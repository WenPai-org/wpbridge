<?php
/**
 * 版本锁定管理器
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 版本锁定管理器类
 */
class VersionLock {

	/**
	 * 选项名称
	 */
	const OPTION_NAME = 'wpbridge_version_locks';

	/**
	 * 锁定类型常量
	 */
	const LOCK_CURRENT  = 'current';      // 锁定到当前版本
	const LOCK_SPECIFIC = 'specific';    // 锁定到指定版本
	const LOCK_IGNORE   = 'ignore';        // 忽略特定版本

	/**
	 * 单例实例
	 *
	 * @var VersionLock|null
	 */
	private static ?VersionLock $instance = null;

	/**
	 * 锁定数据缓存
	 *
	 * @var array|null
	 */
	private ?array $locks = null;

	/**
	 * 获取单例实例
	 *
	 * @return VersionLock
	 */
	public static function get_instance(): VersionLock {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 私有构造函数
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * 初始化钩子
	 */
	private function init_hooks(): void {
		// 过滤插件更新
		add_filter( 'site_transient_update_plugins', [ $this, 'filter_plugin_updates' ], 100 );
		// 过滤主题更新
		add_filter( 'site_transient_update_themes', [ $this, 'filter_theme_updates' ], 100 );
	}

	/**
	 * 获取所有锁定
	 *
	 * @return array
	 */
	public function get_all(): array {
		if ( null === $this->locks ) {
			$this->locks = get_option( self::OPTION_NAME, [] );
			if ( ! is_array( $this->locks ) ) {
				$this->locks = [];
			}
		}
		return $this->locks;
	}

	/**
	 * 获取项目的锁定信息
	 *
	 * @param string $item_key 项目键（如 plugin:hello.php 或 theme:flavor）
	 * @return array|null
	 */
	public function get( string $item_key ): ?array {
		$locks = $this->get_all();
		return $locks[ $item_key ] ?? null;
	}

	/**
	 * 锁定项目版本
	 *
	 * @param string $item_key    项目键
	 * @param string $lock_type   锁定类型
	 * @param string $version     版本号（锁定到指定版本时使用）
	 * @param array  $ignore_versions 忽略的版本列表
	 * @return bool
	 */
	public function lock( string $item_key, string $lock_type, string $version = '', array $ignore_versions = [] ): bool {
		$locks = $this->get_all();

		$locks[ $item_key ] = [
			'type'            => $lock_type,
			'version'         => $version,
			'ignore_versions' => $ignore_versions,
			'locked_at'       => current_time( 'mysql' ),
		];

		$this->locks = $locks;
		return update_option( self::OPTION_NAME, $locks );
	}

	/**
	 * 解锁项目
	 *
	 * @param string $item_key 项目键
	 * @return bool
	 */
	public function unlock( string $item_key ): bool {
		$locks = $this->get_all();

		if ( ! isset( $locks[ $item_key ] ) ) {
			return true;
		}

		unset( $locks[ $item_key ] );
		$this->locks = $locks;
		return update_option( self::OPTION_NAME, $locks );
	}

	/**
	 * 检查项目是否被锁定
	 *
	 * @param string $item_key 项目键
	 * @return bool
	 */
	public function is_locked( string $item_key ): bool {
		return null !== $this->get( $item_key );
	}

	/**
	 * 检查是否应该阻止更新
	 *
	 * @param string $item_key       项目键
	 * @param string $current_version 当前版本
	 * @param string $new_version    新版本
	 * @return bool 返回 true 表示应该阻止更新
	 */
	public function should_block_update( string $item_key, string $current_version, string $new_version ): bool {
		$lock = $this->get( $item_key );

		if ( null === $lock ) {
			return false;
		}

		switch ( $lock['type'] ) {
			case self::LOCK_CURRENT:
				// 锁定到当前版本，阻止所有更新
				return true;

			case self::LOCK_SPECIFIC:
				// 锁定到指定版本，如果当前版本等于锁定版本则阻止更新
				return version_compare( $current_version, $lock['version'], '==' );

			case self::LOCK_IGNORE:
				// 忽略特定版本
				return in_array( $new_version, $lock['ignore_versions'], true );

			default:
				return false;
		}
	}

	/**
	 * 过滤插件更新
	 *
	 * @param object $transient 更新 transient
	 * @return object
	 */
	public function filter_plugin_updates( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		if ( empty( $transient->response ) ) {
			return $transient;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();

		foreach ( $transient->response as $plugin_file => $update_info ) {
			$item_key        = 'plugin:' . $plugin_file;
			$current_version = $plugins[ $plugin_file ]['Version'] ?? '0';
			$new_version     = is_object( $update_info ) ? $update_info->new_version : ( $update_info['new_version'] ?? '0' );

			if ( $this->should_block_update( $item_key, $current_version, $new_version ) ) {
				// 移动到 no_update
				if ( ! isset( $transient->no_update ) ) {
					$transient->no_update = [];
				}
				$transient->no_update[ $plugin_file ] = $update_info;
				unset( $transient->response[ $plugin_file ] );

				Logger::debug(
					sprintf(
						'Version lock: blocked update for %s (current: %s, new: %s)',
						$plugin_file,
						$current_version,
						$new_version
					)
				);
			}
		}

		return $transient;
	}

	/**
	 * 过滤主题更新
	 *
	 * @param object $transient 更新 transient
	 * @return object
	 */
	public function filter_theme_updates( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		if ( empty( $transient->response ) ) {
			return $transient;
		}

		$themes = wp_get_themes();

		foreach ( $transient->response as $theme_slug => $update_info ) {
			$item_key        = 'theme:' . $theme_slug;
			$current_version = isset( $themes[ $theme_slug ] ) ? $themes[ $theme_slug ]->get( 'Version' ) : '0';
			$new_version     = is_array( $update_info ) ? ( $update_info['new_version'] ?? '0' ) : '0';

			if ( $this->should_block_update( $item_key, $current_version, $new_version ) ) {
				unset( $transient->response[ $theme_slug ] );

				Logger::debug(
					sprintf(
						'Version lock: blocked update for theme %s (current: %s, new: %s)',
						$theme_slug,
						$current_version,
						$new_version
					)
				);
			}
		}

		return $transient;
	}

	/**
	 * 获取锁定类型标签
	 *
	 * @param string $type 锁定类型
	 * @return string
	 */
	public static function get_type_label( string $type ): string {
		$labels = [
			self::LOCK_CURRENT  => __( '锁定当前版本', 'wpbridge' ),
			self::LOCK_SPECIFIC => __( '锁定指定版本', 'wpbridge' ),
			self::LOCK_IGNORE   => __( '忽略特定版本', 'wpbridge' ),
		];
		return $labels[ $type ] ?? $type;
	}

	/**
	 * 获取所有锁定类型
	 *
	 * @return array
	 */
	public static function get_lock_types(): array {
		return [
			self::LOCK_CURRENT  => __( '锁定当前版本', 'wpbridge' ),
			self::LOCK_SPECIFIC => __( '锁定指定版本', 'wpbridge' ),
			self::LOCK_IGNORE   => __( '忽略特定版本', 'wpbridge' ),
		];
	}

	/**
	 * 清除缓存
	 */
	public function clear_cache(): void {
		$this->locks = null;
	}
}
