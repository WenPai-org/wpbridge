<?php
/**
 * 管理页面
 *
 * @package WPBridge
 */

namespace WPBridge\Admin;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Core\SourceRegistry;
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
        // 方案 B: 项目配置 AJAX
        add_action( 'wp_ajax_wpbridge_set_item_source', [ $this, 'ajax_set_item_source' ] );
        add_action( 'wp_ajax_wpbridge_batch_set_source', [ $this, 'ajax_batch_set_source' ] );
        add_action( 'wp_ajax_wpbridge_save_defaults', [ $this, 'ajax_save_defaults' ] );
        add_action( 'wp_ajax_wpbridge_quick_setup_source', [ $this, 'ajax_quick_setup_source' ] );
        add_action( 'wp_ajax_wpbridge_save_item_config', [ $this, 'ajax_save_item_config' ] );
        add_action( 'wp_ajax_wpbridge_add_source', [ $this, 'ajax_add_source' ] );
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
                // 添加功能已改为内联表单，重定向到更新源 subtab
                wp_safe_redirect( admin_url( 'admin.php?page=wpbridge#projects' ) );
                exit;
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

        // "Git 仓库" 选项：根据 URL 自动识别具体平台
        if ( $source->type === 'git' ) {
            $host = wp_parse_url( $source->api_url, PHP_URL_HOST );
            if ( $host && strpos( $host, 'github.com' ) !== false ) {
                $source->type = SourceType::GITHUB;
            } elseif ( $host && strpos( $host, 'gitlab.com' ) !== false ) {
                $source->type = SourceType::GITLAB;
            } elseif ( $host && strpos( $host, 'gitee.com' ) !== false ) {
                $source->type = SourceType::GITEE;
            } else {
                $source->type = SourceType::GITHUB; // 默认按 GitHub API 处理
            }
        }

        // Bridge Server 类型自动补全 URL
        if ( $source->type === SourceType::BRIDGE_SERVER ) {
            $path = wp_parse_url( $source->api_url, PHP_URL_PATH ) ?? '';
            if ( strpos( $path, '/wp-json/bridge/v1' ) === false ) {
                $source->api_url = trailingslashit( $source->api_url ) . 'wp-json/bridge/v1/';
            }
        }
        $source->slug      = sanitize_text_field( $_POST['slug'] ?? '' );
        $source->item_type = sanitize_text_field( $_POST['item_type'] ?? 'plugin' );

        // 匹配模式
        $match_mode = sanitize_text_field( $_POST['match_mode'] ?? 'auto' );
        if ( $match_mode === 'auto' ) {
            // 清零，完全由 URL 推断
            $source->slug      = '';
            $source->item_type = 'plugin';

            $path = trim( wp_parse_url( $source->api_url, PHP_URL_PATH ) ?? '', '/' );
            // Git 仓库格式: user/repo → slug = repo
            if ( preg_match( '#([^/]+)/([^/]+?)(?:\.git)?$#', $path, $m ) ) {
                $slug_candidate = sanitize_title( $m[2] );
                if ( ! empty( $slug_candidate ) ) {
                    $source->slug = $slug_candidate;
                }
            }
            // 从 slug 推断是否为主题（含 theme 关键词）
            $slug_lower = strtolower( $source->slug . ' ' . ( $_POST['name'] ?? '' ) );
            $source->item_type = ( strpos( $slug_lower, 'theme' ) !== false ) ? 'theme' : 'plugin';
        }

        // 名称为空时从 URL 自动生成
        if ( empty( $source->name ) ) {
            if ( ! empty( $source->slug ) ) {
                $source->name = ucwords( str_replace( [ '-', '_' ], ' ', $source->slug ) );
            } else {
                $parsed_host  = wp_parse_url( $source->api_url, PHP_URL_HOST );
                $source->name = $parsed_host ?: __( '自定义源', 'wpbridge' );
            }
        }

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

        // 处理语义化优先级选项
        $priority_level = sanitize_text_field( $_POST['priority_level'] ?? 'secondary' );
        $priority_map   = [
            'primary'   => 10,  // 首选源
            'secondary' => 50,  // 备选源
            'fallback'  => 90,  // 最后选择
        ];
        $source->priority = $priority_map[ $priority_level ] ?? 50;

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
            $this->sync_source_to_registry( $source );
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
            $registry = new SourceRegistry();
            $registry->delete( $source_id );
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
            'debug_mode'       => ( $_POST['debug_mode'] ?? '' ) === '1' || ( $_POST['debug_mode'] ?? '' ) === 'on',
            'cache_ttl'        => $cache_ttl,
            'request_timeout'  => $request_timeout,
            'backup_enabled'   => ! empty( $_POST['backup_enabled'] ),
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
     * AJAX: 添加更新源（简化版，仅 URL + token）
     */
    public function ajax_add_source(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $api_url   = esc_url_raw( wp_unslash( $_POST['api_url'] ?? '' ) );
        $raw_token = sanitize_text_field( wp_unslash( $_POST['auth_token'] ?? '' ) );

        if ( empty( $api_url ) ) {
            wp_send_json_error( [ 'message' => __( '请输入更新源地址', 'wpbridge' ) ] );
        }

        // 自动识别类型
        $host = wp_parse_url( $api_url, PHP_URL_HOST );
        $path = wp_parse_url( $api_url, PHP_URL_PATH ) ?? '';
        if ( strpos( $path, '/wp-json/bridge/v1' ) !== false ) {
            $type = SourceType::BRIDGE_SERVER;
        } elseif ( $host && strpos( $host, 'github.com' ) !== false ) {
            $type = SourceType::GITHUB;
        } elseif ( $host && strpos( $host, 'gitlab.com' ) !== false ) {
            $type = SourceType::GITLAB;
        } elseif ( $host && strpos( $host, 'gitee.com' ) !== false ) {
            $type = SourceType::GITEE;
        } elseif ( $host && strpos( $host, 'feicode.com' ) !== false ) {
            $type = 'wenpai_git';
        } elseif ( preg_match( '/\.zip$/i', wp_parse_url( $api_url, PHP_URL_PATH ) ?? '' ) ) {
            $type = SourceType::ZIP;
        } else {
            $type = SourceType::JSON;
        }

        $source           = new SourceModel();
        $source->type     = $type;
        $source->api_url  = $api_url;
        $source->enabled  = true;
        $source->priority = 50;

        // 自动提取 slug
        $path = trim( wp_parse_url( $api_url, PHP_URL_PATH ) ?? '', '/' );
        if ( preg_match( '#([^/]+)/([^/]+?)(?:\.git)?$#', $path, $m ) ) {
            $slug_candidate = sanitize_title( $m[2] );
            if ( ! empty( $slug_candidate ) ) {
                $source->slug = $slug_candidate;
            }
        }

        // 自动生成名称
        if ( $type === SourceType::BRIDGE_SERVER ) {
            $source->name = $host ? sprintf( __( 'Bridge: %s', 'wpbridge' ), $host ) : __( 'Bridge Server', 'wpbridge' );
        } elseif ( ! empty( $source->slug ) ) {
            $source->name = ucwords( str_replace( [ '-', '_' ], ' ', $source->slug ) );
        } else {
            $source->name = $host ?: __( '自定义源', 'wpbridge' );
        }

        // 推断 item_type
        $slug_lower = strtolower( $source->slug . ' ' . $source->name );
        $source->item_type = ( strpos( $slug_lower, 'theme' ) !== false ) ? 'theme' : 'plugin';

        // 加密 token
        if ( ! empty( $raw_token ) ) {
            $source->auth_token = Encryption::encrypt( $raw_token );
        }

        // 验证
        $errors = $source->validate();
        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => implode( ', ', $errors ) ] );
        }

        // 保存
        $result = $this->source_manager->save( $source );
        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( '保存失败', 'wpbridge' ) ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: source name */
                __( '已添加更新源：%s', 'wpbridge' ),
                $source->name
            ),
            'source'  => [
                'id'   => $source->id,
                'name' => $source->name,
                'type' => $source->type,
            ],
        ] );
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

        try {
            // 生成随机 API Key
            $api_key = 'wpb_' . bin2hex( random_bytes( 24 ) );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => __( '生成随机数失败', 'wpbridge' ) ] );
        }

        // 生成唯一 ID
        $key_id = 'key_' . wp_generate_uuid4();

        // 获取当前 API 设置
        $settings     = $this->settings->get_all();
        $api_settings = $settings['api'] ?? [];
        $keys         = $api_settings['keys'] ?? [];

        // 添加新 Key（使用与 ApiKeyManager 一致的字段名）
        $keys[] = [
            'id'         => $key_id,
            'name'       => $key_name,
            'key_hash'   => password_hash( $api_key, PASSWORD_DEFAULT ),
            'key_prefix' => substr( $api_key, 0, 4 ) . '...' . substr( $api_key, -4 ),
            'created_at' => current_time( 'mysql' ),
            'last_used'  => null,
        ];

        $api_settings['keys'] = $keys;
        $settings['api']      = $api_settings;

        if ( $this->settings->update( $settings ) ) {
            wp_send_json_success( [
                'message' => __( 'API Key 已生成', 'wpbridge' ),
                'api_key' => $api_key,
                'key_id'  => $key_id,
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

        $key_id = sanitize_text_field( $_POST['key_id'] ?? '' );

        if ( empty( $key_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Key ID 不能为空', 'wpbridge' ) ] );
        }

        // 获取当前 API 设置
        $settings     = $this->settings->get_all();
        $api_settings = $settings['api'] ?? [];
        $keys         = $api_settings['keys'] ?? [];

        // 通过 ID 查找并移除 Key
        $found = false;
        foreach ( $keys as $index => $key ) {
            if ( isset( $key['id'] ) && $key['id'] === $key_id ) {
                array_splice( $keys, $index, 1 );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => __( 'Key 不存在', 'wpbridge' ) ] );
        }

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

    /**
     * 同步更新源到 SourceRegistry（方案 B）
     *
     * @param SourceModel $source 源模型
     */
    private function sync_source_to_registry( SourceModel $source ): void {
        $registry = new SourceRegistry();
        $existing = $registry->get( $source->id );

        $type_map = [
            SourceType::JSON        => SourceRegistry::TYPE_JSON,
            SourceType::GITHUB      => SourceRegistry::TYPE_GIT,
            SourceType::GITLAB      => SourceRegistry::TYPE_GIT,
            SourceType::GITEE       => SourceRegistry::TYPE_GIT,
            SourceType::WENPAI_GIT  => SourceRegistry::TYPE_GIT,
            SourceType::ZIP         => SourceRegistry::TYPE_CUSTOM,
            SourceType::ARKPRESS    => SourceRegistry::TYPE_ARKPRESS,
            SourceType::ASPIRECLOUD => SourceRegistry::TYPE_CUSTOM,
            SourceType::FAIR        => SourceRegistry::TYPE_FAIR,
            SourceType::PUC         => SourceRegistry::TYPE_CUSTOM,
        ];

        $base_url = '';
        $parsed   = wp_parse_url( $source->api_url );
        if ( ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) ) {
            $base_url = $parsed['scheme'] . '://' . $parsed['host'];
            if ( ! empty( $parsed['port'] ) ) {
                $base_url .= ':' . $parsed['port'];
            }
        }

        $auth_type       = $existing['auth_type'] ?? SourceRegistry::AUTH_NONE;
        $auth_secret_ref = $existing['auth_secret_ref'] ?? '';

        $token = $this->decrypt_auth_token( $source->auth_token );
        if ( '' !== $token ) {
            if ( empty( $auth_secret_ref ) ) {
                $auth_secret_ref = 'secret_' . wp_generate_uuid4();
            }
            update_option( 'wpbridge_secret_' . $auth_secret_ref, $token, false );
            $auth_type = SourceRegistry::AUTH_TOKEN;
        }

        $data = [
            'name'             => $source->name,
            'type'             => $type_map[ $source->type ] ?? SourceRegistry::TYPE_CUSTOM,
            'base_url'         => $base_url,
            'api_url'          => $source->api_url,
            'enabled'          => $source->enabled,
            'default_priority' => $source->priority,
            'auth_type'        => $auth_type,
            'auth_secret_ref'  => $auth_secret_ref,
            'headers'          => [],
            'is_preset'        => false,
        ];

        if ( $existing ) {
            $registry->update( $source->id, $data );
        } else {
            $data['source_key'] = $source->id;
            $registry->add( $data );
        }
    }

    /**
     * 解密认证令牌
     *
     * @param string $token 加密令牌
     * @return string
     */
    private function decrypt_auth_token( string $token ): string {
        if ( empty( $token ) ) {
            return '';
        }

        $decrypted = Encryption::decrypt( $token );
        if ( ! empty( $decrypted ) ) {
            return $decrypted;
        }

        if ( ! Encryption::is_encrypted( $token ) ) {
            return $token;
        }

        return '';
    }

    /**
     * AJAX: 设置项目更新源
     */
    public function ajax_set_item_source(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $item_key   = sanitize_text_field( $_POST['item_key'] ?? '' );
        $source_key = sanitize_text_field( $_POST['source_key'] ?? '' );

        if ( empty( $item_key ) ) {
            wp_send_json_error( [ 'message' => __( '项目键不能为空', 'wpbridge' ) ] );
        }

        $source_registry = new \WPBridge\Core\SourceRegistry();
        $item_manager    = new \WPBridge\Core\ItemSourceManager( $source_registry );

        $result = false;

        if ( $source_key === 'default' ) {
            // 重置为默认
            $result = $item_manager->delete( $item_key );
            if ( ! $result ) {
                // 如果删除失败（可能不存在），也算成功
                $result = true;
            }
        } elseif ( $source_key === 'disabled' ) {
            // 禁用更新
            $result = $item_manager->disable_updates( $item_key );
        } else {
            // 设置自定义源
            $result = $item_manager->set_source( $item_key, $source_key );
        }

        if ( $result ) {
            wp_send_json_success( [ 'message' => __( '配置已保存', 'wpbridge' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( '保存失败', 'wpbridge' ) ] );
        }
    }

    /**
     * AJAX: 批量设置更新源
     */
    public function ajax_batch_set_source(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $item_keys  = isset( $_POST['item_keys'] ) ? array_map( 'sanitize_text_field', (array) $_POST['item_keys'] ) : [];
        $action     = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        $source_key = sanitize_text_field( $_POST['source_key'] ?? '' );

        if ( empty( $item_keys ) ) {
            wp_send_json_error( [ 'message' => __( '请选择项目', 'wpbridge' ) ] );
        }

        // 白名单验证操作类型
        $allowed_actions = [ 'set_source', 'reset_default', 'disable' ];
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            wp_send_json_error( [ 'message' => __( '无效操作', 'wpbridge' ) ] );
        }

        $source_registry = new \WPBridge\Core\SourceRegistry();
        $item_manager    = new \WPBridge\Core\ItemSourceManager( $source_registry );

        $success = 0;

        foreach ( $item_keys as $item_key ) {
            $result = false;

            switch ( $action ) {
                case 'set_source':
                    if ( ! empty( $source_key ) ) {
                        $result = $item_manager->set_source( $item_key, $source_key );
                    }
                    break;

                case 'reset_default':
                    $result = $item_manager->delete( $item_key );
                    if ( ! $result ) {
                        $result = true; // 不存在也算成功
                    }
                    break;

                case 'disable':
                    $result = $item_manager->disable_updates( $item_key );
                    break;
            }

            if ( $result ) {
                $success++;
            }
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of items */
                __( '已更新 %d 个项目', 'wpbridge' ),
                $success
            ),
            'count'   => $success,
        ] );
    }

    /**
     * AJAX: 保存默认规则
     */
    public function ajax_save_defaults(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $defaults_manager = new \WPBridge\Core\DefaultsManager();

        // 全局默认 - 清理输入
        $global_sources = isset( $_POST['global_sources'] )
            ? array_map( 'sanitize_text_field', array_keys( (array) $_POST['global_sources'] ) )
            : [];
        $defaults_manager->set_source_order( \WPBridge\Core\DefaultsManager::SCOPE_GLOBAL, $global_sources );

        // 插件默认
        if ( ! empty( $_POST['plugin_override'] ) ) {
            $plugin_sources = isset( $_POST['plugin_sources'] )
                ? array_map( 'sanitize_text_field', array_keys( (array) $_POST['plugin_sources'] ) )
                : [];
            $defaults_manager->set_source_order( \WPBridge\Core\DefaultsManager::SCOPE_PLUGIN, $plugin_sources );
        } else {
            $defaults_manager->set_source_order( \WPBridge\Core\DefaultsManager::SCOPE_PLUGIN, [] );
        }

        // 主题默认
        if ( ! empty( $_POST['theme_override'] ) ) {
            $theme_sources = isset( $_POST['theme_sources'] )
                ? array_map( 'sanitize_text_field', array_keys( (array) $_POST['theme_sources'] ) )
                : [];
            $defaults_manager->set_source_order( \WPBridge\Core\DefaultsManager::SCOPE_THEME, $theme_sources );
        } else {
            $defaults_manager->set_source_order( \WPBridge\Core\DefaultsManager::SCOPE_THEME, [] );
        }

        wp_send_json_success( [ 'message' => __( '默认规则已保存', 'wpbridge' ) ] );
    }

    /**
     * AJAX: 快速设置更新源 (P1)
     *
     * 允许用户直接输入 URL，自动创建内联源并关联到项目
     */
    public function ajax_quick_setup_source(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $item_key = sanitize_text_field( $_POST['item_key'] ?? '' );
        $url      = esc_url_raw( $_POST['url'] ?? '' );
        $type     = sanitize_text_field( $_POST['type'] ?? 'json' );
        $name     = sanitize_text_field( $_POST['name'] ?? '' );
        $token    = sanitize_text_field( $_POST['token'] ?? '' );

        if ( empty( $item_key ) || empty( $url ) ) {
            wp_send_json_error( [ 'message' => __( '缺少必要参数', 'wpbridge' ) ] );
        }

        // 验证 URL 格式
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => __( '无效的 URL 格式', 'wpbridge' ) ] );
        }

        // 验证 URL 协议（防止 javascript: 或 data: URL）
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'URL 必须使用 http 或 https 协议', 'wpbridge' ) ] );
        }

        // "Git 仓库" 选项：根据 URL 自动识别具体平台
        if ( $type === 'git' ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( $host && strpos( $host, 'github.com' ) !== false ) {
                $type = 'github';
            } elseif ( $host && strpos( $host, 'gitlab.com' ) !== false ) {
                $type = 'gitlab';
            } elseif ( $host && strpos( $host, 'gitee.com' ) !== false ) {
                $type = 'gitee';
            } else {
                $type = 'github';
            }
        }

        // 验证源类型白名单
        $allowed_types = [ 'json', 'github', 'gitlab', 'gitee', 'wenpai_git', 'zip', 'arkpress', 'aspirecloud', 'fair', 'puc' ];
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'json';
        }

        // 生成唯一的源 key（内联源使用 inline_ 前缀）
        $source_key = 'inline_' . md5( $item_key . '_' . $url );

        // 如果名称为空，从 URL 生成
        if ( empty( $name ) ) {
            $parsed_url = wp_parse_url( $url );
            $name       = $parsed_url['host'] ?? 'Custom Source';
        }

        // 创建内联源
        $source = new SourceModel();
        $source->id        = $source_key;
        $source->name      = $name;
        $source->type      = $type;
        $source->api_url   = $url;
        $source->enabled   = true;
        $source->priority  = 10; // 首选源
        $source->is_inline = true; // 标记为内联源

        // 如果提供了 token，加密存储
        if ( ! empty( $token ) ) {
            $source->auth_token = Encryption::encrypt( $token );
        }

        // 从 item_key 推断项目类型
        if ( strpos( $item_key, 'plugin:' ) === 0 ) {
            $source->item_type = 'plugin';
            $source->slug      = str_replace( 'plugin:', '', $item_key );
        } elseif ( strpos( $item_key, 'theme:' ) === 0 ) {
            $source->item_type = 'theme';
            $source->slug      = str_replace( 'theme:', '', $item_key );
        }

        // 保存源
        $result = $this->source_manager->add( $source );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( '创建更新源失败', 'wpbridge' ) ] );
        }

        $this->sync_source_to_registry( $source );

        // 关联到项目
        $source_registry = new SourceRegistry();
        $item_manager    = new \WPBridge\Core\ItemSourceManager( $source_registry );
        $item_manager->set( $item_key, [
            'mode'       => \WPBridge\Core\ItemSourceManager::MODE_CUSTOM,
            'source_ids' => [ $source_key => 10 ],
        ] );

        wp_send_json_success( [
            'message'    => __( '更新源已设置', 'wpbridge' ),
            'source_key' => $source_key,
        ] );
    }

    /**
     * AJAX: 保存项目配置 (统一接口)
     *
     * 支持三种模式：default, custom, disabled
     */
    public function ajax_save_item_config(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
        }

        $item_key = sanitize_text_field( $_POST['item_key'] ?? '' );
        $mode     = sanitize_text_field( $_POST['mode'] ?? 'default' );

        if ( empty( $item_key ) ) {
            wp_send_json_error( [ 'message' => __( '缺少必要参数', 'wpbridge' ) ] );
        }

        // 验证模式白名单
        $allowed_modes = [ 'default', 'custom', 'disabled' ];
        if ( ! in_array( $mode, $allowed_modes, true ) ) {
            wp_send_json_error( [ 'message' => __( '无效的模式', 'wpbridge' ) ] );
        }

        $source_registry = new \WPBridge\Core\SourceRegistry();
        $item_manager    = new \WPBridge\Core\ItemSourceManager( $source_registry );

        $result = false;

        switch ( $mode ) {
            case 'default':
                // 重置为默认
                $result = $item_manager->delete( $item_key );
                if ( ! $result ) {
                    $result = true; // 如果不存在也算成功
                }
                break;

            case 'disabled':
                // 禁用更新
                $result = $item_manager->disable_updates( $item_key );
                break;

            case 'custom':
                // 自定义源 - 需要 URL
                $url   = esc_url_raw( $_POST['url'] ?? '' );
                $type  = sanitize_text_field( $_POST['type'] ?? 'json' );
                $name  = sanitize_text_field( $_POST['name'] ?? '' );
                $token = sanitize_text_field( $_POST['token'] ?? '' );

                if ( empty( $url ) ) {
                    wp_send_json_error( [ 'message' => __( '请输入更新地址', 'wpbridge' ) ] );
                }

                // 验证 URL 格式
                if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    wp_send_json_error( [ 'message' => __( '无效的 URL 格式', 'wpbridge' ) ] );
                }

                // 验证 URL 协议
                $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
                if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
                    wp_send_json_error( [ 'message' => __( 'URL 必须使用 http 或 https 协议', 'wpbridge' ) ] );
                }

                // 验证源类型白名单
                $allowed_types = [ 'json', 'github', 'gitlab', 'gitee', 'wenpai_git', 'zip', 'arkpress', 'aspirecloud', 'fair', 'puc' ];
                if ( ! in_array( $type, $allowed_types, true ) ) {
                    $type = 'json';
                }

                // 生成唯一的源 key
                $source_key = 'inline_' . md5( $item_key . '_' . $url );

                // 如果名称为空，从 URL 生成
                if ( empty( $name ) ) {
                    $parsed_url = wp_parse_url( $url );
                    $name       = $parsed_url['host'] ?? 'Custom Source';
                }

                // 创建内联源
                $source = new SourceModel();
                $source->id        = $source_key;
                $source->name      = $name;
                $source->type      = $type;
                $source->api_url   = $url;
                $source->enabled   = true;
                $source->priority  = 10;
                $source->is_inline = true;

                if ( ! empty( $token ) ) {
                    $source->auth_token = Encryption::encrypt( $token );
                }

                // 从 item_key 推断项目类型
                if ( strpos( $item_key, 'plugin:' ) === 0 ) {
                    $source->item_type = 'plugin';
                    $source->slug      = str_replace( 'plugin:', '', $item_key );
                } elseif ( strpos( $item_key, 'theme:' ) === 0 ) {
                    $source->item_type = 'theme';
                    $source->slug      = str_replace( 'theme:', '', $item_key );
                }

                // 保存源
                $save_result = $this->source_manager->add( $source );

                if ( ! $save_result ) {
                    wp_send_json_error( [ 'message' => __( '创建更新源失败', 'wpbridge' ) ] );
                }

                $this->sync_source_to_registry( $source );

                // 关联到项目
                $result = $item_manager->set( $item_key, [
                    'mode'       => \WPBridge\Core\ItemSourceManager::MODE_CUSTOM,
                    'source_ids' => [ $source_key => 10 ],
                ] );
                break;
        }

        if ( $result ) {
            wp_send_json_success( [ 'message' => __( '配置已保存', 'wpbridge' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( '保存失败', 'wpbridge' ) ] );
        }
    }
}
