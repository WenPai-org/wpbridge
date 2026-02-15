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

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge Server 处理器类
 */
class BridgeServerHandler extends AbstractHandler {

	/**
	 * Bridge 客户端
	 *
	 * @var BridgeClient|null
	 */
	private ?BridgeClient $client = null;

	/**
	 * 构造函数
	 *
	 * @param SourceModel $source 源模型
	 */
	public function __construct( SourceModel $source ) {
		parent::__construct( $source );

		// 从 source 配置初始化客户端
		$server_url = $source->api_url;
		$api_key    = $this->get_auth_token();

		if ( ! empty( $server_url ) ) {
			$this->client = new BridgeClient( $server_url, $api_key );
		}
	}

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		return [
			'auth'     => 'api_key',
			'version'  => 'json',
			'download' => 'signed_url',
			'batch'    => true,
		];
	}

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string {
		return rtrim( $this->source->api_url, '/' ) . '/api/v1/health';
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

		$info = $this->client->get_plugin_info( $slug );

		if ( empty( $info ) || empty( $info['version'] ) ) {
			return null;
		}

		// 比较版本
		if ( ! $this->is_newer_version( $version, $info['version'] ) ) {
			return null;
		}

		// 获取签名下载 URL
		$download_url = $this->client->get_download_url( $slug );

		if ( empty( $download_url ) ) {
			Logger::warning( 'Bridge Server 无法获取下载 URL', [ 'slug' => $slug ] );
			return null;
		}

		return UpdateInfo::from_array( [
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
		] );
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

		$info = $this->client->get_plugin_info( $slug );

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
			return HealthStatus::healthy( $elapsed );
		}

		return HealthStatus::failed( '连接失败' );
	}
}
