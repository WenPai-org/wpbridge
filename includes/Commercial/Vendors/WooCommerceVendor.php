<?php
/**
 * WooCommerce API Manager 供应商
 *
 * 支持使用 WooCommerce API Manager 的 GPL 插件商店
 * 大部分 GPL 分发商店都使用此方案
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
 * WooCommerceVendor 类
 *
 * 支持的 API 端点：
 * - WooCommerce API Manager v2
 * - WooCommerce Software Add-on
 * - 类似的 WC 扩展
 */
class WooCommerceVendor extends AbstractVendor {

	/**
	 * 供应商 ID
	 *
	 * @var string
	 */
	protected string $vendor_id;

	/**
	 * 供应商名称
	 *
	 * @var string
	 */
	protected string $vendor_name;

	/**
	 * 构造函数
	 *
	 * @param string $vendor_id   供应商 ID
	 * @param string $vendor_name 供应商名称
	 * @param array  $config      配置
	 */
	public function __construct( string $vendor_id, string $vendor_name, array $config = [] ) {
		$this->vendor_id   = $vendor_id;
		$this->vendor_name = $vendor_name;
		parent::__construct( $config );
	}

	/**
	 * 获取默认配置
	 *
	 * @return array
	 */
	protected function get_default_config(): array {
		return array_merge( parent::get_default_config(), [
			'auth_mode'      => 'consumer_key', // consumer_key | wc_am
			'api_version'    => 'v2',
			'product_id'     => '',
			'instance'       => '',
			'email'          => '',
			'license_key'    => '',
			'use_rest_api'   => true,
			'products_endpoint' => '/wp-json/wc/v3/products',
			'download_endpoint' => '/wp-json/wc-am/v2/download',
		] );
	}

	/**
	 * 获取供应商 ID
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->vendor_id;
	}

	/**
	 * 获取供应商信息
	 *
	 * @return array
	 */
	public function get_info(): array {
		return [
			'id'           => $this->vendor_id,
			'name'         => $this->vendor_name,
			'url'          => $this->config['api_url'],
			'api_type'     => 'wc_am',
			'api_version'  => $this->config['api_version'],
			'auth_mode'    => $this->config['auth_mode'],
			'requires_key' => true,
		];
	}

	/**
	 * 获取请求头
	 *
	 * @return array
	 */
	protected function get_request_headers(): array {
		$headers = parent::get_request_headers();

		// WC AM 模式不发 Authorization header（用 query params 认证）
		if ( $this->config['auth_mode'] === 'wc_am' ) {
			return $headers;
		}

		// Consumer Key 模式：WooCommerce REST API Basic Auth
		if ( ! empty( $this->config['api_key'] ) && ! empty( $this->config['api_secret'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode(
				$this->config['api_key'] . ':' . $this->config['api_secret']
			);
		}

		return $headers;
	}

	/**
	 * 验证供应商授权
	 *
	 * @return bool
	 */
	public function verify_credentials(): bool {
		$cache_key = 'credentials_valid';
		$cached    = $this->get_cache( $cache_key );

		if ( $cached !== null ) {
			return (bool) $cached;
		}

		if ( $this->config['auth_mode'] === 'wc_am' ) {
			$valid = $this->wc_am_status() !== null;
		} else {
			$response = $this->api_request( $this->config['products_endpoint'], [
				'per_page' => 1,
				'status'   => 'publish',
			] );
			$valid = $response !== null;
		}

		$this->set_cache( $cache_key, $valid, 300 );

		return $valid;
	}

	/**
	 * 获取可用插件列表
	 *
	 * @param int $page  页码
	 * @param int $limit 每页数量
	 * @return array
	 */
	public function get_plugins( int $page = 1, int $limit = 100 ): array {
		$cache_key = "plugins_page_{$page}_limit_{$limit}";
		$cached    = $this->get_cache( $cache_key );

		if ( $cached !== null ) {
			return $cached;
		}

		$response = $this->api_request( $this->config['products_endpoint'], [
			'page'     => $page,
			'per_page' => $limit,
			'status'   => 'publish',
			'type'     => 'simple', // 或 'variable' 取决于商店配置
			'category' => $this->config['category'] ?? '', // 可选：按分类过滤
		] );

		if ( $response === null ) {
			return [
				'plugins' => [],
				'total'   => 0,
				'pages'   => 0,
			];
		}

		$plugins = [];
		foreach ( $response as $product ) {
			$plugin = $this->normalize_wc_product( $product );
			if ( $plugin !== null ) {
				$plugins[] = $plugin;
			}
		}

		$result = [
			'plugins' => $plugins,
			'total'   => count( $plugins ), // WC API 返回 X-WP-Total header
			'pages'   => 1, // WC API 返回 X-WP-TotalPages header
		];

		$this->set_cache( $cache_key, $result );

		return $result;
	}

	/**
	 * 标准化 WooCommerce 产品数据
	 *
	 * @param array $product WC 产品数据
	 * @return array|null
	 */
	protected function normalize_wc_product( array $product ): ?array {
		// 跳过非插件产品
		if ( ! $this->is_plugin_product( $product ) ) {
			return null;
		}

		// 从产品数据中提取插件信息
		$slug = $this->extract_plugin_slug( $product );
		if ( empty( $slug ) ) {
			return null;
		}

		return [
			'slug'         => $slug,
			'name'         => $product['name'] ?? '',
			'version'      => $this->extract_version( $product ),
			'author'       => $this->extract_author( $product ),
			'description'  => wp_strip_all_tags( $product['short_description'] ?? $product['description'] ?? '' ),
			'homepage'     => $product['permalink'] ?? '',
			'download_url' => '', // 需要单独获取
			'tested'       => $this->extract_meta( $product, '_tested_wp_version' ),
			'requires'     => $this->extract_meta( $product, '_requires_wp_version' ),
			'requires_php' => $this->extract_meta( $product, '_requires_php_version' ),
			'last_updated' => $product['date_modified'] ?? '',
			'price'        => $product['price'] ?? '0',
			'product_id'   => $product['id'] ?? 0,
			'vendor'       => $this->get_id(),
		];
	}

	/**
	 * 检查产品是否是插件
	 *
	 * @param array $product 产品数据
	 * @return bool
	 */
	protected function is_plugin_product( array $product ): bool {
		// 检查分类
		$categories = $product['categories'] ?? [];
		foreach ( $categories as $cat ) {
			$cat_slug = strtolower( $cat['slug'] ?? '' );
			if ( in_array( $cat_slug, [ 'plugins', 'wordpress-plugins', 'wp-plugins' ], true ) ) {
				return true;
			}
		}

		// 检查标签
		$tags = $product['tags'] ?? [];
		foreach ( $tags as $tag ) {
			$tag_slug = strtolower( $tag['slug'] ?? '' );
			if ( strpos( $tag_slug, 'plugin' ) !== false ) {
				return true;
			}
		}

		// 检查是否有下载文件
		$downloads = $product['downloads'] ?? [];
		foreach ( $downloads as $download ) {
			$file = strtolower( $download['file'] ?? '' );
			if ( strpos( $file, '.zip' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 从产品中提取插件 slug
	 *
	 * @param array $product 产品数据
	 * @return string
	 */
	protected function extract_plugin_slug( array $product ): string {
		// 1. 从 meta 中获取
		$slug = $this->extract_meta( $product, '_plugin_slug' );
		if ( ! empty( $slug ) ) {
			return $slug;
		}

		// 2. 从 SKU 获取
		$sku = $product['sku'] ?? '';
		if ( ! empty( $sku ) ) {
			return sanitize_title( $sku );
		}

		// 3. 从产品 slug 获取
		$product_slug = $product['slug'] ?? '';
		if ( ! empty( $product_slug ) ) {
			// 移除常见后缀
			$product_slug = preg_replace( '/-(pro|premium|plus|addon)$/', '', $product_slug );
			return $product_slug;
		}

		// 4. 从名称生成
		return sanitize_title( $product['name'] ?? '' );
	}

	/**
	 * 从产品中提取版本号
	 *
	 * @param array $product 产品数据
	 * @return string
	 */
	protected function extract_version( array $product ): string {
		// 从 meta 获取
		$version = $this->extract_meta( $product, '_version' );
		if ( ! empty( $version ) ) {
			return $version;
		}

		$version = $this->extract_meta( $product, '_plugin_version' );
		if ( ! empty( $version ) ) {
			return $version;
		}

		// 从下载文件名提取
		$downloads = $product['downloads'] ?? [];
		foreach ( $downloads as $download ) {
			$file = $download['file'] ?? '';
			if ( preg_match( '/[\-_]v?(\d+\.\d+(?:\.\d+)?)/i', $file, $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * 从产品中提取作者
	 *
	 * @param array $product 产品数据
	 * @return string
	 */
	protected function extract_author( array $product ): string {
		$author = $this->extract_meta( $product, '_plugin_author' );
		if ( ! empty( $author ) ) {
			return $author;
		}

		// 使用商店名称作为默认作者
		return $this->vendor_name;
	}

	/**
	 * 从产品 meta 中提取值
	 *
	 * @param array  $product  产品数据
	 * @param string $meta_key Meta 键
	 * @return string
	 */
	protected function extract_meta( array $product, string $meta_key ): string {
		$meta_data = $product['meta_data'] ?? [];
		foreach ( $meta_data as $meta ) {
			if ( ( $meta['key'] ?? '' ) === $meta_key ) {
				return (string) ( $meta['value'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * 检查插件更新
	 *
	 * @param string $slug            插件 slug
	 * @param string $current_version 当前版本
	 * @return array|null
	 */
	public function check_update( string $slug, string $current_version ): ?array {
		$plugin = $this->get_plugin( $slug );

		if ( $plugin === null ) {
			return null;
		}

		$latest_version = $plugin['version'] ?? '';

		if ( empty( $latest_version ) ) {
			return null;
		}

		if ( version_compare( $latest_version, $current_version, '<=' ) ) {
			return null; // 无更新
		}

		return [
			'version'      => $latest_version,
			'download_url' => $this->get_download_url( $slug, $latest_version ),
			'changelog'    => $this->get_changelog( $slug ),
			'tested'       => $plugin['tested'] ?? '',
			'requires'     => $plugin['requires'] ?? '',
			'requires_php' => $plugin['requires_php'] ?? '',
		];
	}

	/**
	 * 获取下载链接
	 *
	 * @param string $slug    插件 slug
	 * @param string $version 版本号
	 * @return string|null
	 */
	public function get_download_url( string $slug, string $version = '' ): ?string {
		$plugin = $this->get_plugin( $slug );

		if ( $plugin === null ) {
			return null;
		}

		$product_id = $plugin['product_id'] ?? 0;

		if ( empty( $product_id ) ) {
			return null;
		}

		$instance = $this->config['instance'] ?: $this->generate_instance_id();

		// WC AM 模式：用 wc_am_action=download + api_key + email
		if ( $this->config['auth_mode'] === 'wc_am' ) {
			$params = [
				'wc-api'        => 'wc-am-api',
				'wc_am_action'  => 'download',
				'product_id'    => $product_id,
				'api_key'       => $this->config['license_key'],
				'email'         => $this->config['email'],
				'instance'      => $instance,
			];

			if ( ! empty( $version ) ) {
				$params['version'] = $version;
			}

			return add_query_arg(
				$params,
				trailingslashit( $this->config['api_url'] )
			);
		}

		// Consumer Key 模式
		$params = [
			'product_id' => $product_id,
			'api_key'    => $this->config['api_key'],
			'instance'   => $instance,
		];

		if ( ! empty( $version ) ) {
			$params['version'] = $version;
		}

		return add_query_arg(
			$params,
			trailingslashit( $this->config['api_url'] ) . ltrim( $this->config['download_endpoint'], '/' )
		);
	}

	/**
	 * 获取更新日志
	 *
	 * @param string $slug 插件 slug
	 * @return string
	 */
	protected function get_changelog( string $slug ): string {
		$plugin = $this->get_plugin( $slug );

		if ( $plugin === null ) {
			return '';
		}

		// 尝试从产品描述中提取 changelog
		$description = $plugin['description'] ?? '';

		if ( preg_match( '/changelog[:\s]*(.+?)(?=<h|$)/is', $description, $matches ) ) {
			return wp_strip_all_tags( $matches[1] );
		}

		return '';
	}

	/**
	 * WC AM: 激活授权
	 *
	 * @return array|null
	 */
	public function wc_am_activate(): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		$url = add_query_arg( [
			'wc-api'       => 'wc-am-api',
			'wc_am_action' => 'activate',
			'api_key'      => $this->config['license_key'],
			'email'        => $this->config['email'],
			'instance'     => $this->config['instance'] ?: $this->generate_instance_id(),
		], trailingslashit( $this->config['api_url'] ) );

		$response = wp_remote_get( $url, [
			'timeout' => $this->config['timeout'],
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'WC AM activate failed', [
				'vendor' => $this->get_id(),
				'error'  => $response->get_error_message(),
			] );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true ) ?: null;
	}

	/**
	 * WC AM: 查询授权状态
	 *
	 * @return array|null
	 */
	public function wc_am_status(): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		$url = add_query_arg( [
			'wc-api'       => 'wc-am-api',
			'wc_am_action' => 'status',
			'api_key'      => $this->config['license_key'],
			'email'        => $this->config['email'],
			'instance'     => $this->config['instance'] ?: $this->generate_instance_id(),
		], trailingslashit( $this->config['api_url'] ) );

		$response = wp_remote_get( $url, [
			'timeout' => $this->config['timeout'],
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'WC AM status check failed', [
				'vendor' => $this->get_id(),
				'error'  => $response->get_error_message(),
			] );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true ) ?: null;
	}

	/**
	 * 生成实例 ID
	 *
	 * @return string
	 */
	protected function generate_instance_id(): string {
		return md5( home_url() . AUTH_KEY );
	}
}
