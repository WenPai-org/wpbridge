<?php
/**
 * 插件更新器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\UpdateSource\Handlers\UpdateInfo;
use WPBridge\Core\ItemSourceManager;
use WPBridge\Cache\FallbackStrategy;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 插件更新器类
 */
class PluginUpdater {

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 源解析器（方案 B）
	 *
	 * @var SourceResolver
	 */
	private SourceResolver $source_resolver;

	/**
	 * 降级策略
	 *
	 * @var FallbackStrategy
	 */
	private FallbackStrategy $fallback_strategy;

	/**
	 * 缓存键前缀
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wpbridge_plugin_update_';

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings          = $settings;
		$this->source_resolver   = new SourceResolver();
		$this->fallback_strategy = new FallbackStrategy( $settings );

		$this->init_hooks();
	}

	/**
	 * 初始化钩子
	 */
	private function init_hooks(): void {
		// 插件更新检查
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_updates' ], 10, 1 );

		// 插件信息
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

		// 下载包过滤
		add_filter( 'upgrader_pre_download', [ $this, 'filter_download' ], 10, 3 );
	}

	/**
	 * 检查插件更新
	 *
	 * @param object $transient 更新 transient
	 * @return object
	 */
	public function check_updates( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( ! isset( $transient->response ) ) {
			$transient->response = [];
		}

		if ( ! isset( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		// 获取已安装的插件
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$slug = $this->get_plugin_slug( $plugin_file );

			$item_key             = 'plugin:' . $plugin_file;
			$resolved             = $this->source_resolver->resolve( $item_key, $slug, 'plugin' );
			$mode                 = $resolved['mode'];
			$matching_sources     = $resolved['sources'];
			$allow_wporg_fallback = ! empty( $resolved['has_wporg'] );

			if ( $mode === ItemSourceManager::MODE_DISABLED ) {
				unset( $transient->response[ $plugin_file ] );
				$transient->no_update[ $plugin_file ] = (object) [
					'slug'        => $slug,
					'plugin'      => $plugin_file,
					'new_version' => $plugin_data['Version'],
				];
				continue;
			}

			if ( empty( $matching_sources ) ) {
				if ( $mode === ItemSourceManager::MODE_CUSTOM ) {
					unset( $transient->response[ $plugin_file ] );
					$transient->no_update[ $plugin_file ] = (object) [
						'slug'        => $slug,
						'plugin'      => $plugin_file,
						'new_version' => $plugin_data['Version'],
					];
				}
				continue;
			}

			$take_over = ( $mode === ItemSourceManager::MODE_CUSTOM ) || ! $allow_wporg_fallback;

			if ( $take_over ) {
				// 接管更新检查，清除默认响应
				unset( $transient->response[ $plugin_file ] );
			}

			// 尝试从缓存获取（使用 md5 哈希防止缓存污染）
			$cache_key = self::CACHE_PREFIX . md5( $slug . get_site_url() );
			$cached    = get_transient( $cache_key );

			if ( false !== $cached ) {
				if ( ! empty( $cached['update'] ) ) {
					$transient->response[ $plugin_file ] = (object) $cached['update'];
					unset( $transient->no_update[ $plugin_file ] );
				} elseif ( $take_over ) {
						$transient->no_update[ $plugin_file ] = (object) [
							'slug'        => $slug,
							'plugin'      => $plugin_file,
							'new_version' => $plugin_data['Version'],
						];
				}
				continue;
			}

			// 检查更新
			$update_info = $this->check_plugin_update( $slug, $plugin_data['Version'], $matching_sources );

			if ( null !== $update_info ) {
				$update_object         = $update_info->to_wp_update_object();
				$update_object->plugin = $plugin_file;

				$transient->response[ $plugin_file ] = $update_object;
				unset( $transient->no_update[ $plugin_file ] );

				// 缓存结果
				set_transient(
					$cache_key,
					[
						'update' => (array) $update_object,
					],
					$this->settings->get_cache_ttl()
				);

				Logger::info(
					'插件更新可用',
					[
						'plugin'  => $plugin_file,
						'current' => $plugin_data['Version'],
						'new'     => $update_info->version,
					]
				);
			} else {
				if ( $take_over ) {
					$transient->no_update[ $plugin_file ] = (object) [
						'slug'        => $slug,
						'plugin'      => $plugin_file,
						'new_version' => $plugin_data['Version'],
					];
				}

				// 缓存无更新结果（短缓存，避免临时网络失败长期阻塞真正的更新）
				set_transient(
					$cache_key,
					[
						'update' => null,
					],
					min( 300, $this->settings->get_cache_ttl() )
				);
			}
		}

		return $transient;
	}

	/**
	 * 检查单个插件更新
	 *
	 * @param string        $slug    插件 slug
	 * @param string        $version 当前版本
	 * @param SourceModel[] $sources 更新源列表
	 * @return UpdateInfo|null
	 */
	private function check_plugin_update( string $slug, string $version, array $sources ): ?UpdateInfo {
		$cache_key = 'update_info_plugin_' . md5( $slug . get_site_url() );

		$result = $this->fallback_strategy->execute_with_fallback(
			$sources,
			function ( SourceModel $source ) use ( $slug, $version ) {
				$handler = $source->get_handler();

				if ( null === $handler ) {
					Logger::warning(
						'无法获取处理器',
						[
							'source' => $source->id,
							'type'   => $source->type,
						]
					);
					return null;
				}

				try {
					return $handler->check_update( $slug, $version );
				} catch ( \Exception $e ) {
					Logger::error(
						'检查更新时发生错误',
						[
							'source' => $source->id,
							'slug'   => $slug,
							'error'  => $e->getMessage(),
						]
					);
					throw $e;
				}
			},
			$cache_key
		);

		return $result instanceof UpdateInfo ? $result : null;
	}

	/**
	 * 获取插件信息
	 *
	 * @param false|object|array $result 结果
	 * @param string             $action 动作
	 * @param object             $args   参数
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = $args->slug ?? '';

		if ( empty( $slug ) ) {
			return $result;
		}

		$item_key = $this->get_item_key_from_slug( $slug );
		$resolved = $this->source_resolver->resolve( $item_key, $slug, 'plugin' );
		$mode     = $resolved['mode'];
		$sources  = $resolved['sources'];

		if ( $mode === ItemSourceManager::MODE_DISABLED || empty( $sources ) ) {
			return $result;
		}

		// 获取第一个匹配的源
		$source  = reset( $sources );
		$handler = $source->get_handler();

		if ( null === $handler ) {
			return $result;
		}

		$info = $handler->get_info( $slug );

		if ( null === $info ) {
			return $result;
		}

		// 转换为 plugins_api 响应格式
		$update_info = Handlers\UpdateInfo::from_array( $info );
		return $update_info->to_plugins_api_response( $info['name'] ?? $slug );
	}

	/**
	 * 从 slug 推断项目键
	 *
	 * @param string $slug 插件 slug
	 * @return string
	 */
	private function get_item_key_from_slug( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$plugin_slug = $this->get_plugin_slug( $plugin_file );
			if ( $plugin_slug === $slug ) {
				return 'plugin:' . $plugin_file;
			}
		}

		return 'plugin:' . $slug;
	}

	/**
	 * 过滤下载
	 *
	 * @param bool   $reply   是否已处理
	 * @param string $package 下载包 URL
	 * @param object $upgrader 升级器
	 * @return bool
	 */
	public function filter_download( $reply, $package, $upgrader ) {
		// 目前不做特殊处理，直接返回
		return $reply;
	}

	/**
	 * 获取插件 slug
	 *
	 * @param string $plugin_file 插件文件路径
	 * @return string
	 */
	private function get_plugin_slug( string $plugin_file ): string {
		if ( strpos( $plugin_file, '/' ) !== false ) {
			return dirname( $plugin_file );
		}
		return str_replace( '.php', '', $plugin_file );
	}

	/**
	 * 清除插件更新缓存
	 *
	 * @param string|null $slug 插件 slug，为空则清除所有
	 */
	public function clear_cache( ?string $slug = null ): void {
		if ( null !== $slug ) {
			delete_transient( self::CACHE_PREFIX . md5( $slug . get_site_url() ) );
		} else {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
				)
			);
		}

		// 清除 WordPress 更新缓存
		delete_site_transient( 'update_plugins' );
	}
}
