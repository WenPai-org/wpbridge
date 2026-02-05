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
     * 源注册表（方案 B）
     *
     * @var SourceRegistry|null
     */
    private ?SourceRegistry $source_registry = null;

    /**
     * 项目配置管理器（方案 B）
     *
     * @var ItemSourceManager|null
     */
    private ?ItemSourceManager $item_manager = null;

    /**
     * 默认规则管理器（方案 B）
     *
     * @var DefaultsManager|null
     */
    private ?DefaultsManager $defaults_manager = null;

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
     * 商业插件检测器
     *
     * @var CommercialDetector|null
     */
    private ?CommercialDetector $commercial_detector = null;

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

        // 初始化方案 B 数据模型
        $this->source_registry  = new SourceRegistry();
        $this->defaults_manager = new DefaultsManager();
        $this->item_manager     = new ItemSourceManager( $this->source_registry );

        // 检查并执行迁移
        $this->maybe_migrate();

        $this->init_hooks();
    }

    /**
     * 检查并执行数据迁移
     */
    private function maybe_migrate(): void {
        $migration = new MigrationManager(
            $this->source_registry,
            $this->item_manager,
            $this->defaults_manager
        );

        if ( $migration->needs_migration() ) {
            $result = $migration->migrate();
            if ( ! $result['success'] ) {
                // 记录迁移失败
                Logger::error( '数据迁移失败', [ 'log' => $result['log'] ] );
            }
        }
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

            // AJAX 处理
            add_action( 'wp_ajax_wpbridge_set_plugin_type', [ $this, 'ajax_set_plugin_type' ] );
            add_action( 'wp_ajax_wpbridge_refresh_commercial_detection', [ $this, 'ajax_refresh_commercial_detection' ] );
            add_action( 'wp_ajax_wpbridge_prepare_refresh', [ $this, 'ajax_prepare_refresh' ] );
            add_action( 'wp_ajax_wpbridge_refresh_batch', [ $this, 'ajax_refresh_batch' ] );
            add_action( 'wp_ajax_wpbridge_export_config', [ $this, 'ajax_export_config' ] );
            add_action( 'wp_ajax_wpbridge_import_config', [ $this, 'ajax_import_config' ] );
            add_action( 'wp_ajax_wpbridge_lock_version', [ $this, 'ajax_lock_version' ] );
            add_action( 'wp_ajax_wpbridge_unlock_version', [ $this, 'ajax_unlock_version' ] );
            add_action( 'wp_ajax_wpbridge_rollback', [ $this, 'ajax_rollback' ] );
            add_action( 'wp_ajax_wpbridge_get_backups', [ $this, 'ajax_get_backups' ] );
            add_action( 'wp_ajax_wpbridge_get_changelog', [ $this, 'ajax_get_changelog' ] );
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
        $this->commercial_detector  = CommercialDetector::get_instance();

        // 初始化版本锁定
        VersionLock::get_instance();

        // 初始化备份管理器
        BackupManager::get_instance();

        // 初始化 Site Health 集成
        new SiteHealth( $this->settings );

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
                'confirm_delete'       => __( '确定要删除这个更新源吗？', 'wpbridge' ),
                'confirm_revoke'       => __( '确定要撤销此 API Key 吗？', 'wpbridge' ),
                'confirm_clear_logs'   => __( '确定要清除所有日志吗？', 'wpbridge' ),
                'testing'              => __( '测试中...', 'wpbridge' ),
                'success'              => __( '成功', 'wpbridge' ),
                'failed'               => __( '操作失败', 'wpbridge' ),
                'enabled'              => __( '已启用', 'wpbridge' ),
                'disabled'             => __( '已禁用', 'wpbridge' ),
                'healthy'              => __( '正常', 'wpbridge' ),
                'degraded'             => __( '降级', 'wpbridge' ),
                'failed_status'        => __( '失败', 'wpbridge' ),
                'test_success'         => __( '连接成功', 'wpbridge' ),
                'test_degraded'        => __( '连接异常', 'wpbridge' ),
                'cache_cleared'        => __( '缓存已清除', 'wpbridge' ),
                'logs_cleared'         => __( '日志已清除', 'wpbridge' ),
                'no_logs'              => __( '暂无日志记录', 'wpbridge' ),
                'enter_key_name'       => __( '请输入 API Key 名称：', 'wpbridge' ),
                'key_generated'        => __( 'API Key 已生成，请妥善保存：', 'wpbridge' ),
                'key_warning'          => __( '此 Key 只会显示一次，请立即复制保存。', 'wpbridge' ),
                'key_revoked'          => __( 'API Key 已撤销', 'wpbridge' ),
                // 诊断工具相关
                'close_notice'         => __( '关闭此通知', 'wpbridge' ),
                'copied'               => __( '已复制到剪贴板', 'wpbridge' ),
                'diagnostics_complete' => __( '诊断完成', 'wpbridge' ),
                'environment_ok'       => __( '环境检查已完成', 'wpbridge' ),
                'diagnostics_report'   => __( 'WPBridge 诊断报告', 'wpbridge' ),
                'generated_at'         => __( '生成时间', 'wpbridge' ),
                'system_info'          => __( '系统信息', 'wpbridge' ),
                'environment_check'    => __( '环境检查', 'wpbridge' ),
                'config_check'         => __( '配置检查', 'wpbridge' ),
                'source_status'        => __( '更新源状态', 'wpbridge' ),
                'passed'               => __( '通过', 'wpbridge' ),
                'warning'              => __( '警告', 'wpbridge' ),
                'not_tested'           => __( '未测试', 'wpbridge' ),
                'status'               => __( '状态', 'wpbridge' ),
                // 插件类型相关
                'type_free'            => __( '免费', 'wpbridge' ),
                'type_commercial'      => __( '商业', 'wpbridge' ),
                'type_private'         => __( '私有', 'wpbridge' ),
                'type_unknown'         => __( '第三方', 'wpbridge' ),
                'type_saved'           => __( '插件类型已保存', 'wpbridge' ),
                'manual_mark'          => __( '手动标记', 'wpbridge' ),
                'manual_marked'        => __( '当前为手动标记', 'wpbridge' ),
                // 配置导入导出
                'config_exported'      => __( '配置已导出', 'wpbridge' ),
                'config_imported'      => __( '配置已导入', 'wpbridge' ),
                'import_failed'        => __( '导入失败', 'wpbridge' ),
                'invalid_file'         => __( '无效的配置文件', 'wpbridge' ),
                'confirm_import'       => __( '确定要导入配置吗？这将覆盖当前设置。', 'wpbridge' ),
                // 版本锁定
                'version_locked'       => __( '版本已锁定', 'wpbridge' ),
                'version_unlocked'     => __( '版本已解锁', 'wpbridge' ),
                'lock_current'         => __( '锁定当前版本', 'wpbridge' ),
                'lock_specific'        => __( '锁定指定版本', 'wpbridge' ),
                'lock_ignore'          => __( '忽略特定版本', 'wpbridge' ),
                'confirm_unlock'       => __( '确定要解锁此版本吗？', 'wpbridge' ),
                // 备份回滚
                'rollback_success'     => __( '回滚成功', 'wpbridge' ),
                'rollback_failed'      => __( '回滚失败', 'wpbridge' ),
                'confirm_rollback'     => __( '确定要回滚到此版本吗？当前版本将被覆盖。', 'wpbridge' ),
                'no_backups'           => __( '暂无备份', 'wpbridge' ),
                // 更新日志
                'changelog_title'      => __( '更新日志', 'wpbridge' ),
                'changelog_error'      => __( '获取更新日志失败', 'wpbridge' ),
                'loading'              => __( '加载中...', 'wpbridge' ),
                'last_updated'         => __( '最后更新', 'wpbridge' ),
                'recent_versions'      => __( '最近版本', 'wpbridge' ),
                'no_changelog'         => __( '暂无更新日志', 'wpbridge' ),
                // 模态框通用
                'confirm_title'        => __( '确认操作', 'wpbridge' ),
                'confirm_btn'          => __( '确定', 'wpbridge' ),
                'cancel_btn'           => __( '取消', 'wpbridge' ),
                'delete_btn'           => __( '删除', 'wpbridge' ),
                'copy'                 => __( '复制', 'wpbridge' ),
                // 删除更新源
                'confirm_delete_title' => __( '删除更新源', 'wpbridge' ),
                // API Key
                'generate_api_key'     => __( '生成 API Key', 'wpbridge' ),
                'key_name_placeholder' => __( '例如：我的应用', 'wpbridge' ),
                'key_name_required'    => __( '请输入名称', 'wpbridge' ),
                'key_generated_title'  => __( 'API Key 已生成', 'wpbridge' ),
                'revoke_key_title'     => __( '撤销 API Key', 'wpbridge' ),
                'revoke_btn'           => __( '撤销', 'wpbridge' ),
                // 清除日志
                'clear_logs_title'     => __( '清除日志', 'wpbridge' ),
                'clear_btn'            => __( '清除', 'wpbridge' ),
                // 批量操作
                'bulk_action_title'    => __( '批量操作', 'wpbridge' ),
                'confirm_bulk_action'  => __( '确定要对选中的 {count} 个项目执行"{action}"操作吗？', 'wpbridge' ),
                'action_set_source'    => __( '设置更新源', 'wpbridge' ),
                'action_reset'         => __( '重置为默认', 'wpbridge' ),
                'action_disable'       => __( '禁用更新', 'wpbridge' ),
                // 导入配置
                'import_config_title'  => __( '导入配置', 'wpbridge' ),
                'import_btn'           => __( '导入', 'wpbridge' ),
                // 解锁版本
                'unlock_version_title' => __( '解锁版本', 'wpbridge' ),
                'unlock_btn'           => __( '解锁', 'wpbridge' ),
                // 异步检测
                'no_plugins'           => __( '没有插件需要检测', 'wpbridge' ),
                'detecting'            => __( '正在检测插件...', 'wpbridge' ),
                'detection_complete'   => __( '检测完成', 'wpbridge' ),
                'progress'             => __( '检测进度', 'wpbridge' ),
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
     * 获取商业插件检测器
     *
     * @return CommercialDetector|null
     */
    public function get_commercial_detector(): ?CommercialDetector {
        return $this->commercial_detector;
    }

    /**
     * AJAX: 设置插件类型
     */
    public function ajax_set_plugin_type(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) ) : '';
        $plugin_type = isset( $_POST['plugin_type'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_type'] ) ) : '';

        if ( empty( $plugin_slug ) ) {
            wp_send_json_error( array( 'message' => __( '插件标识不能为空', 'wpbridge' ) ) );
        }

        if ( empty( $plugin_type ) ) {
            wp_send_json_error( array( 'message' => __( '插件类型不能为空', 'wpbridge' ) ) );
        }

        $detector = CommercialDetector::get_instance();
        $result   = $detector->set_user_mark( $plugin_slug, $plugin_type );

        if ( $result ) {
            wp_send_json_success( array(
                'message' => __( '插件类型已保存', 'wpbridge' ),
                'type'    => $plugin_type,
                'label'   => CommercialDetector::get_type_label( $plugin_type ),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( '保存失败', 'wpbridge' ) ) );
        }
    }

    /**
     * AJAX: 刷新商业插件检测
     */
    public function ajax_refresh_commercial_detection(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $detector = CommercialDetector::get_instance();
        $results  = $detector->refresh_all();

        $stats = array(
            'total'      => count( $results ),
            'free'       => 0,
            'commercial' => 0,
            'private'    => 0,
            'unknown'    => 0,
        );

        foreach ( $results as $result ) {
            if ( isset( $stats[ $result['type'] ] ) ) {
                $stats[ $result['type'] ]++;
            }
        }

        wp_send_json_success( array(
            'message' => sprintf(
                __( '已重新检测 %d 个插件：%d 免费，%d 商业，%d 第三方', 'wpbridge' ),
                $stats['total'],
                $stats['free'],
                $stats['commercial'],
                $stats['unknown'] + $stats['private']
            ),
            'stats'   => $stats,
        ) );
    }

    /**
     * AJAX: 准备刷新检测（返回插件列表）
     */
    public function ajax_prepare_refresh(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $detector = CommercialDetector::get_instance();
        $data     = $detector->prepare_refresh();

        wp_send_json_success( $data );
    }

    /**
     * AJAX: 批量检测插件
     */
    public function ajax_refresh_batch(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        if ( empty( $_POST['plugins'] ) ) {
            wp_send_json_error( array( 'message' => __( '插件列表为空', 'wpbridge' ) ) );
        }

        $plugins = json_decode( wp_unslash( $_POST['plugins'] ), true );

        if ( ! is_array( $plugins ) ) {
            wp_send_json_error( array( 'message' => __( '插件列表格式无效', 'wpbridge' ) ) );
        }

        // 验证并清理每个插件数据
        $sanitized_plugins = array();
        foreach ( $plugins as $plugin ) {
            if ( ! isset( $plugin['slug'] ) || ! isset( $plugin['file'] ) ) {
                continue;
            }
            $sanitized_plugins[] = array(
                'slug' => sanitize_text_field( $plugin['slug'] ),
                'file' => sanitize_text_field( $plugin['file'] ),
            );
        }

        if ( empty( $sanitized_plugins ) ) {
            wp_send_json_error( array( 'message' => __( '没有有效的插件数据', 'wpbridge' ) ) );
        }

        $detector = CommercialDetector::get_instance();
        $results  = $detector->refresh_batch( $sanitized_plugins );

        wp_send_json_success( array( 'results' => $results ) );
    }

    /**
     * AJAX: 导出配置
     */
    public function ajax_export_config(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $include_secrets = isset( $_POST['include_secrets'] ) && 'true' === $_POST['include_secrets'];

        $config_manager = new ConfigManager();
        $config         = $config_manager->export( $include_secrets );

        wp_send_json_success( array(
            'config'   => $config,
            'filename' => 'wpbridge-config-' . gmdate( 'Y-m-d' ) . '.json',
        ) );
    }

    /**
     * AJAX: 导入配置
     */
    public function ajax_import_config(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        if ( empty( $_POST['config'] ) ) {
            wp_send_json_error( array( 'message' => __( '配置数据为空', 'wpbridge' ) ) );
        }

        $config = json_decode( wp_unslash( $_POST['config'] ), true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array( 'message' => __( 'JSON 格式无效', 'wpbridge' ) ) );
        }

        $merge = isset( $_POST['merge'] ) && 'true' === $_POST['merge'];

        $config_manager = new ConfigManager();
        $result         = $config_manager->import( $config, $merge );

        if ( $result['success'] ) {
            // 清除设置缓存
            $this->settings->clear_cache();

            wp_send_json_success( array(
                'message'  => sprintf(
                    __( '成功导入 %d 项配置', 'wpbridge' ),
                    count( $result['imported'] )
                ),
                'imported' => $result['imported'],
                'skipped'  => $result['skipped'],
            ) );
        } else {
            wp_send_json_error( array(
                'message' => implode( ', ', $result['errors'] ),
                'errors'  => $result['errors'],
            ) );
        }
    }

    /**
     * AJAX: 锁定版本
     */
    public function ajax_lock_version(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $item_key  = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';
        $lock_type = isset( $_POST['lock_type'] ) ? sanitize_text_field( wp_unslash( $_POST['lock_type'] ) ) : '';
        $version   = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

        if ( empty( $item_key ) || empty( $lock_type ) ) {
            wp_send_json_error( array( 'message' => __( '参数不完整', 'wpbridge' ) ) );
        }

        $version_lock = VersionLock::get_instance();

        if ( $version_lock->lock( $item_key, $lock_type, $version ) ) {
            // 清除更新缓存
            delete_site_transient( 'update_plugins' );
            delete_site_transient( 'update_themes' );

            wp_send_json_success( array(
                'message' => __( '版本已锁定', 'wpbridge' ),
                'lock'    => $version_lock->get( $item_key ),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( '锁定失败', 'wpbridge' ) ) );
        }
    }

    /**
     * AJAX: 解锁版本
     */
    public function ajax_unlock_version(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $item_key = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';

        if ( empty( $item_key ) ) {
            wp_send_json_error( array( 'message' => __( '参数不完整', 'wpbridge' ) ) );
        }

        $version_lock = VersionLock::get_instance();

        if ( $version_lock->unlock( $item_key ) ) {
            // 清除更新缓存
            delete_site_transient( 'update_plugins' );
            delete_site_transient( 'update_themes' );

            wp_send_json_success( array(
                'message' => __( '版本已解锁', 'wpbridge' ),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( '解锁失败', 'wpbridge' ) ) );
        }
    }

    /**
     * AJAX: 回滚到备份
     */
    public function ajax_rollback(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $item_key  = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';
        $backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';

        if ( empty( $item_key ) || empty( $backup_id ) ) {
            wp_send_json_error( array( 'message' => __( '参数不完整', 'wpbridge' ) ) );
        }

        $backup_manager = BackupManager::get_instance();
        $result = $backup_manager->rollback( $item_key, $backup_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( '回滚成功', 'wpbridge' ),
        ) );
    }

    /**
     * AJAX: 获取备份列表
     */
    public function ajax_get_backups(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $item_key = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';

        if ( empty( $item_key ) ) {
            wp_send_json_error( array( 'message' => __( '参数不完整', 'wpbridge' ) ) );
        }

        $backup_manager = BackupManager::get_instance();
        $backups = $backup_manager->get_item_backups( $item_key );

        wp_send_json_success( array(
            'backups' => $backups,
        ) );
    }

    /**
     * AJAX: 获取更新日志
     */
    public function ajax_get_changelog(): void {
        check_ajax_referer( 'wpbridge_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'wpbridge' ) ) );
        }

        $slug        = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $type        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'plugin';
        $source_type = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : 'wporg';
        $source_url  = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( '参数不完整', 'wpbridge' ) ) );
        }

        $changelog_manager = ChangelogManager::get_instance();

        if ( 'theme' === $type ) {
            $changelog = $changelog_manager->get_theme_changelog( $slug, $source_type, $source_url );
        } else {
            $changelog = $changelog_manager->get_plugin_changelog( $slug, $source_type, $source_url );
        }

        if ( ! $changelog['success'] ) {
            wp_send_json_error( array( 'message' => $changelog['error'] ?? __( '获取更新日志失败', 'wpbridge' ) ) );
        }

        wp_send_json_success( $changelog );
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
