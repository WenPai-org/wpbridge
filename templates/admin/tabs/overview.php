<?php
/**
 * 概览 Tab 内容 - 状态仪表板
 *
 * @package WPBridge
 * @since 0.7.0
 * @var array $sources 源列表
 * @var array $stats   统计信息
 * @var array $settings 设置
 * @var array $health_status 健康状态
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 计算统计数据
$plugins_count = count( get_plugins() );
$themes_count  = count( wp_get_themes() );

// 获取项目配置统计
$item_configs = get_option( 'wpbridge_item_sources', array() );
$custom_count = 0;
$disabled_count = 0;
foreach ( $item_configs as $config ) {
    if ( isset( $config['mode'] ) ) {
        if ( $config['mode'] === 'custom' ) {
            $custom_count++;
        } elseif ( $config['mode'] === 'disabled' ) {
            $disabled_count++;
        }
    }
}

// 健康源统计
$healthy_count = 0;
$degraded_count = 0;
$failed_count = 0;
foreach ( $health_status as $status ) {
    if ( isset( $status['status'] ) ) {
        switch ( $status['status'] ) {
            case 'healthy':
                $healthy_count++;
                break;
            case 'degraded':
                $degraded_count++;
                break;
            case 'failed':
                $failed_count++;
                break;
        }
    }
}

// 缓存状态
$cache_enabled = ! empty( $settings['fallback_enabled'] );
$debug_mode    = ! empty( $settings['debug_mode'] );
?>

<!-- 欢迎区域 -->
<div class="wpbridge-welcome">
    <div class="wpbridge-welcome-content">
        <h2><?php esc_html_e( '欢迎使用文派云桥', 'wpbridge' ); ?></h2>
        <p><?php esc_html_e( '自定义源桥接器 - 让您完全控制 WordPress 的外部连接', 'wpbridge' ); ?></p>
    </div>
    <div class="wpbridge-welcome-actions">
        <a href="#sources" class="wpbridge-btn wpbridge-btn-primary" data-tab-link="sources">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( '添加更新源', 'wpbridge' ); ?>
        </a>
        <a href="#diagnostics" class="wpbridge-btn wpbridge-btn-secondary" data-tab-link="diagnostics">
            <span class="dashicons dashicons-admin-tools"></span>
            <?php esc_html_e( '运行诊断', 'wpbridge' ); ?>
        </a>
    </div>
</div>

<!-- 状态概览卡片 -->
<div class="wpbridge-overview-grid">
    <!-- 更新源状态 -->
    <div class="wpbridge-overview-card">
        <div class="wpbridge-overview-card-header">
            <span class="dashicons dashicons-cloud"></span>
            <h3><?php esc_html_e( '更新源', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-overview-card-body">
            <div class="wpbridge-overview-stat-main">
                <span class="wpbridge-overview-number"><?php echo esc_html( $stats['total'] ); ?></span>
                <span class="wpbridge-overview-label"><?php esc_html_e( '总更新源', 'wpbridge' ); ?></span>
            </div>
            <div class="wpbridge-overview-stat-details">
                <div class="wpbridge-overview-stat-item">
                    <span class="wpbridge-dot wpbridge-dot-success"></span>
                    <span><?php echo esc_html( $stats['enabled'] ); ?> <?php esc_html_e( '已启用', 'wpbridge' ); ?></span>
                </div>
                <div class="wpbridge-overview-stat-item">
                    <span class="wpbridge-dot wpbridge-dot-gray"></span>
                    <span><?php echo esc_html( $stats['total'] - $stats['enabled'] ); ?> <?php esc_html_e( '已禁用', 'wpbridge' ); ?></span>
                </div>
            </div>
        </div>
        <div class="wpbridge-overview-card-footer">
            <a href="#sources" data-tab-link="sources"><?php esc_html_e( '管理更新源', 'wpbridge' ); ?> →</a>
        </div>
    </div>

    <!-- 项目状态 -->
    <div class="wpbridge-overview-card">
        <div class="wpbridge-overview-card-header">
            <span class="dashicons dashicons-admin-plugins"></span>
            <h3><?php esc_html_e( '项目', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-overview-card-body">
            <div class="wpbridge-overview-stat-main">
                <span class="wpbridge-overview-number"><?php echo esc_html( $plugins_count + $themes_count ); ?></span>
                <span class="wpbridge-overview-label"><?php esc_html_e( '总项目', 'wpbridge' ); ?></span>
            </div>
            <div class="wpbridge-overview-stat-details">
                <div class="wpbridge-overview-stat-item">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <span><?php echo esc_html( $plugins_count ); ?> <?php esc_html_e( '插件', 'wpbridge' ); ?></span>
                </div>
                <div class="wpbridge-overview-stat-item">
                    <span class="dashicons dashicons-admin-appearance"></span>
                    <span><?php echo esc_html( $themes_count ); ?> <?php esc_html_e( '主题', 'wpbridge' ); ?></span>
                </div>
            </div>
        </div>
        <div class="wpbridge-overview-card-footer">
            <a href="#projects" data-tab-link="projects"><?php esc_html_e( '管理项目', 'wpbridge' ); ?> →</a>
        </div>
    </div>

    <!-- 配置状态 -->
    <div class="wpbridge-overview-card">
        <div class="wpbridge-overview-card-header">
            <span class="dashicons dashicons-admin-settings"></span>
            <h3><?php esc_html_e( '配置', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-overview-card-body">
            <div class="wpbridge-overview-stat-main">
                <span class="wpbridge-overview-number"><?php echo esc_html( $custom_count + $disabled_count ); ?></span>
                <span class="wpbridge-overview-label"><?php esc_html_e( '自定义配置', 'wpbridge' ); ?></span>
            </div>
            <div class="wpbridge-overview-stat-details">
                <div class="wpbridge-overview-stat-item">
                    <span class="wpbridge-dot wpbridge-dot-info"></span>
                    <span><?php echo esc_html( $custom_count ); ?> <?php esc_html_e( '自定义源', 'wpbridge' ); ?></span>
                </div>
                <div class="wpbridge-overview-stat-item">
                    <span class="wpbridge-dot wpbridge-dot-warning"></span>
                    <span><?php echo esc_html( $disabled_count ); ?> <?php esc_html_e( '禁用更新', 'wpbridge' ); ?></span>
                </div>
            </div>
        </div>
        <div class="wpbridge-overview-card-footer">
            <a href="#projects" data-tab-link="projects"><?php esc_html_e( '查看详情', 'wpbridge' ); ?> →</a>
        </div>
    </div>

    <!-- 健康状态 -->
    <div class="wpbridge-overview-card">
        <div class="wpbridge-overview-card-header">
            <span class="dashicons dashicons-heart"></span>
            <h3><?php esc_html_e( '健康状态', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-overview-card-body">
            <?php if ( empty( $health_status ) ) : ?>
                <div class="wpbridge-overview-empty">
                    <span class="dashicons dashicons-info-outline"></span>
                    <p><?php esc_html_e( '暂无健康检查数据', 'wpbridge' ); ?></p>
                </div>
            <?php else : ?>
                <div class="wpbridge-overview-stat-main">
                    <span class="wpbridge-overview-number <?php echo $failed_count > 0 ? 'error' : ( $degraded_count > 0 ? 'warning' : 'success' ); ?>">
                        <?php echo esc_html( $healthy_count ); ?>/<?php echo esc_html( count( $health_status ) ); ?>
                    </span>
                    <span class="wpbridge-overview-label"><?php esc_html_e( '源正常', 'wpbridge' ); ?></span>
                </div>
                <div class="wpbridge-overview-stat-details">
                    <?php if ( $healthy_count > 0 ) : ?>
                        <div class="wpbridge-overview-stat-item">
                            <span class="wpbridge-dot wpbridge-dot-success"></span>
                            <span><?php echo esc_html( $healthy_count ); ?> <?php esc_html_e( '正常', 'wpbridge' ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $degraded_count > 0 ) : ?>
                        <div class="wpbridge-overview-stat-item">
                            <span class="wpbridge-dot wpbridge-dot-warning"></span>
                            <span><?php echo esc_html( $degraded_count ); ?> <?php esc_html_e( '降级', 'wpbridge' ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $failed_count > 0 ) : ?>
                        <div class="wpbridge-overview-stat-item">
                            <span class="wpbridge-dot wpbridge-dot-error"></span>
                            <span><?php echo esc_html( $failed_count ); ?> <?php esc_html_e( '失败', 'wpbridge' ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="wpbridge-overview-card-footer">
            <a href="#diagnostics" data-tab-link="diagnostics"><?php esc_html_e( '运行诊断', 'wpbridge' ); ?> →</a>
        </div>
    </div>
</div>

<!-- 快速操作 -->
<div class="wpbridge-quick-actions">
    <h3><?php esc_html_e( '快速操作', 'wpbridge' ); ?></h3>
    <div class="wpbridge-quick-actions-grid">
        <button type="button" class="wpbridge-quick-action wpbridge-clear-cache">
            <span class="dashicons dashicons-update"></span>
            <span><?php esc_html_e( '清除缓存', 'wpbridge' ); ?></span>
        </button>
        <a href="#diagnostics" class="wpbridge-quick-action" data-tab-link="diagnostics">
            <span class="dashicons dashicons-admin-tools"></span>
            <span><?php esc_html_e( '诊断工具', 'wpbridge' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=add' ) ); ?>" class="wpbridge-quick-action">
            <span class="dashicons dashicons-plus-alt2"></span>
            <span><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></span>
        </a>
        <a href="#settings" class="wpbridge-quick-action" data-tab-link="settings">
            <span class="dashicons dashicons-admin-generic"></span>
            <span><?php esc_html_e( '设置', 'wpbridge' ); ?></span>
        </a>
    </div>
</div>

<!-- 系统信息 -->
<div class="wpbridge-system-info">
    <h3><?php esc_html_e( '系统信息', 'wpbridge' ); ?></h3>
    <div class="wpbridge-system-info-grid">
        <div class="wpbridge-system-info-item">
            <span class="wpbridge-system-info-label"><?php esc_html_e( 'WordPress 版本', 'wpbridge' ); ?></span>
            <span class="wpbridge-system-info-value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
        </div>
        <div class="wpbridge-system-info-item">
            <span class="wpbridge-system-info-label"><?php esc_html_e( 'PHP 版本', 'wpbridge' ); ?></span>
            <span class="wpbridge-system-info-value"><?php echo esc_html( PHP_VERSION ); ?></span>
        </div>
        <div class="wpbridge-system-info-item">
            <span class="wpbridge-system-info-label"><?php esc_html_e( '调试模式', 'wpbridge' ); ?></span>
            <span class="wpbridge-system-info-value <?php echo $debug_mode ? 'warning' : ''; ?>">
                <?php echo $debug_mode ? esc_html__( '已启用', 'wpbridge' ) : esc_html__( '已禁用', 'wpbridge' ); ?>
            </span>
        </div>
        <div class="wpbridge-system-info-item">
            <span class="wpbridge-system-info-label"><?php esc_html_e( '缓存降级', 'wpbridge' ); ?></span>
            <span class="wpbridge-system-info-value <?php echo $cache_enabled ? 'success' : ''; ?>">
                <?php echo $cache_enabled ? esc_html__( '已启用', 'wpbridge' ) : esc_html__( '已禁用', 'wpbridge' ); ?>
            </span>
        </div>
    </div>
</div>
