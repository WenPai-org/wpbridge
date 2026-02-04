<?php
/**
 * 管理页面
 *
 * @package WPBridge
 */

namespace WPBridge\Admin;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Security\Encryption;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\UpdateSource\SourceModel;
use WPBridge\UpdateSource\SourceType;
use WPBridge\Cache\HealthChecker;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 管理页面类
 */
class AdminPage {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 源管理器
     *
     * @var SourceManager
     */
    private SourceManager $source_manager;

    /**
     * 页面 slug
     *
     * @var string
     */
    const PAGE_SLUG = 'wpbridge';

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings       = $settings;
        $this->source_manager = new SourceManager( $settings );

        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'wp_ajax_wpbridge_toggle_source', [ $this, 'ajax_toggle_source' ] );
        add_action( 'wp_ajax_wpbridge_test_source', [ $this, 'ajax_test_source' ] );
        add_action( 'wp_ajax_wpbridge_clear_cache', [ $this, 'ajax_clear_cache' ] );
        add_action( 'wp_ajax_wpbridge_clear_logs', [ $this, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_wpbridge_generate_api_key', [ $this, 'ajax_generate_api_key' ] );
        add_action( 'wp_ajax_wpbridge_revoke_api_key', [ $this, 'ajax_revoke_api_key' ] );
    }

    /**
     * 添加菜单
     */
    public function add_menu(): void {
        add_menu_page(
            __( '云桥', 'wpbridge' ),
            __( '云桥', 'wpbridge' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'dashicons-networking',
            80
        );
    }

    /**
     * 渲染主页面
     */
    public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 仅用于页面路由
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';

        // 白名单验证
        $allowed_actions = [ 'list', 'add', 'edit' ];
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            $action = 'list';
        }

        switch ( $action ) {
            case 'add':
            case 'edit':
                $this->render_editor_page();
                break;
            default:
                $this->render_main_page();
                break;
        }
    }

    /**
     * 渲染主页面（新版 Tab 布局）
     */
    private function render_main_page(): void {
        include WPBRIDGE_PATH . 'templates/admin/main.php';
    }

    /**
     * 渲染列表页面（旧版，保留兼容）
     */
    private function render_list_page(): void {
        $sources = $this->source_manager->get_all();
        $stats   = $this->source_manager->get_stats();

        include WPBRIDGE_PATH . 'templates/admin/source-list.php';
    }

    /**
     * 渲染编辑页面
     */
    private function render_editor_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 仅用于获取源 ID
        $source_id = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
        $source    = null;

        if ( ! empty( $source_id ) ) {
            $source = $this->source_manager->get( $source_id );
        }

        $types = SourceType::get_labels();

        include WPBRIDGE_PATH . 'templates/admin/source-editor.php';
    }

    /**
     * 渲染设置页面
     */
    public function render_settings_page(): void {
        $settings = $this->settings->get_all();
        $logs     = Logger::get_logs();

        include WPBRIDGE_PATH . 'templates/admin/settings.php';
    }

    /**
     * 处理表单提交
     */
    public function handle_actions(): void {
        if ( ! isset( $_POST['wpbridge_action'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['wpbridge_nonce'] ?? '', 'wpbridge_action' ) ) {
            wp_die( __( '安全检查失败', 'wpbridge' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( '权限不足', 'wpbridge' ) );
        }

        $action = sanitize_text_field( $_POST['wpbridge_action'] );

        switch ( $action ) {
            case 'save_source':
                $this->handle_save_source();
                break;
            case 'delete_source':
                $this->handle_delete_source();
                break;
            case 'save_settings':
                $this->handle_save_settings();
                break;
            case 'save_api_settings':
                $this->handle_save_api_settings();
                break;
        }
    }

    /**
     * 处理保存源
     */
    private function handle_save_source(): void {
        $source_id = sanitize_text_field( $_POST['source_id'] ?? '' );

        $source = new SourceModel();
        $source->id        = $source_id;
        $source->name      = sanitize_text_field( $_POST['name'] ?? '' );
        $source->type      = sanitize_text_field( $_POST['type'] ?? SourceType::JSON );
        $source->api_url   = esc_url_raw( $_POST['api_url'] ?? '' );
        $source->slug      = sanitize_text_field( $_POST['slug'] ?? '' );
        $source->item_type = sanitize_text_field( $_POST['item_type'] ?? 'plugin' );

        // 加密存储 auth_token
        $raw_token = sanitize_text_field( $_POST['auth_token'] ?? '' );
        // 如果是占位符或空值，保留原有 token
        if ( $raw_token === '********' || empty( $raw_token ) ) {
            $existing = $this->source_manager->get( $source_id );
            $source->auth_token = $existing ? $existing->auth_token : '';
        } else {
            $source->auth_token = Encryption::encrypt( $raw_token );
        }

        $source->enabled   = ! empty( $_POST['enabled'] );
        $source->priority  = (int) ( $_POST['priority'] ?? 50 );

        // 验证优先级范围
        if ( $source->priority < 0 || $source->priority > 100 ) {
            $source->priority = 50;
        }

        // 验证
        $errors = $source->validate();

        if ( ! empty( $errors ) ) {
            $this->add_notice( 'error', implode( '<br>', $errors ) );
            return;
        }

        // 保存
        if ( empty( $source_id ) ) {
            $result = $this->source_manager->add( $source );
            $message = __( '更新源已添加', 'wpbridge' );
        } else {
            $result = $this->source_manager->update( $source );
            $message = __( '更新源已更新', 'wpbridge' );
        }

        if ( $result ) {
            $this->add_notice( 'success', $message );
            wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit;
        } else {
            $this->add_notice( 'error', __( '保存失败', 'wpbridge' ) );
        }
    }

    /**
     * 处理删除源
     */
    private function handle_delete_source(): void {
        $source_id = sanitize_text_field( $_POST['source_id'] ?? '' );

        if ( empty( $source_id ) ) {
            $this->add_notice( 'error', __( '无效的源 ID', 'wpbridge' ) );
            return;
        }

        if ( $this->source_manager->delete( $source_id ) ) {
            $this->add_notice( 'success', __( '更新源已删除', 'wpbridge' ) );
        } else {
            $this->add_notice( 'error', __( '删除失败，可能是预置源', 'wpbridge' ) );
        }

        wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    /**
     * 处理保存设置
     */
    private function handle_save_settings(): void {
        // 验证并限制请求超时范围
        $request_timeout = (int) ( $_POST['request_timeout'] ?? 10 );
        $request_timeout = max( 5, min( 60, $request_timeout ) );

        // 验证缓存 TTL
        $valid_ttls = [ 3600, 21600, 43200, 86400 ];
        $cache_ttl  = (int) ( $_POST['cache_ttl'] ?? 43200 );
        if ( ! in_array( $cache_ttl, $valid_ttls, true ) ) {
            $cache_ttl = 43200;
        }

        // 保留现有的 api 设置
        $current  = $this->settings->get_all();
        $settings = [
            'debug_mode'       => ! empty( $_POST['debug_mode'] ),
            'cache_ttl'        => $cache_ttl,
            'request_timeout'  => $request_timeout,
            'fallback_enabled' => ! empty( $_POST['fallback_enabled'] ),
            'api'              => $current['api'] ?? [],
        ];

        if ( $this->settings->update( $settings ) ) {
            $this->add_notice( 'success', __( '设置已保存', 'wpbridge' ) );
        } else {
            $this->add_notice( 'error', __( '保存失败', 'wpbridge' ) );
        }

        wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#settings' ) );
        exit;
    }

    /**
     * 处理保存 API 设置
     */
    private function handle_save_api_settings(): void {
        // 验证速率限制范围
        $rate_limit = (int) ( $_POST['rate_limit'] ?? 100 );
        $rate_limit = max( 10, min( 10000, $rate_limit ) );

        // 获取当前设置
        $current      = $this->settings->get_all();
        $api_settings = $current['api'] ?? [];

        // 更新 API 设置
        $api_settings['enabled']      = ! empty( $_POST['api_enabled'] );
        $api_settings['require_auth'] = ! empty( $_POST['require_auth'] );
        $api_settings['rate_limit']   = $rate_limit;

        // 保留现有的 keys
        if ( ! isset( $api_settings['keys'] ) ) {
            $api_settings['keys'] = [];
        }

        $current['api'] = $api_settings;

        if ( $this->settings->update( $current ) ) {
            $this->add_notice( 'success', __( 'API 设置已保存', 'wpbridge' ) );
        } else {
            $this->add_notice( 'error', __( '保存失败', 'wpbridge' ) );
        }

        wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#api' ) );
        exit;
    }

    /**
     * AJAX: 切换源状态
     */
    public function ajax_toggle_source(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $source_id = sanitize_text_field( $_POST['source_id'] ?? '' );
        $enabled   = ! empty( $_POST['enabled'] );

        if ( $this->source_manager->toggle( $source_id, $enabled ) ) {
            wp_send_json_success( [ 'message' => __( '状态已更新', 'wpbridge' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( '更新失败', 'wpbridge' ) ] );
        }
    }

    /**
     * AJAX: 测试源连通性
     */
    public function ajax_test_source(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $source_id = sanitize_text_field( $_POST['source_id'] ?? '' );
        $source    = $this->source_manager->get( $source_id );

        if ( null === $source ) {
            wp_send_json_error( [ 'message' => __( '源不存在', 'wpbridge' ) ] );
        }

        $checker = new HealthChecker();
        $status  = $checker->check( $source, true );

        wp_send_json_success( [
            'status'        => $status->status,
            'response_time' => $status->response_time,
            'error'         => $status->error,
        ] );
    }

    /**
     * AJAX: 清除缓存
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        \WPBridge\Core\Plugin::clear_all_cache();

        wp_send_json_success( [ 'message' => __( '缓存已清除', 'wpbridge' ) ] );
    }

    /**
     * AJAX: 清除日志
     */
    public function ajax_clear_logs(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        Logger::clear();

        wp_send_json_success( [ 'message' => __( '日志已清除', 'wpbridge' ) ] );
    }

    /**
     * AJAX: 生成 API Key
     */
    public function ajax_generate_api_key(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $key_name = sanitize_text_field( $_POST['key_name'] ?? '' );
        if ( empty( $key_name ) ) {
            $key_name = __( '未命名', 'wpbridge' );
        }

        // 生成随机 API Key
        $api_key = 'wpb_' . bin2hex( random_bytes( 24 ) );

        // 获取当前 API 设置
        $settings     = $this->settings->get_all();
        $api_settings = $settings['api'] ?? [];
        $keys         = $api_settings['keys'] ?? [];

        // 添加新 Key（存储哈希值）
        $keys[] = [
            'name'       => $key_name,
            'hash'       => password_hash( $api_key, PASSWORD_DEFAULT ),
            'prefix'     => substr( $api_key, 0, 8 ),
            'created_at' => time(),
            'last_used'  => 0,
        ];

        $api_settings['keys'] = $keys;
        $settings['api']      = $api_settings;

        if ( $this->settings->update( $settings ) ) {
            wp_send_json_success( [
                'message' => __( 'API Key 已生成', 'wpbridge' ),
                'api_key' => $api_key,
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( '生成失败', 'wpbridge' ) ] );
        }
    }

    /**
     * AJAX: 撤销 API Key
     */
    public function ajax_revoke_api_key(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $key_index = intval( $_POST['key_index'] ?? -1 );

        // 获取当前 API 设置
        $settings     = $this->settings->get_all();
        $api_settings = $settings['api'] ?? [];
        $keys         = $api_settings['keys'] ?? [];

        if ( ! isset( $keys[ $key_index ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Key 不存在', 'wpbridge' ) ] );
        }

        // 移除 Key
        array_splice( $keys, $key_index, 1 );

        $api_settings['keys'] = $keys;
        $settings['api']      = $api_settings;

        if ( $this->settings->update( $settings ) ) {
            wp_send_json_success( [ 'message' => __( 'API Key 已撤销', 'wpbridge' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( '撤销失败', 'wpbridge' ) ] );
        }
    }

    /**
     * 添加通知
     *
     * @param string $type    类型
     * @param string $message 消息
     */
    private function add_notice( string $type, string $message ): void {
        add_settings_error( 'wpbridge', 'wpbridge_notice', $message, $type );
    }
}
