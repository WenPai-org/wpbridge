<?php
/**
 * 供应商产品自动匹配器
 *
 * 自动将供应商产品目录匹配到本地已安装的插件/主题，
 * 并注册更新源，实现商城更新自动推送。
 *
 * @package WPBridge
 * @since 1.2.0
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Commercial\Vendors\VendorManager;
use WPBridge\Core\ItemSourceManager;
use WPBridge\Core\Logger;
use WPBridge\Core\SourceRegistry;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AutoMatcher 类
 */
class AutoMatcher {

	/**
	 * 匹配结果 transient 前缀
	 */
	const CACHE_KEY = 'wpbridge_automatch_last';

	/**
	 * 重新扫描间隔（秒）— 默认 12 小时
	 */
	const SCAN_INTERVAL = 43200;

	/**
	 * 已知的商城 slug → WordPress slug 映射表
	 *
	 * 商城产品标题提取的 slug 和 WordPress 实际目录名不一致时，
	 * 在此硬编码映射。后续商城加 wp_slug 字段后可逐步移除。
	 */
	const SLUG_MAP = [
		// 插件
		'astra-pro'                          => 'astra-addon',
		'hide-my-wp'                         => 'hide_my_wp',
		'ultimate-addons-for-wpbakery'       => 'Ultimate_VC_Addons',
		'ultimate-addons-for-elementor'      => 'ultimate-elementor',
		'powerpack-for-beaver-builder'       => 'bbpowerpack',
		'woostify-pro'                       => 'woostify-pro',
		'dokan-pro'                          => 'dokan-pro',
		'flavor'                             => 'flavor',
		// 主题
		'the7'                               => 'dt-the7',
		'avada'                              => 'Avada',
		'flavor-developer'                   => 'flavor',
		'flavor-developer-developer-license' => 'flavor',
	];

	/**
	 * 源注册表
	 *
	 * @var SourceRegistry
	 */
	private SourceRegistry $source_registry;

	/**
	 * 项目配置管理器
	 *
	 * @var ItemSourceManager
	 */
	private ItemSourceManager $item_manager;

	/**
	 * 构造函数
	 */
	public function __construct() {
		$this->source_registry = new SourceRegistry();
		$this->item_manager    = new ItemSourceManager( $this->source_registry );
	}

	/**
	 * 初始化钩子
	 */
	public function init(): void {
		// 管理后台定期扫描（带节流）
		add_action( 'admin_init', [ $this, 'maybe_scan' ] );

		// 供应商配置保存后立即重新扫描
		add_action( 'wpbridge_vendor_config_saved', [ $this, 'force_scan' ] );
	}

	/**
	 * 带节流的扫描（避免每次 admin 请求都跑）
	 */
	public function maybe_scan(): void {
		$last = get_transient( self::CACHE_KEY );
		if ( false !== $last ) {
			return;
		}

		$this->run_scan();
		set_transient( self::CACHE_KEY, time(), self::SCAN_INTERVAL );
	}

	/**
	 * 强制立即扫描（清除节流缓存）
	 */
	public function force_scan(): void {
		delete_transient( self::CACHE_KEY );
		$this->run_scan();
		set_transient( self::CACHE_KEY, time(), self::SCAN_INTERVAL );
	}

	/**
	 * 执行自动匹配扫描
	 *
	 * @return array 匹配结果摘要
	 */
	public function run_scan(): array {
		$vendor_manager = VendorManager::get_instance();
		$vendors        = $vendor_manager->get_vendors( true );

		if ( empty( $vendors ) ) {
			return [
				'matched' => 0,
				'skipped' => 0,
			];
		}

		// 获取本地已安装的插件和主题
		$installed_plugins = $this->get_installed_plugins();
		$installed_themes  = $this->get_installed_themes();

		$total_matched = 0;
		$total_skipped = 0;

		foreach ( $vendors as $vendor ) {
			$vendor_id  = $vendor->get_id();
			$source_key = 'vendor_' . $vendor_id;

			// 确保 SourceRegistry 里有这个 vendor 的源
			$this->ensure_vendor_source( $vendor_id, $vendor->get_info() );

			// 获取供应商产品列表
			try {
				$result   = $vendor->get_plugins();
				$products = $result['plugins'] ?? [];
			} catch ( \Exception $e ) {
				Logger::error(
					'AutoMatcher: 获取供应商产品失败',
					[
						'vendor' => $vendor_id,
						'error'  => $e->getMessage(),
					]
				);
				continue;
			}

			foreach ( $products as $product ) {
				$vendor_slug = $product['slug'] ?? '';
				if ( empty( $vendor_slug ) ) {
					++$total_skipped;
					continue;
				}

				// 跳过没有版本信息的产品（供应商无法提供实际更新）
				if ( empty( $product['version'] ) ) {
					++$total_skipped;
					continue;
				}

				// 尝试匹配本地插件/主题
				$match = $this->match_product(
					$vendor_slug,
					$product,
					$installed_plugins,
					$installed_themes
				);

				if ( null === $match ) {
					++$total_skipped;
					continue;
				}

				// 注册到 ItemSourceManager
				$item_key = $match['item_key'];
				$existing = $this->item_manager->get( $item_key );

				// 已有用户配置的不覆盖（自定义源或已禁用更新）
				if ( $existing && in_array(
					$existing['mode'],
					[
						ItemSourceManager::MODE_CUSTOM,
						ItemSourceManager::MODE_DISABLED,
					],
					true
				) ) {
					++$total_skipped;
					continue;
				}

				$this->item_manager->set(
					$item_key,
					[
						'item_type'  => $match['type'],
						'item_slug'  => $match['wp_slug'],
						'label'      => $match['label'],
						'mode'       => ItemSourceManager::MODE_CUSTOM,
						'source_ids' => [ $source_key => 100 ],
						'metadata'   => [
							'preconfigured' => true,
							'installed'     => true,
							'vendor_id'     => $vendor_id,
							'vendor_slug'   => $vendor_slug,
							'product_id'    => $product['product_id'] ?? 0,
							'auto_matched'  => true,
							'matched_at'    => current_time( 'mysql' ),
						],
					]
				);

				++$total_matched;

				Logger::info(
					'AutoMatcher: 匹配成功',
					[
						'vendor_slug' => $vendor_slug,
						'wp_slug'     => $match['wp_slug'],
						'item_key'    => $item_key,
						'vendor'      => $vendor_id,
					]
				);
			}
		}

		if ( $total_matched > 0 ) {
			// 清除更新缓存，让 WordPress 重新检查
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
		}

		Logger::info(
			'AutoMatcher: 扫描完成',
			[
				'matched' => $total_matched,
				'skipped' => $total_skipped,
			]
		);

		return [
			'matched' => $total_matched,
			'skipped' => $total_skipped,
		];
	}

	/**
	 * 匹配单个供应商产品到本地插件/主题
	 *
	 * 匹配优先级：
	 * 1. API 返回的 wp_slug 字段（商城侧配置）
	 * 2. 预设映射表 SLUG_MAP
	 * 3. 本地 slug_map option（之前 resolve_product_id 存的）
	 * 4. 精确匹配已安装插件/主题目录名
	 * 5. 模糊匹配（slug 包含关系）
	 *
	 * @param string $vendor_slug       供应商产品 slug
	 * @param array  $product           产品数据
	 * @param array  $installed_plugins 已安装插件 [dir_name => plugin_file]
	 * @param array  $installed_themes  已安装主题 [dir_name => theme_data]
	 * @return array|null {item_key, type, wp_slug, label}
	 */
	private function match_product(
		string $vendor_slug,
		array $product,
		array $installed_plugins,
		array $installed_themes
	): ?array {
		$wp_slug = null;

		// 1. API 返回的 wp_slug（商城侧自定义字段）
		if ( ! empty( $product['wp_slug'] ) ) {
			$wp_slug = $product['wp_slug'];
		}

		// 2. 预设映射表
		if ( null === $wp_slug && isset( self::SLUG_MAP[ $vendor_slug ] ) ) {
			$wp_slug = self::SLUG_MAP[ $vendor_slug ];
		}

		// 3. 本地 slug_map（WooCommerceVendor::resolve_product_id 存的）
		if ( null === $wp_slug ) {
			$vendor_id = $product['vendor'] ?? '';
			if ( ! empty( $vendor_id ) ) {
				$local_map = get_option( 'wpbridge_slug_map_' . $vendor_id, [] );
				// 反向查找：local_map 是 wp_slug => product_id
				$product_id = $product['product_id'] ?? 0;
				foreach ( $local_map as $slug => $pid ) {
					if ( (int) $pid === (int) $product_id ) {
						$wp_slug = $slug;
						break;
					}
				}
			}
		}

		// 用确定的 wp_slug 或 vendor_slug 去匹配
		$candidates = array_filter( [ $wp_slug, $vendor_slug ] );

		foreach ( $candidates as $slug ) {
			// 匹配插件
			if ( isset( $installed_plugins[ $slug ] ) ) {
				return [
					'item_key' => 'plugin:' . $installed_plugins[ $slug ],
					'type'     => 'plugin',
					'wp_slug'  => $slug,
					'label'    => $product['name'] ?? $slug,
				];
			}

			// 匹配主题
			if ( isset( $installed_themes[ $slug ] ) ) {
				return [
					'item_key' => 'theme:' . $slug,
					'type'     => 'theme',
					'wp_slug'  => $slug,
					'label'    => $installed_themes[ $slug ]['Name'] ?? $slug,
				];
			}
		}

		// 4. 大小写不敏感精确匹配（不做模糊 strpos，避免误匹配免费插件）
		$exact_slug_lower = strtolower( $wp_slug ?? $vendor_slug );

		foreach ( $installed_plugins as $dir_name => $plugin_file ) {
			if ( strtolower( $dir_name ) === $exact_slug_lower ) {
				return [
					'item_key' => 'plugin:' . $plugin_file,
					'type'     => 'plugin',
					'wp_slug'  => $dir_name,
					'label'    => $product['name'] ?? $dir_name,
				];
			}
		}

		foreach ( $installed_themes as $dir_name => $theme_data ) {
			if ( strtolower( $dir_name ) === $exact_slug_lower ) {
				return [
					'item_key' => 'theme:' . $dir_name,
					'type'     => 'theme',
					'wp_slug'  => $dir_name,
					'label'    => $theme_data['Name'] ?? $dir_name,
				];
			}
		}

		return null;
	}

	/**
	 * 确保 SourceRegistry 中有该供应商的源
	 *
	 * @param string $vendor_id   供应商 ID
	 * @param array  $vendor_info 供应商信息
	 */
	private function ensure_vendor_source( string $vendor_id, array $vendor_info ): void {
		$source_key = 'vendor_' . $vendor_id;
		$existing   = $this->source_registry->get( $source_key );

		if ( null !== $existing ) {
			return;
		}

		$this->source_registry->add(
			[
				'source_key'       => $source_key,
				'name'             => $vendor_info['name'] ?? $vendor_id,
				'type'             => SourceRegistry::TYPE_VENDOR,
				'base_url'         => $vendor_info['url'] ?? '',
				'api_url'          => $vendor_info['url'] ?? '',
				'enabled'          => true,
				'default_priority' => 100,
				'trust_level'      => 70,
				'capabilities'     => [ 'plugins', 'themes' ],
				'metadata'         => [ 'vendor_id' => $vendor_id ],
			]
		);

		Logger::info(
			'AutoMatcher: 注册供应商源',
			[
				'source_key' => $source_key,
				'vendor'     => $vendor_id,
			]
		);
	}

	/**
	 * 获取已安装插件列表
	 *
	 * @return array [目录名 => plugin_file]
	 */
	private function get_installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$map     = [];

		foreach ( $plugins as $plugin_file => $data ) {
			// plugin_file 格式: "dir-name/file.php" 或 "file.php"
			$parts    = explode( '/', $plugin_file, 2 );
			$dir_name = $parts[0];

			// 单文件插件跳过（没有目录）
			if ( count( $parts ) < 2 ) {
				continue;
			}

			$map[ $dir_name ] = $plugin_file;
		}

		return $map;
	}

	/**
	 * 获取已安装主题列表
	 *
	 * @return array [目录名 => ['Name' => ...]]
	 */
	private function get_installed_themes(): array {
		$themes = wp_get_themes();
		$map    = [];

		foreach ( $themes as $slug => $theme ) {
			$map[ $slug ] = [
				'Name'    => $theme->get( 'Name' ),
				'Version' => $theme->get( 'Version' ),
			];
		}

		return $map;
	}
}
