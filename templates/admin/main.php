<?php
/**
 * WPBridge 主设置页面模板
 *
 * 参考 WPMind Gutenberg 风格设计
 *
 * @package WPBridge
 * @since 0.5.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\UpdateSource\SourceType;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Core\RemoteConfig;
use WPBridge\Cache\HealthChecker;
use WPBridge\Commercial\BridgeManager;

// 获取数据
$settings_obj   = new Settings();
$source_manager = new SourceManager( $settings_obj );
$sources        = $source_manager->get_all();
$stats          = $source_manager->get_stats();
$settings       = $settings_obj->get_all();
$logs           = Logger::get_logs();

// 供应商 & 订阅（供 vendors/settings/api tab 共用）
$remote_config  = RemoteConfig::get_instance();
$bridge_manager = new BridgeManager( $settings_obj, $remote_config );
$subscription   = $bridge_manager->get_subscription();
$is_feature_locked = static function( string $feature ) use ( $subscription ): bool {
    return ! in_array( $feature, $subscription['features'] ?? [], true );
};

// 健康检查
$health_checker = new HealthChecker();
$health_status  = [];
foreach ( $sources as $source ) {
    if ( $source->enabled ) {
        $cached_status = get_transient( 'wpbridge_health_' . $source->id );
        // 确保缓存的状态是数组，防止 __PHP_Incomplete_Class 错误
        if ( $cached_status && is_array( $cached_status ) ) {
            $health_status[ $source->id ] = $cached_status;
        }
    }
}
?>
<!-- 标题栏 -->
<header class="wpbridge-header">
    <div class="wpbridge-header-left">
        <span class="dashicons dashicons-networking wpbridge-logo"></span>
        <h1 class="wpbridge-title">
            <?php esc_html_e( '云桥', 'wpbridge' ); ?>
            <span class="wpbridge-version">v<?php echo esc_html( WPBRIDGE_VERSION ); ?></span>
        </h1>
    </div>

    <div class="wpbridge-header-right">
        <a href="https://wenpai.org/plugins/wpbridge" target="_blank" class="wpbridge-header-link">
            <span class="dashicons dashicons-book"></span>
            <?php esc_html_e( '文档', 'wpbridge' ); ?>
        </a>
        <a href="https://github.com/WenPai-org/wpbridge" target="_blank" class="wpbridge-header-link">
            <span class="dashicons dashicons-editor-code"></span>
            <?php esc_html_e( 'GitHub', 'wpbridge' ); ?>
        </a>
        <a href="https://wenpai.org/support" target="_blank" class="wpbridge-header-link">
            <span class="dashicons dashicons-sos"></span>
            <?php esc_html_e( '支持', 'wpbridge' ); ?>
        </a>
    </div>
</header>

<div class="wrap wpbridge-wrap">

    <!-- 主内容区 -->
    <div class="wpbridge-content">
        <!-- Tab 卡片 -->
        <div class="wpbridge-tabs-card">
            <!-- Tab 导航 -->
            <nav class="wpbridge-tab-list">
                <a href="#overview" class="wpbridge-tab wpbridge-tab-active" data-tab="overview">
                    <?php esc_html_e( '概览', 'wpbridge' ); ?>
                </a>
                <a href="#projects" class="wpbridge-tab" data-tab="projects">
                    <?php esc_html_e( '更新管理', 'wpbridge' ); ?>
                </a>
                <a href="#vendors" class="wpbridge-tab" data-tab="vendors">
                    <?php esc_html_e( '供应商', 'wpbridge' ); ?>
                </a>
                <a href="#settings" class="wpbridge-tab" data-tab="settings">
                    <?php esc_html_e( '设置', 'wpbridge' ); ?>
                </a>
                <a href="#api" class="wpbridge-tab" data-tab="api">
                    <?php esc_html_e( 'Bridge API', 'wpbridge' ); ?>
                </a>
            </nav>

            <!-- Tab: 概览 -->
            <div id="overview" class="wpbridge-tab-pane wpbridge-tab-pane-active">
                <?php include WPBRIDGE_PATH . 'templates/admin/tabs/overview.php'; ?>
            </div>

            <!-- Tab: 项目 -->
            <div id="projects" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH . 'templates/admin/tabs/projects.php'; ?>
            </div>

            <!-- Tab: 渠道（原供应商 + 更新源） -->
            <div id="vendors" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH . 'templates/admin/tabs/vendors.php'; ?>
            </div>

            <!-- Tab: 设置（含调试日志） -->
            <div id="settings" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH . 'templates/admin/tabs/settings.php'; ?>
                <?php if ( ! empty( $settings['debug_mode'] ) ) : ?>
                <div class="wpbridge-settings-panel wpbridge-mt-8">
                    <h2 class="wpbridge-section-title wpbridge-mb-4">
                        <span class="dashicons dashicons-editor-code"></span>
                        <?php esc_html_e( '调试日志', 'wpbridge' ); ?>
                    </h2>
                    <?php include WPBRIDGE_PATH . 'templates/admin/tabs/logs.php'; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Bridge API -->
            <div id="api" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH . 'templates/admin/tabs/api.php'; ?>
            </div>
        </div>
    </div>
</div>
