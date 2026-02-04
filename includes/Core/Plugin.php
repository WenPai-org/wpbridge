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
use WPBridge\AIBridge\AIGateway;
use WPBridge\Commercial\CommercialManager;
use WPBridge\Notification\NotificationManager;
use WPBridge\SourceGroup\GroupManager;
use WPBridge\API\RestController;

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
     * AI 网关
     *
     * @var AIGateway|null
     */
    private ?AIGateway $ai_gateway = null;

    /**
     * 商业插件管理器
     *
     * @var CommercialManager|null
     */
    private ?CommercialManager $commercial_manager = null;

    /**
     * 通知管理器
     *
     * @var NotificationManager|null
     */
    private ?NotificationManager $notification_manager = null;

    /**
     * 源分组管理器
     *
     * @var GroupManager|null
     */
    private ?GroupManager $group_manager = null;

    /**
     * REST API 控制器
     *
     * @var RestController|null
     */
    private ?RestController $rest_controller = null;

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

        // 管理界面 - 在 plugins_loaded 之后立即初始化
        if ( is_admin() ) {
            $this->init_admin();
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
        $this->plugin_updater       = new PluginUpdater( $this->settings );
        $this->theme_updater        = new ThemeUpdater( $this->settings );
        $this->ai_gateway           = new AIGateway( $this->settings );
        $this->commercial_manager   = new CommercialManager( $this->settings );
        $this->notification_manager = new NotificationManager( $this->settings );
        $this->group_manager        = new GroupManager( $this->settings );
        $this->rest_controller      = new RestController( $this->settings );

        // 注册 AI 适配器
        $this->register_ai_adapters();
    }

    /**
     * 注册 AI 适配器
     */
    private function register_ai_adapters(): void {
        if ( null === $this->ai_gateway ) {
            return;
        }

        $this->ai_gateway->register_adapter(
            'yoast',
            new \WPBridge\AIBridge\Adapters\YoastAdapter( $this->settings )
        );

        $this->ai_gateway->register_adapter(
            'rankmath',
            new \WPBridge\AIBridge\Adapters\RankMathAdapter( $this->settings )
        );
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
                'confirm_delete'     => __( '确定要删除这个更新源吗？', 'wpbridge' ),
                'confirm_revoke'     => __( '确定要撤销此 API Key 吗？', 'wpbridge' ),
                'confirm_clear_logs' => __( '确定要清除所有日志吗？', 'wpbridge' ),
                'testing'            => __( '测试中...', 'wpbridge' ),
                'success'            => __( '成功', 'wpbridge' ),
                'failed'             => __( '操作失败', 'wpbridge' ),
                'enabled'            => __( '已启用', 'wpbridge' ),
                'disabled'           => __( '已禁用', 'wpbridge' ),
                'healthy'            => __( '正常', 'wpbridge' ),
                'degraded'           => __( '降级', 'wpbridge' ),
                'failed_status'      => __( '失败', 'wpbridge' ),
                'test_success'       => __( '连接成功', 'wpbridge' ),
                'test_degraded'      => __( '连接异常', 'wpbridge' ),
                'cache_cleared'      => __( '缓存已清除', 'wpbridge' ),
                'logs_cleared'       => __( '日志已清除', 'wpbridge' ),
                'no_logs'            => __( '暂无日志记录', 'wpbridge' ),
                'enter_key_name'     => __( '请输入 API Key 名称：', 'wpbridge' ),
                'key_generated'      => __( 'API Key 已生成，请妥善保存：', 'wpbridge' ),
                'key_warning'        => __( '此 Key 只会显示一次，请立即复制保存。', 'wpbridge' ),
                'key_revoked'        => __( 'API Key 已撤销', 'wpbridge' ),
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
     * 获取 AI 网关
     *
     * @return AIGateway|null
     */
    public function get_ai_gateway(): ?AIGateway {
        return $this->ai_gateway;
    }

    /**
     * 获取商业插件管理器
     *
     * @return CommercialManager|null
     */
    public function get_commercial_manager(): ?CommercialManager {
        return $this->commercial_manager;
    }

    /**
     * 获取通知管理器
     *
     * @return NotificationManager|null
     */
    public function get_notification_manager(): ?NotificationManager {
        return $this->notification_manager;
    }

    /**
     * 获取源分组管理器
     *
     * @return GroupManager|null
     */
    public function get_group_manager(): ?GroupManager {
        return $this->group_manager;
    }

    /**
     * 获取 REST API 控制器
     *
     * @return RestController|null
     */
    public function get_rest_controller(): ?RestController {
        return $this->rest_controller;
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
