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
if (!defined("ABSPATH")) {
    exit();
}

use WPBridge\UpdateSource\SourceType;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Cache\HealthChecker;

// 获取数据
$settings_obj = new Settings();
$source_manager = new SourceManager($settings_obj);
$sources = $source_manager->get_all();
$stats = $source_manager->get_stats();
$settings = $settings_obj->get_all();
$logs = Logger::get_logs();

// 健康检查
$health_checker = new HealthChecker($settings_obj);
$health_status = [];
foreach ($sources as $source) {
    if ($source->enabled) {
        $cached_status = get_transient("wpbridge_health_" . $source->id);
        if ($cached_status) {
            $health_status[$source->id] = $cached_status;
        }
    }
}
?>
<!-- 标题栏 -->
<header class="wpbridge-header">
    <div class="wpbridge-header-left">
        <span class="dashicons dashicons-networking wpbridge-logo"></span>
        <h1 class="wpbridge-title">
            <?php esc_html_e("云桥", "wpbridge"); ?>
            <span class="wpbridge-version">v<?php echo esc_html(
                WPBRIDGE_VERSION,
            ); ?></span>
        </h1>
    </div>

    <div class="wpbridge-header-right">
        <a href="https://wenpai.org/plugins/wpbridge" target="_blank" class="wpbridge-header-link">
            <span class="dashicons dashicons-book"></span>
            <?php esc_html_e("文档", "wpbridge"); ?>
        </a>
        <a href="https://github.com/WenPai-org/wpbridge" target="_blank" class="wpbridge-header-link">
            <span class="dashicons dashicons-editor-code"></span>
            <?php esc_html_e("GitHub", "wpbridge"); ?>
        </a>
        <a href="https://wenpai.org/support" target="_blank" class="wpbridge-header-link">
            <span class="dashicons dashicons-sos"></span>
            <?php esc_html_e("支持", "wpbridge"); ?>
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
                <a href="#projects" class="wpbridge-tab wpbridge-tab-active" data-tab="projects">
                    <?php esc_html_e("项目", "wpbridge"); ?>
                </a>
                <a href="#sources" class="wpbridge-tab" data-tab="sources">
                    <?php esc_html_e("更新源", "wpbridge"); ?>
                </a>
                <a href="#settings" class="wpbridge-tab" data-tab="settings">
                    <?php esc_html_e("设置", "wpbridge"); ?>
                </a>
                <a href="#api" class="wpbridge-tab" data-tab="api">
                    <?php esc_html_e("Cloud API", "wpbridge"); ?>
                </a>
                <a href="#logs" class="wpbridge-tab" data-tab="logs">
                    <?php esc_html_e("日志", "wpbridge"); ?>
                </a>
            </nav>

            <!-- Tab: 项目 -->
            <div id="projects" class="wpbridge-tab-pane wpbridge-tab-pane-active">
                <?php include WPBRIDGE_PATH .
                    "templates/admin/tabs/projects.php"; ?>
            </div>

            <!-- Tab: 更新源 -->
            <div id="sources" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH .
                    "templates/admin/tabs/sources.php"; ?>
            </div>

            <!-- Tab: 设置 -->
            <div id="settings" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH .
                    "templates/admin/tabs/settings.php"; ?>
            </div>

            <!-- Tab: Cloud API -->
            <div id="api" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH . "templates/admin/tabs/api.php"; ?>
            </div>

            <!-- Tab: 日志 -->
            <div id="logs" class="wpbridge-tab-pane">
                <?php include WPBRIDGE_PATH .
                    "templates/admin/tabs/logs.php"; ?>
            </div>
        </div>
    </div>
</div>

<!-- Toast 通知容器 -->
<div id="wpbridge-toast" class="wpbridge-toast"></div>
