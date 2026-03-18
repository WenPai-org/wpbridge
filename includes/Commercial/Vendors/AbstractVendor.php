<?php
/**
 * 供应商抽象基类
 *
 * 提供供应商通用功能实现
 *
 * @package WPBridge
 * @since 0.9.7
 */

declare(strict_types=1);

namespace WPBridge\Commercial\Vendors;

use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AbstractVendor 抽象类
 */
abstract class AbstractVendor implements VendorInterface {

	/**
	 * 供应商配置
	 *
	 * @var array
	 */
	protected array $config = [];

	/**
	 * 缓存前缀
	 *
	 * @var string
	 */
	protected string $cache_prefix = 'wpbridge_vendor_';

	/**
	 * 缓存时间（秒）
	 *
	 * @var int
	 */
	protected int $cache_ttl = 86400;

	/**
	 * 构造函数
	 *
	 * @param array $config 配置
	 */
	public function __construct( array $config = [] ) {
		$this->config = array_merge( $this->get_default_config(), $config );
	}

	/**
	 * 获取默认配置
	 *
	 * @return array
	 */
	protected function get_default_config(): array {
		return [
			'api_url'    => '',
			'api_key'    => '',
			'api_secret' => '',
			'timeout'    => 15,
			'enabled'    => true,
		];
	}

	/**
	 * 检查供应商是否可用
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( empty( $this->config['enabled'] ) ) {
			return false;
		}

		if ( empty( $this->config['api_url'] ) ) {
			return false;
		}

		return $this->verify_credentials();
	}

	/**
	 * 发送 API 请求
	 *
	 * @param string $endpoint 端点
	 * @param array  $params   参数
	 * @param string $method   方法 (GET/POST)
	 * @return array|null
	 */
	/**
	 * 不应出现在 URL query 中的敏感参数名
	 */
	private const SENSITIVE_PARAMS = [ 'api_key', 'api_secret', 'consumer_key', 'consumer_secret', 'license_key', 'token', 'access_token' ];

	protected function api_request( string $endpoint, array $params = [], string $method = 'GET' ): ?array {
		$api_url = $this->config['api_url'] ?? '';

		// H3/H4: 强制 HTTPS
		if ( ! empty( $api_url ) && strpos( $api_url, 'http://' ) === 0 ) {
			$api_url = 'https://' . substr( $api_url, 7 );
		}

		$url = trailingslashit( $api_url ) . ltrim( $endpoint, '/' );

		$args = [
			'timeout'   => $this->config['timeout'],
			'headers'   => $this->get_request_headers(),
			'sslverify' => true,
		];

		if ( $method === 'GET' && ! empty( $params ) ) {
			// H2: 敏感参数不通过 URL 传输，移到 headers
			$url_params = [];
			foreach ( $params as $key => $value ) {
				if ( in_array( $key, self::SENSITIVE_PARAMS, true ) ) {
					$args['headers'][ 'X-' . str_replace( '_', '-', ucwords( $key, '_' ) ) ] = $value;
				} else {
					$url_params[ $key ] = $value;
				}
			}
			if ( ! empty( $url_params ) ) {
				$url = add_query_arg( $url_params, $url );
			}
		} elseif ( $method === 'POST' ) {
			$args['body'] = $params;
		}

		$response = $method === 'GET'
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Vendor API request failed',
				[
					'vendor'   => $this->get_id(),
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				]
			);
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			Logger::warning(
				'Vendor API non-200 response',
				[
					'vendor'   => $this->get_id(),
					'endpoint' => $endpoint,
					'code'     => $code,
				]
			);
			return null;
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			Logger::error(
				'Vendor API invalid JSON',
				[
					'vendor'   => $this->get_id(),
					'endpoint' => $endpoint,
				]
			);
			return null;
		}

		return $data;
	}

	/**
	 * 获取请求头
	 *
	 * @return array
	 */
	protected function get_request_headers(): array {
		return [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		];
	}

	/**
	 * 获取缓存
	 *
	 * @param string $key 缓存键
	 * @return mixed|null
	 */
	protected function get_cache( string $key ) {
		$cache_key = $this->cache_prefix . $this->get_id() . '_' . md5( $key );
		$cached    = get_transient( $cache_key );
		return $cached !== false ? $cached : null;
	}

	/**
	 * 设置缓存
	 *
	 * @param string $key   缓存键
	 * @param mixed  $value 缓存值
	 * @param int    $ttl   过期时间（秒）
	 */
	protected function set_cache( string $key, $value, int $ttl = 0 ): void {
		$cache_key = $this->cache_prefix . $this->get_id() . '_' . md5( $key );
		set_transient( $cache_key, $value, $ttl ?: $this->cache_ttl );
	}

	/**
	 * 清除缓存
	 *
	 * @param string $key 缓存键（可选，为空则清除所有）
	 */
	protected function clear_cache( string $key = '' ): void {
		if ( ! empty( $key ) ) {
			$cache_key = $this->cache_prefix . $this->get_id() . '_' . md5( $key );
			delete_transient( $cache_key );
		}
	}

	/**
	 * 清除该供应商的所有 transient 缓存
	 *
	 * @return int 删除条数
	 */
	public function clear_all_cache(): int {
		global $wpdb;

		$prefix = $this->cache_prefix . $this->get_id() . '_';

		// WordPress transient 在 options 表中以 _transient_ 前缀存储
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( $prefix ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
			)
		);

		return intdiv( $count, 2 );
	}

	/**
	 * 搜索插件（默认实现：从列表中过滤）
	 *
	 * @param string $keyword 关键词
	 * @return array
	 */
	public function search_plugins( string $keyword ): array {
		$all_plugins = $this->get_plugins( 1, 1000 );
		$keyword     = strtolower( $keyword );
		$results     = [];

		foreach ( $all_plugins['plugins'] as $plugin ) {
			$name = strtolower( $plugin['name'] ?? '' );
			$slug = strtolower( $plugin['slug'] ?? '' );

			if ( strpos( $name, $keyword ) !== false || strpos( $slug, $keyword ) !== false ) {
				$results[] = $plugin;
			}
		}

		return $results;
	}

	/**
	 * 获取插件详情（默认实现：从列表中查找）
	 *
	 * @param string $slug 插件 slug
	 * @return array|null
	 */
	public function get_plugin( string $slug ): ?array {
		// 先检查缓存
		$cache_key = 'plugin_' . $slug;
		$cached    = $this->get_cache( $cache_key );
		if ( $cached !== null ) {
			return $cached;
		}

		// 从列表中查找
		$all_plugins = $this->get_plugins( 1, 1000 );
		foreach ( $all_plugins['plugins'] as $plugin ) {
			if ( ( $plugin['slug'] ?? '' ) === $slug ) {
				$this->set_cache( $cache_key, $plugin );
				return $plugin;
			}
		}

		return null;
	}

	/**
	 * 标准化插件数据
	 *
	 * @param array $raw_plugin 原始插件数据
	 * @return array
	 */
	protected function normalize_plugin( array $raw_plugin ): array {
		return [
			'slug'         => $raw_plugin['slug'] ?? '',
			'name'         => $raw_plugin['name'] ?? $raw_plugin['title'] ?? '',
			'version'      => $raw_plugin['version'] ?? '',
			'author'       => $raw_plugin['author'] ?? '',
			'description'  => $raw_plugin['description'] ?? $raw_plugin['excerpt'] ?? '',
			'homepage'     => $raw_plugin['homepage'] ?? $raw_plugin['url'] ?? '',
			'download_url' => $raw_plugin['download_url'] ?? $raw_plugin['download_link'] ?? '',
			'tested'       => $raw_plugin['tested'] ?? '',
			'requires'     => $raw_plugin['requires'] ?? $raw_plugin['requires_at_least'] ?? '',
			'requires_php' => $raw_plugin['requires_php'] ?? '',
			'last_updated' => $raw_plugin['last_updated'] ?? $raw_plugin['modified'] ?? '',
			'vendor'       => $this->get_id(),
		];
	}
}
