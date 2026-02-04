<?php
/**
 * 插件主类
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

use WPBridge\UpdateSource\PluginUpdater;
use WPBridge\UpdateSource\ThemeUpdater;
use WPBridge\Admin\AdminPage;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 插件主类（单例模式）
 */
class Plugin {

    /**
     * 单例实例
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * 设置管理器
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 插件更新器
     *
     * @var PluginUpdater|null
     */
    private ?PluginUpdater $plugin_updater = null;

    /**
     * 主题更新器
     *
     * @var ThemeUpdater|null
     */
    private ?ThemeUpdater $theme_updater = null;

    /**
     * 获取单例实例
     *
     * @return Plugin
     */
    public static function get_instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造函数
     */
    private function __construct() {
        $this->settings = new Settings();
        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        // 加载文本域
        add_action( 'init', [ $this, 'load_textdomain' ] );

        // 初始化更新器
        add_action( 'init', [ $this, 'init_updaters' ] );

        // 管理界面
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'init_admin' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        }

        // 插件链接
        add_filter( 'plugin_action_links_' . WPBRIDGE_BASENAME, [ $this, 'add_action_links' ] );
    }

    /**
     * 加载文本域
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'wpbridge',
            false,
            dirname( WPBRIDGE_BASENAME ) . '/languages'
        );
    }

    /**
     * 初始化更新器
     */
    public function init_updaters(): void {
        $this->plugin_updater = new PluginUpdater( $this->settings );
        $this->theme_updater  = new ThemeUpdater( $this->settings );
    }

    /**
     * 初始化管理界面
     */
    public function init_admin(): void {
        new AdminPage( $this->settings );
    }

    /**
     * 加载管理界面资源
     *
     * @param string $hook 当前页面钩子
     */
    public function enqueue_admin_assets( string $hook ): void {
        // 只在插件页面加载
        if ( strpos( $hook, 'wpbridge' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpbridge-admin',
            WPBRIDGE_URL . 'assets/css/admin.css',
            [],
            WPBRIDGE_VERSION
        );

        wp_enqueue_script(
            'wpbridge-admin',
            WPBRIDGE_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WPBRIDGE_VERSION,
            true
        );

        wp_localize_script( 'wpbridge-admin', 'wpbridge', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpbridge_nonce' ),
            'i18n'     => [
                'confirm_delete' => __( '确定要删除这个更新源吗？', 'wpbridge' ),
                'testing'        => __( '测试中...', 'wpbridge' ),
                'success'        => __( '成功', 'wpbridge' ),
                'failed'         => __( '失败', 'wpbridge' ),
            ],
        ] );
    }

    /**
     * 添加插件操作链接
     *
     * @param array $links 现有链接
     * @return array
     */
    public function add_action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wpbridge' ),
            __( '设置', 'wpbridge' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 获取设置管理器
     *
     * @return Settings
     */
    public function get_settings(): Settings {
        return $this->settings;
    }

    /**
     * 插件激活
     */
    public static function activate(): void {
        // 创建默认设置
        $settings = new Settings();
        $settings->init_defaults();

        // 清除更新缓存
        delete_site_transient( 'update_plugins' );
        delete_site_transient( 'update_themes' );

        // 记录激活时间
        update_option( 'wpbridge_activated', time() );
    }

    /**
     * 插件停用
     */
    public static function deactivate(): void {
        // 清除缓存
        self::clear_all_cache();

        // 移除定时任务
        wp_clear_scheduled_hook( 'wpbridge_update_sources' );
    }

    /**
     * 清除所有缓存
     */
    public static function clear_all_cache(): void {
        global $wpdb;

        // 清除所有 wpbridge 相关的 transient（使用 prepare 防止 SQL 注入）
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_wpbridge_' ) . '%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_timeout_wpbridge_' ) . '%'
            )
        );

        // 清除对象缓存组（不使用 flush 避免影响其他插件）
        if ( wp_using_ext_object_cache() ) {
            if ( function_exists( 'wp_cache_flush_group' ) ) {
                wp_cache_flush_group( 'wpbridge' );
            } else {
                wp_cache_delete( 'wpbridge', 'wpbridge' );
            }
        }
    }
}
