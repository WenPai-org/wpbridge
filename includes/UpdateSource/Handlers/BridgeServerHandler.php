<?php
/**
 * Bridge Server 处理器
 *
 * 通过 wpbridge-server Go 服务获取商业插件更新
 *
 * @package WPBridge
 * @since 0.9.8
 */

declare(strict_types=1);

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\UpdateSource\SourceModel;
use WPBridge\Core\Logger;
use WPBridge\Commercial\BridgeClient;
use WPBridge\Commercial\UpdateAuthorizationClient;
use WPBridge\Security\PackageIntegrityVerifier;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge Server 处理器类
 */
class BridgeServerHandler extends AbstractHandler implements ProtectedPackageHandlerInterface {

	/**
	 * Bridge 客户端
	 *
	 * @var BridgeClient|null
	 */
	private ?BridgeClient $client = null;

	/** @var UpdateAuthorizationClient|null */
	private ?UpdateAuthorizationClient $update_authorization = null;

	/** @var string */
	private string $paired_slug = '';
	/** @var callable|null */
	private $grant_issuer = null;

	/**
	 * 构造函数
	 *
	 * @param SourceModel $source 源模型
	 */
	public function __construct( SourceModel $source, ?BridgeClient $client = null, ?callable $grant_issuer = null ) {
		parent::__construct( $source );

		// 从 source 配置初始化客户端
		$server_url = $source->api_url;
		$api_key    = $this->get_auth_token();

		if ( null !== $client ) {
			$this->client = $client;
		} elseif ( ! empty( $server_url ) ) {
			$this->client = new BridgeClient( $server_url, $api_key );
		}
		$this->grant_issuer = $grant_issuer;
		$paired_bridge = rtrim( (string) ( $source->metadata['update_bridge_url'] ?? '' ), '/' );
		if ( '' !== $paired_bridge && hash_equals( $paired_bridge, rtrim( $server_url, '/' ) ) && ! empty( $source->metadata['update_device_id'] ) && ! empty( $source->metadata['update_private_key'] ) ) {
			$this->update_authorization = new UpdateAuthorizationClient( (array) $source->metadata );
			$this->paired_slug          = sanitize_title( (string) ( $source->metadata['update_product_slug'] ?? '' ) );
		}
	}

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		return $this->client ? $this->client->get_capabilities() : [];
	}

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string {
		$capabilities = $this->get_capabilities();
		return rtrim( $this->source->api_url, '/' ) . ( $capabilities['endpoints']['health'] ?? '/health' );
	}

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo {
		if ( ! $this->client || ! $this->client->is_configured() ) {
			Logger::warning( 'Bridge Server 未配置', [ 'slug' => $slug ] );
			return null;
		}

		$grant = $this->metadata_grant( $slug );
		if ( is_wp_error( $grant ) ) {
			Logger::warning( '无法签发插件元数据授权', [ 'slug' => $slug, 'error' => $grant->get_error_code() ] );
			return null;
		}
		$info = $this->client->get_plugin_info( $slug, $grant );

		if ( empty( $info ) || empty( $info['version'] ) ) {
			return null;
		}

		// 比较版本
		if ( ! $this->is_newer_version( $version, $info['version'] ) ) {
			return null;
		}

		// 获取签名下载 URL
		$download_url = $info['download_url'] ?? $info['download_link'] ?? $info['package'] ?? $this->client->get_download_url( $slug );

		if ( empty( $download_url ) ) {
			Logger::warning( 'Bridge Server 无法获取下载 URL', [ 'slug' => $slug ] );
			return null;
		}

		return UpdateInfo::from_array(
			array_merge(
				[
				'slug'         => $slug,
				'version'      => $info['version'],
				'download_url' => $download_url,
				'details_url'  => $info['homepage'] ?? '',
				'requires'     => $info['requires'] ?? '',
				'tested'       => $info['tested'] ?? '',
				'requires_php' => $info['requires_php'] ?? '',
				'last_updated' => $info['updated_at'] ?? '',
				'icons'        => $info['icons'] ?? [],
				'banners'      => $info['banners'] ?? [],
				'changelog'    => $info['changelog'] ?? '',
				'description'  => $info['description'] ?? '',
				'sha256'       => $info['sha256'] ?? $info['checksum_sha256'] ?? '',
				'signature_scheme'   => $info['signature_scheme'] ?? '',
				'signature_kid'      => $info['signature_kid'] ?? '',
				'signature'          => $info['signature'] ?? '',
				'signature_required' => ! empty( $info['signature_required'] ) || ! empty( $this->source->metadata['signature_required'] ),
				'artifact_size'      => $info['artifact_size'] ?? 0,
				'artifact_file'      => $info['artifact_file'] ?? '',
				'artifact_signed_at' => $info['artifact_signed_at'] ?? '',
				],
				$this->artifact_trust_policy()
			)
		);
	}

	/**
	 * 获取项目信息
	 *
	 * @param string $slug 插件 slug
	 * @return array|null
	 */
	public function get_info( string $slug ): ?array {
		if ( ! $this->client || ! $this->client->is_configured() ) {
			return null;
		}

		$grant = $this->metadata_grant( $slug );
		if ( is_wp_error( $grant ) ) {
			return null;
		}
		$info = $this->client->get_plugin_info( $slug, $grant );

		if ( empty( $info ) ) {
			return null;
		}

		// 添加下载 URL
		$info['download_url'] = $this->client->get_download_url( $slug );

		return $info;
	}

	/**
	 * 验证认证信息
	 *
	 * @return bool
	 */
	public function validate_auth(): bool {
		if ( ! $this->client ) {
			return false;
		}

		return $this->client->health_check();
	}

	/**
	 * 测试连通性
	 *
	 * @return HealthStatus
	 */
	public function test_connection(): HealthStatus {
		if ( ! $this->client ) {
			return HealthStatus::failed( 'Bridge Server 未配置' );
		}

		$start = microtime( true );

		if ( $this->client->health_check() ) {
			$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );
			$status          = HealthStatus::healthy( $elapsed );
			$status->details = $this->client->get_diagnostics();
			return $status;
		}

		return HealthStatus::failed( '连接失败' );
	}

	/** Whether this source owns a protected bridge package URL. */
	public function can_handle_download( string $package, string $slug ): bool {
		$expected = $this->client ? $this->client->get_download_url( $slug ) : null;
		return $slug === $this->paired_slug && is_string( $expected ) && '' !== $expected && hash_equals( $expected, $package ) && null !== $this->update_authorization;
	}

	/** @return string|\WP_Error Temporary file path on success. */
	public function download_package( string $slug, array $integrity ) {
		if ( ! $this->client || ( ! $this->update_authorization && null === $this->grant_issuer ) ) {
			return new \WP_Error( 'wpbridge_update_not_paired', __( '此更新源尚未与文派账户配对。', 'wpbridge' ) );
		}
		$grant = null !== $this->grant_issuer ? call_user_func( $this->grant_issuer, $slug, 'package' ) : $this->update_authorization->issue_grant( $slug, 'package' );
		if ( is_wp_error( $grant ) ) {
			return $grant;
		}
		return $this->client->download_package( $slug, $grant, $integrity );
	}

	/** Build the exact record consumed by the protected package verifier. */
	public function protected_integrity( string $slug, array $info ): array {
		$policy = $this->artifact_trust_policy();
		return self::protected_integrity_record( $slug, $info, (array) $policy['_wpbridge_artifact_keys'] );
	}

	/** Exact controller-to-BridgeClient integrity contract. */
	public static function protected_integrity_record( string $slug, array $info, array $artifact_public_keys ): array {
		return [
			'sha256' => $info['sha256'] ?? $info['checksum_sha256'] ?? '',
			'slug' => $slug,
			'version' => (string) ( $info['version'] ?? '' ),
			'signature_scheme' => $info['signature_scheme'] ?? '',
			'signature_kid' => $info['signature_kid'] ?? '',
			'signature' => $info['signature'] ?? '',
			'signature_required' => true,
			'artifact_size' => $info['artifact_size'] ?? 0,
			'artifact_file' => $info['artifact_file'] ?? '',
			'artifact_signed_at' => $info['artifact_signed_at'] ?? '',
			'artifact_public_keys' => $artifact_public_keys,
		];
	}

	/** Locally configured artifact trust policy. Bridge metadata cannot add keys. */
	private function artifact_trust_policy(): array {
		$keyring = $this->source->metadata['artifact_public_keys'] ?? [];
		if ( defined( 'WPBRIDGE_ARTIFACT_PUBLIC_KEYS' ) && is_array( WPBRIDGE_ARTIFACT_PUBLIC_KEYS ) ) {
			$keyring = PackageIntegrityVerifier::restrict_to_deployment_keyring(
				WPBRIDGE_ARTIFACT_PUBLIC_KEYS,
				is_array( $keyring ) ? $keyring : []
			);
		}
		return [
			'_wpbridge_artifact_keys' => is_array( $keyring ) ? $keyring : [],
		];
	}

	/** @return string|\WP_Error */
	private function metadata_grant( string $slug ) {
		if ( null !== $this->grant_issuer ) {
			return call_user_func( $this->grant_issuer, $slug, 'metadata' );
		}
		if ( null === $this->update_authorization || $slug !== $this->paired_slug ) {
			return '';
		}
		return $this->update_authorization->issue_grant( $slug, 'metadata' );
	}
}
