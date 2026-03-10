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
			$response = $this->wc_am_verify_key();
			$valid    = ! empty( $response['success'] );
		} else {
			$response = $this->api_request( $this->config['products_endpoint'], [
				'per_page' => 1,
				'status'   => 'publish',
			] );
			$valid = $response !== null;
		}

		// 成功缓存 12 小时，失败只缓存 5 分钟（避免临时网络问题长期阻塞）
		$this->set_cache( $cache_key, $valid, $valid ? 43200 : 300 );

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

		// WC AM 模式：通过 product_list + information 获取
		if ( $this->config['auth_mode'] === 'wc_am' ) {
			$result = $this->get_plugins_via_wc_am();
			// 空结果短缓存 5 分钟，正常结果用默认 TTL
			$this->set_cache( $cache_key, $result, empty( $result['plugins'] ) ? 300 : 0 );
			return $result;
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

		// 空结果短缓存 5 分钟
		$this->set_cache( $cache_key, $result, empty( $plugins ) ? 300 : 0 );

		return $result;
	}

	/**
	 * 通过 WC AM API 获取插件列表
	 *
	 * @return array
	 */
	protected function get_plugins_via_wc_am(): array {
		$list_response = $this->wc_am_product_list();

		if ( empty( $list_response['success'] ) || empty( $list_response['data']['product_list'] ) ) {
			return [
				'plugins' => [],
				'total'   => 0,
				'pages'   => 0,
			];
		}

		$product_list = $list_response['data']['product_list'];

		// Kestrel 返回 non_wc_subs_resources 嵌套结构
		if ( isset( $product_list['non_wc_subs_resources'] ) ) {
			$products = $product_list['non_wc_subs_resources'];
		} elseif ( isset( $product_list[0] ) ) {
			// 标准索引数组格式
			$products = $product_list;
		} else {
			$products = [];
		}
		$plugins  = [];

		foreach ( $products as $product ) {
			$product_id    = (int) ( $product['product_id'] ?? 0 );
			$product_title = $product['product_title'] ?? '';

			if ( $product_id <= 0 ) {
				continue;
			}

			// 从产品标题提取 slug（标题格式: "English Name | 中文描述"）
			$slug = $this->extract_slug_from_title( $product_title );

			// 纯中文标题无法提取英文 slug，用 product_id 占位
			if ( empty( $slug ) ) {
				$slug = 'product-' . $product_id;
			}

			// 尝试获取详情（带 transient 缓存）
			$info_cache_key = 'wc_am_info_' . $product_id;
			$info           = $this->get_cache( $info_cache_key );

			if ( $info === null ) {
				$info_response = $this->wc_am_information( $product_id, $slug );
				if ( ! empty( $info_response['success'] ) && ! empty( $info_response['data']['info'] ) ) {
					$info = $info_response['data']['info'];
					$this->set_cache( $info_cache_key, $info, 86400 );
				}
			}

			$plugin_data = [
				'slug'         => $info['slug'] ?? $slug,
				'name'         => $product_title,
				'version'      => $info['version'] ?? '',
				'author'       => $info['author'] ?? $this->vendor_name,
				'description'  => '',
				'homepage'     => $info['homepage'] ?? '',
				'download_url' => '',
				'tested'       => $info['tested'] ?? '',
				'requires'     => $info['requires'] ?? '',
				'requires_php' => $info['requires_php'] ?? '',
				'last_updated' => $info['last_updated'] ?? '',
				'product_id'   => $product_id,
				'vendor'       => $this->get_id(),
			];

			$plugins[] = $plugin_data;
		}

		return [
			'plugins' => $plugins,
			'total'   => count( $plugins ),
			'pages'   => 1,
		];
	}

	/**
	 * 从产品标题提取 slug
	 *
	 * 薇晓朵商城标题格式: "Blocksy | 企业 博客 商店 可定制轻量级 WordPress 主题"
	 * 提取 "|" 前的英文部分，避免 sanitize_title 被 WPSlug 转成拼音
	 *
	 * @param string $title 产品标题
	 * @return string
	 */
	protected function extract_slug_from_title( string $title ): string {
		// 按 | 分割，取第一部分
		if ( strpos( $title, '|' ) !== false ) {
			$parts = explode( '|', $title, 2 );
			$name  = trim( $parts[0] );
		} else {
			$name = $title;
		}

		// 只保留 ASCII 字符生成 slug，避免 WPSlug 拼音转换
		$ascii_only = preg_replace( '/[^\x20-\x7E]/', '', $name );
		$ascii_only = trim( $ascii_only );

		if ( ! empty( $ascii_only ) ) {
			return sanitize_title( $ascii_only );
		}

		// 全中文标题：返回空，由调用处用 product_id 兜底
		return '';
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
		// WC AM 模式：通过 update action 检查
		if ( $this->config['auth_mode'] === 'wc_am' ) {
			return $this->check_update_via_wc_am( $slug, $current_version );
		}

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
	 * 通过 WC AM 检查更新
	 *
	 * @param string $slug            插件 slug
	 * @param string $current_version 当前版本
	 * @return array|null
	 */
	protected function check_update_via_wc_am( string $slug, string $current_version ): ?array {
		$product_id = $this->resolve_product_id( $slug );

		if ( $product_id <= 0 ) {
			return null;
		}

		$plugin_name = $slug . '/' . $slug . '.php';

		$response = $this->wc_am_update( $product_id, $slug, $plugin_name, $current_version );

		if ( empty( $response['success'] ) || empty( $response['data']['package'] ) ) {
			return null;
		}

		$package = $response['data']['package'];

		return [
			'version'      => $package['new_version'] ?? '',
			'download_url' => $package['package'] ?? '',
			'changelog'    => $package['upgrade_notice'] ?? '',
			'tested'       => $package['tested'] ?? '',
			'requires'     => $package['requires'] ?? '',
			'requires_php' => $package['requires_php'] ?? '',
		];
	}

	/**
	 * 从缓存的产品列表中查找 slug 对应的 product_id
	 *
	 * 查找策略（按优先级）：
	 * 1. 精确匹配：缓存产品列表的 slug
	 * 2. 本地激活记录：wp_options 中 wpbridge_slug_map_{vendor_id} 的手动绑定
	 * 3. 模糊匹配：slug 是否包含在产品标题中（英文部分）
	 *
	 * @param string $slug 插件 slug
	 * @return int
	 */
	protected function resolve_product_id( string $slug ): int {
		// 1. 精确匹配缓存列表
		$plugin = $this->get_plugin( $slug );
		if ( $plugin !== null && ! empty( $plugin['product_id'] ) ) {
			return (int) $plugin['product_id'];
		}

		// 2. 查本地 slug→product_id 映射表
		$slug_map = get_option( 'wpbridge_slug_map_' . $this->vendor_id, [] );
		if ( ! empty( $slug_map[ $slug ] ) ) {
			return (int) $slug_map[ $slug ];
		}

		// 3. 模糊匹配：遍历产品列表，看 slug 是否出现在标题英文部分
		$all_plugins = $this->get_plugins( 1, 1000 );
		foreach ( $all_plugins['plugins'] as $p ) {
			$title = strtolower( $p['name'] ?? '' );
			// 提取 | 前的英文部分
			if ( strpos( $title, '|' ) !== false ) {
				$title = trim( explode( '|', $title, 2 )[0] );
			}
			// slug 完整出现在标题中（如 "avada" 在 "avada" 或 "dokan pro" 含 "dokan-pro"）
			$title_slug = sanitize_title( $title );
			if ( $title_slug === $slug || strpos( $title_slug, $slug ) === 0 ) {
				$pid = (int) ( $p['product_id'] ?? 0 );
				if ( $pid > 0 ) {
					// 存入映射表加速下次查找
					$slug_map[ $slug ] = $pid;
					update_option( 'wpbridge_slug_map_' . $this->vendor_id, $slug_map, false );
					return $pid;
				}
			}
		}

		return 0;
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

		// WC AM 模式：通过 update action 获取 package URL
		if ( $this->config['auth_mode'] === 'wc_am' ) {
			$plugin_name = $slug . '/' . $slug . '.php';
			$response    = $this->wc_am_update(
				$product_id,
				$slug,
				$plugin_name,
				$version ?: '0.0.0'
			);

			// 从响应中提取 package URL（Kestrel 可能返回多种结构）
			$package = $this->extract_package_url( $response );

			if ( $package !== null ) {
				return $package;
			}

			// 首次失败 — 记录响应，尝试自动激活后重试
			Logger::error( 'WC AM download: first attempt failed', [
				'slug'        => $slug,
				'product_id'  => $product_id,
				'response'    => $response,
			] );

			$this->wc_am_activate( $product_id, $version ?: '0.0.0' );

			$response = $this->wc_am_update(
				$product_id,
				$slug,
				$plugin_name,
				$version ?: '0.0.0'
			);

			$package = $this->extract_package_url( $response );

			if ( $package !== null ) {
				return $package;
			}

			Logger::error( 'WC AM download: retry after activate also failed', [
				'slug'        => $slug,
				'product_id'  => $product_id,
				'response'    => $response,
			] );

			return null;
		}

		// Consumer Key 模式
		$instance = $this->config['instance'] ?: $this->generate_instance_id();
		$params   = [
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
	 * 从 WC AM update 响应中提取 package URL
	 *
	 * Kestrel API Manager 和标准 WC AM 返回结构不同：
	 * - 标准: data.package.package (string)
	 * - Kestrel: data.package (string) 或 package (string)
	 *
	 * @param array|null $response API 响应
	 * @return string|null
	 */
	protected function extract_package_url( ?array $response ): ?string {
		if ( empty( $response ) ) {
			return null;
		}

		// 路径 1: data.package.package（标准 WC AM v2）
		$url = $response['data']['package']['package'] ?? null;
		if ( is_string( $url ) && ! empty( $url ) ) {
			return $url;
		}

		// 路径 2: data.package（Kestrel 简化结构）
		$url = $response['data']['package'] ?? null;
		if ( is_string( $url ) && ! empty( $url ) ) {
			return $url;
		}

		// 路径 3: package（顶层）
		$url = $response['package'] ?? null;
		if ( is_string( $url ) && ! empty( $url ) ) {
			return $url;
		}

		return null;
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
	 * @param int    $product_id       产品 ID
	 * @param string $software_version 当前版本（可选）
	 * @return array|null
	 */
	public function wc_am_activate( int $product_id, string $software_version = '' ): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		$params = [
			'product_id' => $product_id,
			'instance'   => $this->get_or_create_instance(),
			'object'     => $this->get_object(),
		];

		if ( ! empty( $software_version ) ) {
			$params['software_version'] = $software_version;
		}

		return $this->wc_am_request( 'activate', $params );
	}

	/**
	 * WC AM: 查询授权状态
	 *
	 * @param int $product_id 产品 ID（可选）
	 * @return array|null
	 */
	public function wc_am_status( int $product_id = 0 ): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		$params = [
			'instance' => $this->get_or_create_instance(),
			'object'   => $this->get_object(),
		];

		if ( $product_id > 0 ) {
			$params['product_id'] = $product_id;
		}

		return $this->wc_am_request( 'status', $params );
	}

	/**
	 * 获取站点域名（不含 scheme）
	 *
	 * @return string
	 */
	protected function get_object(): string {
		return str_ireplace( [ 'http://', 'https://' ], '', home_url() );
	}

	/**
	 * 获取或创建站点实例 ID
	 *
	 * @return string
	 */
	protected function get_or_create_instance(): string {
		$option_key = 'wpbridge_instance_' . $this->vendor_id;
		$instance   = get_option( $option_key );

		if ( empty( $instance ) ) {
			$instance = wp_generate_uuid4();
			update_option( $option_key, $instance, false );
		}

		return $instance;
	}

	/**
	 * 生成实例 ID
	 *
	 * @return string
	 */
	protected function generate_instance_id(): string {
		return $this->get_or_create_instance();
	}

	/**
	 * WC AM 统一请求方法
	 *
	 * @param string $action WC AM action
	 * @param array  $params 额外参数
	 * @return array|null
	 */
	protected function wc_am_request( string $action, array $params = [] ): ?array {
		$base_params = [
			'wc-api'       => 'wc-am-api',
			'wc_am_action' => $action,
			'api_key'      => $this->config['license_key'],
		];

		$url = add_query_arg(
			array_merge( $base_params, $params ),
			trailingslashit( $this->config['api_url'] )
		);

		$response = wp_remote_get( $url, [
			'timeout' => $this->config['timeout'],
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( "WC AM {$action} failed", [
				'vendor' => $this->get_id(),
				'error'  => $response->get_error_message(),
			] );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			Logger::warning( "WC AM {$action} non-200", [
				'vendor' => $this->get_id(),
				'code'   => $code,
			] );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			Logger::error( "WC AM {$action} invalid JSON", [
				'vendor' => $this->get_id(),
			] );
			return null;
		}

		return $data;
	}

	/**
	 * WC AM: 解除产品激活
	 *
	 * @param int $product_id 产品 ID
	 * @return array|null
	 */
	public function wc_am_deactivate( int $product_id ): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		return $this->wc_am_request( 'deactivate', [
			'product_id' => $product_id,
			'instance'   => $this->get_or_create_instance(),
			'object'     => $this->get_object(),
		] );
	}

	/**
	 * WC AM: 验证 Key 有效性
	 *
	 * @return array|null
	 */
	public function wc_am_verify_key(): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		return $this->wc_am_request( 'verify_api_key_is_active', [
			'instance' => $this->get_or_create_instance(),
			'object'   => $this->get_object(),
		] );
	}

	/**
	 * WC AM: 获取已购产品列表
	 *
	 * @return array|null
	 */
	public function wc_am_product_list(): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		return $this->wc_am_request( 'product_list', [
			'instance' => $this->get_or_create_instance(),
			'object'   => $this->get_object(),
		] );
	}

	/**
	 * WC AM: 检查产品更新
	 *
	 * @param int    $product_id  产品 ID
	 * @param string $slug        插件 slug
	 * @param string $plugin_name 插件 basename
	 * @param string $version     当前版本
	 * @return array|null
	 */
	public function wc_am_update( int $product_id, string $slug, string $plugin_name, string $version ): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		return $this->wc_am_request( 'update', [
			'product_id'  => $product_id,
			'slug'        => $slug,
			'plugin_name' => $plugin_name,
			'version'     => $version,
			'instance'    => $this->get_or_create_instance(),
			'object'      => $this->get_object(),
		] );
	}

	/**
	 * WC AM: 获取插件详情
	 *
	 * @param int    $product_id 产品 ID
	 * @param string $slug       插件 slug
	 * @return array|null
	 */
	public function wc_am_information( int $product_id, string $slug ): ?array {
		if ( $this->config['auth_mode'] !== 'wc_am' ) {
			return null;
		}

		return $this->wc_am_request( 'information', [
			'product_id'  => $product_id,
			'slug'        => $slug,
			'plugin_name' => $slug . '/' . $slug . '.php',
			'instance'    => $this->get_or_create_instance(),
			'object'      => $this->get_object(),
		] );
	}
}
