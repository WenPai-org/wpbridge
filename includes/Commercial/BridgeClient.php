<?php
/**
 * Bridge Server 客户端
 *
 * 与 wpbridge-server Go 服务端通信
 *
 * @package WPBridge
 * @since 0.9.8
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Logger;
use WPBridge\Security\SafeHttpClient;
use WPBridge\Security\Validator;
use WPBridge\Security\PackageIntegrityVerifier;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BridgeClient 类
 */
class BridgeClient {

	/**
	 * 服务端 URL
	 *
	 * @var string
	 */
	private string $server_url;

	/**
	 * API Key
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * 请求超时（秒）
	 *
	 * @var int
	 */
	private int $timeout;

	/** @var callable */
	private $package_transport;

	/** @var array|null */
	private ?array $capabilities = null;

	/**
	 * 构造函数
	 *
	 * @param string $server_url 服务端 URL
	 * @param string $api_key    API Key
	 * @param int    $timeout    请求超时（秒）
	 */
	public function __construct( string $server_url, string $api_key, int $timeout = 30, ?callable $package_transport = null ) {
		$server_url = rtrim( $server_url, '/' );

		// H3/H4: 强制 HTTPS
		if ( strpos( $server_url, 'http://' ) === 0 ) {
			$server_url = 'https://' . substr( $server_url, 7 );
		}

		// M8: SSRF 防护 — 禁止内网地址
		if ( ! Validator::is_valid_url( $server_url ) ) {
			Logger::error(
				'BridgeClient: 无效的 server_url（可能为内网地址）',
				[
					'url' => $server_url,
				]
			);
			$server_url = '';
		}

		$this->server_url = $server_url;
		$this->api_key    = $api_key;
		$this->timeout    = $timeout;
		$this->package_transport = $package_transport ?? [ SafeHttpClient::class, 'request' ];
	}

	/**
	 * 获取插件信息
	 *
	 * @param string $slug 插件 slug
	 * @return array|null
	 */
	public function get_plugin_info( string $slug, string $grant = '' ): ?array {
		$endpoint = $this->endpoint( 'plugin_info', '/api/v1/plugin/{slug}', $slug );
		$response = $this->request( 'GET', $endpoint, [], true, true, $grant );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Failed to get plugin info',
				[
					'slug'  => $slug,
					'error' => $response->get_error_message(),
				]
			);
			return null;
		}

		return $response;
	}

	/**
	 * 获取下载 URL
	 *
	 * @param string $slug 插件 slug
	 * @return string|null
	 */
	public function get_download_url( string $slug ): ?string {
		if ( empty( $this->server_url ) ) {
			return null;
		}
		// 下载端点会返回重定向或直接代理
		return $this->server_url . $this->endpoint( 'download', '/api/v1/download/{slug}', $slug );
	}

	/**
	 * Download a package with a short-lived grant and verify its advertised digest.
	 *
	 * @return string|\WP_Error Temporary file path on success.
	 */
	public function download_package( string $slug, string $grant, array $integrity ) {
		$sha256 = strtolower( trim( (string) ( $integrity['sha256'] ?? '' ) ) );
		if ( '' === $grant || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || empty( $this->server_url ) ) {
			return new \WP_Error( 'wpbridge_protected_package_unverified', __( '受保护更新缺少可验证的包摘要。', 'wpbridge' ) );
		}
		$file = wp_tempnam( $slug . '.zip' );
		if ( ! is_string( $file ) || '' === $file ) {
			return new \WP_Error( 'wpbridge_package_temp_failed', __( '无法创建更新包临时文件。', 'wpbridge' ) );
		}
		$endpoint = $this->endpoint( 'download', '/api/v1/download/{slug}', $slug );
		$response = call_user_func(
			$this->package_transport,
			$this->server_url . $endpoint,
			[
				'method'      => 'GET',
				'timeout'     => 300,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $file,
				'headers'     => [ 'Accept' => 'application/zip', 'Authorization' => 'Bearer ' . $grant ],
			]
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $file );
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			wp_delete_file( $file );
			return new \WP_Error( 'wpbridge_package_download_failed', __( '受保护更新包下载失败。', 'wpbridge' ), [ 'status' => $status ] );
		}
		$verified = PackageIntegrityVerifier::verify_downloaded_file( $file, $integrity );
		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $file );
			return $verified;
		}
		return $file;
	}

	/**
	 * Discover and normalize the server contract. Legacy servers keep their old routes.
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		if ( null !== $this->capabilities ) {
			return $this->capabilities;
		}

		$fallback = [
			'service'     => 'wpbridge-server',
			'api_version' => 'v1',
			'legacy'      => true,
			'auth'        => [ 'required' => true, 'header' => 'X-API-Key' ],
			'endpoints'   => [
				'health'      => '/health',
				'plugin_info' => '/api/v1/plugin/{slug}',
				'download'    => '/api/v1/download/{slug}',
			],
			'features'    => [ 'batch_update' => false, 'signed_download' => false, 'sha256' => false ],
		];

		$response = $this->request( 'GET', '/api/v1/capabilities', [], true, false );
		if ( is_wp_error( $response ) || empty( $response['endpoints'] ) || ! is_array( $response['endpoints'] ) ) {
			$this->capabilities = $fallback;
			return $this->capabilities;
		}

		$this->capabilities = array_replace_recursive( $fallback, $response );
		$this->capabilities['legacy'] = false;
		return $this->capabilities;
	}

	/** @return array */
	public function get_diagnostics(): array {
		$capabilities = $this->get_capabilities();
		return [
			'service'     => sanitize_text_field( (string) ( $capabilities['service'] ?? '' ) ),
			'api_version' => sanitize_text_field( (string) ( $capabilities['api_version'] ?? '' ) ),
			'legacy'      => ! empty( $capabilities['legacy'] ),
			'auth_header' => sanitize_text_field( (string) ( $capabilities['auth']['header'] ?? 'X-API-Key' ) ),
			'features'    => array_map( 'boolval', (array) ( $capabilities['features'] ?? [] ) ),
		];
	}

	/**
	 * 列出所有供应商（需要认证）
	 *
	 * @return array
	 */
	public function list_vendors(): array {
		$response = $this->request( 'GET', '/api/v1/admin/vendors', [], true );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Failed to list vendors',
				[
					'error' => $response->get_error_message(),
				]
			);
			return [];
		}

		return $response;
	}

	/**
	 * 创建供应商（需要认证）
	 *
	 * @param array $data 供应商数据
	 * @return array
	 */
	public function create_vendor( array $data ): array {
		$response = $this->request( 'POST', '/api/v1/admin/vendors', $data, true );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
			'data'    => $response,
		];
	}

	/**
	 * 更新供应商（需要认证）
	 *
	 * @param int   $id   供应商 ID
	 * @param array $data 供应商数据
	 * @return array
	 */
	public function update_vendor( int $id, array $data ): array {
		$response = $this->request( 'PUT', "/api/v1/admin/vendors/{$id}", $data, true );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
			'data'    => $response,
		];
	}

	/**
	 * 删除供应商（需要认证）
	 *
	 * @param int $id 供应商 ID
	 * @return array
	 */
	public function delete_vendor( int $id ): array {
		$response = $this->request( 'DELETE', "/api/v1/admin/vendors/{$id}", [], true );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
		];
	}

	/**
	 * 列出所有插件（需要认证）
	 *
	 * @return array
	 */
	public function list_plugins(): array {
		$response = $this->request( 'GET', '/api/v1/admin/plugins', [], true );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Failed to list plugins',
				[
					'error' => $response->get_error_message(),
				]
			);
			return [];
		}

		return $response;
	}

	/**
	 * 创建插件（需要认证）
	 *
	 * @param array $data 插件数据
	 * @return array
	 */
	public function create_plugin( array $data ): array {
		$response = $this->request( 'POST', '/api/v1/admin/plugins', $data, true );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
			'data'    => $response,
		];
	}

	/**
	 * 获取插件详情（需要认证）
	 *
	 * @param string $slug 插件 slug
	 * @return array|null
	 */
	public function get_plugin( string $slug ): ?array {
		$response = $this->request( 'GET', "/api/v1/admin/plugins/{$slug}", [], true );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return $response;
	}

	/**
	 * 更新插件（需要认证）
	 *
	 * @param string $slug 插件 slug
	 * @param array  $data 插件数据
	 * @return array
	 */
	public function update_plugin( string $slug, array $data ): array {
		$response = $this->request( 'PUT', "/api/v1/admin/plugins/{$slug}", $data, true );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
			'data'    => $response,
		];
	}

	/**
	 * 删除插件（需要认证）
	 *
	 * @param string $slug 插件 slug
	 * @return array
	 */
	public function delete_plugin( string $slug ): array {
		$response = $this->request( 'DELETE', "/api/v1/admin/plugins/{$slug}", [], true );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
		];
	}

	/**
	 * 健康检查
	 *
	 * @return bool
	 */
	public function health_check(): bool {
		$response = $this->request( 'GET', $this->endpoint( 'health', '/health' ), [], true );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return isset( $response['status'] ) && $response['status'] === 'ok';
	}

	/**
	 * 发送请求
	 *
	 * @param string $method   HTTP 方法
	 * @param string $endpoint API 端点
	 * @param array  $data     请求数据
	 * @param bool   $auth     是否需要认证
	 * @return array|\WP_Error
	 */
	private function request( string $method, string $endpoint, array $data = [], bool $auth = false, bool $retry = true, string $bearer = '' ) {
		$url = $this->server_url . $endpoint;

		$args = [
			'method'    => $method,
			'timeout'   => $this->timeout,
			'sslverify' => true,
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		];

		// 添加认证头
		if ( $auth && ! empty( $this->api_key ) ) {
			$args['headers']['X-API-Key'] = $this->api_key;
		}
		if ( '' !== $bearer ) {
			$args['headers']['Authorization'] = 'Bearer ' . $bearer;
		}

		// 添加请求体
		if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = SafeHttpClient::request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// 处理 204 No Content
		if ( $status_code === 204 ) {
			return [];
		}

		// 处理错误状态码
		if ( $status_code >= 400 ) {
			$error_data = json_decode( $body, true );
			$error       = is_array( $error_data ) ? ( $error_data['error'] ?? $error_data ) : [];
			$top_message = is_array( $error_data ) ? ( $error_data['message'] ?? '' ) : '';
			$message     = is_array( $error ) ? ( $error['message'] ?? $top_message ) : (string) $error;
			$code        = is_array( $error ) ? ( $error['code'] ?? 'bridge_server_error' ) : 'bridge_server_error';
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

			if ( $retry && 'GET' === $method && ( 429 === $status_code || $status_code >= 500 ) ) {
				$delay_ms = min( 1000, max( 100, $retry_after * 1000 ) );
				usleep( $delay_ms * 1000 );
				return $this->request( $method, $endpoint, $data, $auth, false, $bearer );
			}

			return new \WP_Error(
				sanitize_key( (string) $code ) ?: 'bridge_server_error',
				$message ?: "HTTP {$status_code}",
				[
					'status'      => $status_code,
					'retry_after' => $retry_after,
					'retryable'   => 429 === $status_code || $status_code >= 500,
					'request_id'  => is_array( $error ) ? sanitize_text_field( (string) ( $error['request_id'] ?? '' ) ) : '',
				]
			);
		}

		// 解析 JSON 响应
		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			return new \WP_Error( 'json_decode_error', 'Invalid JSON response' );
		}

		return $decoded;
	}

	/**
	 * 获取服务端 URL
	 *
	 * @return string
	 */
	public function get_server_url(): string {
		return $this->server_url;
	}

	/**
	 * 检查是否已配置
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->server_url );
	}

	/** Resolve a capability endpoint without allowing an absolute URL. */
	private function endpoint( string $name, string $fallback, string $slug = '' ): string {
		$capabilities = $this->get_capabilities();
		$endpoint     = (string) ( $capabilities['endpoints'][ $name ] ?? $fallback );
		if ( '' === $endpoint || '/' !== $endpoint[0] || 0 === strpos( $endpoint, '//' ) ) {
			$endpoint = $fallback;
		}
		return str_replace( '{slug}', rawurlencode( sanitize_title( $slug ) ), $endpoint );
	}
}
