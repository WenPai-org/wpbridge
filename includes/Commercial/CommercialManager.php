<?php
/**
 * 商业插件管理器
 *
 * @package WPBridge
 */

namespace WPBridge\Commercial;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Security\Validator;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 商业插件管理器类
 * 处理商业插件的更新源覆盖和版本管理
 */
class CommercialManager {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 已注册的商业插件
     *
     * @var array
     */
    private array $registered_plugins = [];

    /**
     * 版本锁定列表
     *
     * @var array
     */
    private array $version_locks = [];

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings      = $settings;
        $this->version_locks = $this->settings->get( 'version_locks', [] );

        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'filter_updates' ], 100 );
        add_action( 'upgrader_process_complete', [ $this, 'on_upgrade_complete' ], 10, 2 );
    }

    /**
     * 注册商业插件
     *
     * @param string $slug   插件 slug
     * @param array  $config 配置
     */
    public function register( string $slug, array $config ): void {
        $this->registered_plugins[ $slug ] = wp_parse_args( $config, [
            'name'           => $slug,
            'license_type'   => 'unknown',
            'update_source'  => '',
            'backup_enabled' => true,
        ] );

        Logger::debug( '注册商业插件', [ 'slug' => $slug ] );
    }

    /**
     * 检测已安装的商业插件
     *
     * @return array
     */
    public function detect_commercial_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins        = get_plugins();
        $commercial_plugins = [];

        foreach ( $all_plugins as $file => $data ) {
            $slug = dirname( $file );

            // 检测商业插件特征
            if ( $this->is_commercial_plugin( $file, $data ) ) {
                $commercial_plugins[ $slug ] = [
                    'file'         => $file,
                    'name'         => $data['Name'],
                    'version'      => $data['Version'],
                    'license_type' => $this->detect_license_type( $file, $data ),
                    'registered'   => isset( $this->registered_plugins[ $slug ] ),
                ];
            }
        }

        return $commercial_plugins;
    }

    /**
     * 检查是否是商业插件
     *
     * @param string $file 插件文件
     * @param array  $data 插件数据
     * @return bool
     */
    private function is_commercial_plugin( string $file, array $data ): bool {
        // 已知商业插件列表（允许通过过滤器扩展）
        $known_commercial = apply_filters( 'wpbridge_known_commercial_plugins', [
            'elementor-pro',
            'wordpress-seo-premium',
            'seo-by-rank-math-pro',
            'advanced-custom-fields-pro',
            'gravityforms',
            'wpforms',
            'ninja-forms',
            'woocommerce-subscriptions',
            'woocommerce-memberships',
            'learndash',
            'memberpress',
            'wpml-sitepress-multilingual-cms',
            'updraftplus-premium',
            'wp-rocket',
            'perfmatters',
        ] );

        $slug = dirname( $file );

        if ( in_array( $slug, $known_commercial, true ) ) {
            return true;
        }

        // 检查插件头信息
        $plugin_path = WP_PLUGIN_DIR . '/' . $file;

        // 路径安全验证：防止路径遍历
        $real_path       = realpath( $plugin_path );
        $plugin_dir_real = realpath( WP_PLUGIN_DIR );

        if ( ! $real_path || ! $plugin_dir_real || strpos( $real_path, $plugin_dir_real . DIRECTORY_SEPARATOR ) !== 0 ) {
            return false; // 路径不安全，跳过
        }

        if ( file_exists( $real_path ) ) {
            $content = file_get_contents( $real_path, false, null, 0, 8192 );

            // 检查授权相关关键词
            $license_keywords = [
                'license_key',
                'license-key',
                'activation_key',
                'purchase_code',
                'envato',
                'codecanyon',
                'themeforest',
            ];

            foreach ( $license_keywords as $keyword ) {
                if ( stripos( $content, $keyword ) !== false ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 检测授权类型
     *
     * @param string $file 插件文件
     * @param array  $data 插件数据
     * @return string
     */
    private function detect_license_type( string $file, array $data ): string {
        $plugin_path = WP_PLUGIN_DIR . '/' . $file;

        if ( ! file_exists( $plugin_path ) ) {
            return 'unknown';
        }

        $content = file_get_contents( $plugin_path, false, null, 0, 8192 );

        // EDD Software Licensing
        if ( stripos( $content, 'EDD_SL_Plugin_Updater' ) !== false ) {
            return 'edd';
        }

        // WooCommerce API Manager
        if ( stripos( $content, 'WC_AM_Client' ) !== false ) {
            return 'woocommerce';
        }

        // Envato
        if ( stripos( $content, 'envato' ) !== false ) {
            return 'envato';
        }

        // WPML
        if ( stripos( $content, 'OTGS' ) !== false ) {
            return 'otgs';
        }

        return 'custom';
    }

    /**
     * 过滤更新
     *
     * @param object $transient 更新 transient
     * @return object
     */
    public function filter_updates( $transient ) {
        if ( empty( $transient->response ) ) {
            return $transient;
        }

        foreach ( $transient->response as $file => $update ) {
            $slug = dirname( $file );

            // 检查版本锁定
            if ( $this->is_version_locked( $slug ) ) {
                $locked_version = $this->get_locked_version( $slug );

                if ( version_compare( $update->new_version, $locked_version, '>' ) ) {
                    Logger::info( '版本锁定阻止更新', [
                        'slug'           => $slug,
                        'locked_version' => $locked_version,
                        'new_version'    => $update->new_version,
                    ] );

                    unset( $transient->response[ $file ] );
                }
            }
        }

        return $transient;
    }

    /**
     * 锁定版本
     *
     * @param string $slug    插件 slug
     * @param string $version 版本号
     * @return bool
     */
    public function lock_version( string $slug, string $version ): bool {
        // 权限检查
        if ( ! current_user_can( 'update_plugins' ) ) {
            Logger::warning( '无权限锁定版本', [ 'slug' => $slug, 'user' => get_current_user_id() ] );
            return false;
        }

        // 验证版本号格式
        if ( ! Validator::is_valid_version( $version ) ) {
            return false;
        }

        $this->version_locks[ $slug ] = [
            'version'    => sanitize_text_field( $version ),
            'locked_at'  => current_time( 'mysql' ),
            'locked_by'  => get_current_user_id(),
        ];

        $result = $this->settings->set( 'version_locks', $this->version_locks );

        if ( $result ) {
            Logger::info( '版本已锁定', [ 'slug' => $slug, 'version' => $version ] );
        }

        return $result;
    }

    /**
     * 解锁版本
     *
     * @param string $slug 插件 slug
     * @return bool
     */
    public function unlock_version( string $slug ): bool {
        // 权限检查
        if ( ! current_user_can( 'update_plugins' ) ) {
            Logger::warning( '无权限解锁版本', [ 'slug' => $slug, 'user' => get_current_user_id() ] );
            return false;
        }

        if ( ! isset( $this->version_locks[ $slug ] ) ) {
            return false;
        }

        unset( $this->version_locks[ $slug ] );

        $result = $this->settings->set( 'version_locks', $this->version_locks );

        if ( $result ) {
            Logger::info( '版本已解锁', [ 'slug' => $slug ] );
        }

        return $result;
    }

    /**
     * 检查版本是否锁定
     *
     * @param string $slug 插件 slug
     * @return bool
     */
    public function is_version_locked( string $slug ): bool {
        return isset( $this->version_locks[ $slug ] );
    }

    /**
     * 获取锁定的版本
     *
     * @param string $slug 插件 slug
     * @return string|null
     */
    public function get_locked_version( string $slug ): ?string {
        return $this->version_locks[ $slug ]['version'] ?? null;
    }

    /**
     * 获取所有版本锁定
     *
     * @return array
     */
    public function get_version_locks(): array {
        return $this->version_locks;
    }

    /**
     * 更新完成时触发
     *
     * @param \WP_Upgrader $upgrader 升级器
     * @param array        $options  选项
     */
    public function on_upgrade_complete( $upgrader, array $options ): void {
        if ( $options['type'] !== 'plugin' || $options['action'] !== 'update' ) {
            return;
        }

        $plugins = $options['plugins'] ?? [];

        foreach ( $plugins as $file ) {
            $slug = dirname( $file );

            // 触发更新完成事件
            do_action( 'wpbridge_plugin_updated', $slug, $file );

            Logger::info( '插件更新完成', [ 'slug' => $slug ] );
        }
    }

    /**
     * 获取已注册的商业插件
     *
     * @return array
     */
    public function get_registered_plugins(): array {
        return $this->registered_plugins;
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function get_stats(): array {
        $detected = $this->detect_commercial_plugins();

        return [
            'detected_count'   => count( $detected ),
            'registered_count' => count( $this->registered_plugins ),
            'locked_count'     => count( $this->version_locks ),
        ];
    }
}
