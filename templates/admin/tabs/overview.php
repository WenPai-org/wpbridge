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
$item_configs   = get_option( 'wpbridge_item_sources', array() );
$custom_count   = 0;
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
$healthy_count  = 0;
$degraded_count = 0;
$failed_count   = 0;
foreach ( $health_status as $status ) {
    // 确保 $status 是数组
    if ( is_array( $status ) && isset( $status['status'] ) ) {
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

// 计算健康百分比
$total_checked   = count( $health_status );
$health_percent  = $total_checked > 0 ? round( ( $healthy_count / $total_checked ) * 100 ) : 0;
$health_status_class = $failed_count > 0 ? 'error' : ( $degraded_count > 0 ? 'warning' : 'success' );
?>

<!-- 状态摘要栏 -->
<div class="wpbridge-status-bar">
    <div class="wpbridge-status-bar-item">
        <span class="wpbridge-status-indicator <?php echo $stats['enabled'] > 0 ? 'active' : 'inactive'; ?>"></span>
        <span class="wpbridge-status-text">
            <?php
            printf(
                /* translators: %d: number of enabled sources */
                esc_html__( '%d 个更新源已启用', 'wpbridge' ),
                $stats['enabled']
            );
            ?>
        </span>
    </div>
    <?php if ( $debug_mode ) : ?>
        <div class="wpbridge-status-bar-item wpbridge-status-bar-warning">
            <span class="dashicons dashicons-warning"></span>
            <span class="wpbridge-status-text"><?php esc_html_e( '调试模式已开启', 'wpbridge' ); ?></span>
        </div>
    <?php endif; ?>
    <div class="wpbridge-status-bar-actions">
        <button type="button" class="wpbridge-btn wpbridge-btn-sm wpbridge-btn-secondary wpbridge-clear-cache">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( '清除缓存', 'wpbridge' ); ?>
        </button>
    </div>
</div>

<!-- 核心指标 -->
<div class="wpbridge-metrics">
    <!-- 更新源 -->
    <div class="wpbridge-metric-card">
        <div class="wpbridge-metric-icon">
            <span class="dashicons dashicons-cloud"></span>
        </div>
        <div class="wpbridge-metric-content">
            <div class="wpbridge-metric-value"><?php echo esc_html( $stats['total'] ); ?></div>
            <div class="wpbridge-metric-label"><?php esc_html_e( '更新源', 'wpbridge' ); ?></div>
            <div class="wpbridge-metric-sub">
                <span class="wpbridge-metric-highlight"><?php echo esc_html( $stats['enabled'] ); ?></span> <?php esc_html_e( '已启用', 'wpbridge' ); ?>
            </div>
        </div>
        <a href="#sources" class="wpbridge-metric-link" data-tab-link="sources" aria-label="<?php esc_attr_e( '管理更新源', 'wpbridge' ); ?>">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>
    </div>

    <!-- 项目 -->
    <div class="wpbridge-metric-card">
        <div class="wpbridge-metric-icon">
            <span class="dashicons dashicons-admin-plugins"></span>
        </div>
        <div class="wpbridge-metric-content">
            <div class="wpbridge-metric-value"><?php echo esc_html( $plugins_count + $themes_count ); ?></div>
            <div class="wpbridge-metric-label"><?php esc_html_e( '项目', 'wpbridge' ); ?></div>
            <div class="wpbridge-metric-sub">
                <?php echo esc_html( $plugins_count ); ?> <?php esc_html_e( '插件', 'wpbridge' ); ?> / <?php echo esc_html( $themes_count ); ?> <?php esc_html_e( '主题', 'wpbridge' ); ?>
            </div>
        </div>
        <a href="#projects" class="wpbridge-metric-link" data-tab-link="projects" aria-label="<?php esc_attr_e( '管理项目', 'wpbridge' ); ?>">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>
    </div>

    <!-- 自定义配置 -->
    <div class="wpbridge-metric-card">
        <div class="wpbridge-metric-icon">
            <span class="dashicons dashicons-admin-settings"></span>
        </div>
        <div class="wpbridge-metric-content">
            <div class="wpbridge-metric-value"><?php echo esc_html( $custom_count + $disabled_count ); ?></div>
            <div class="wpbridge-metric-label"><?php esc_html_e( '自定义配置', 'wpbridge' ); ?></div>
            <div class="wpbridge-metric-sub">
                <?php if ( $custom_count > 0 || $disabled_count > 0 ) : ?>
                    <?php if ( $custom_count > 0 ) : ?>
                        <span class="wpbridge-metric-highlight"><?php echo esc_html( $custom_count ); ?></span> <?php esc_html_e( '自定义', 'wpbridge' ); ?>
                    <?php endif; ?>
                    <?php if ( $disabled_count > 0 ) : ?>
                        <?php if ( $custom_count > 0 ) : ?> / <?php endif; ?>
                        <span class="wpbridge-metric-muted"><?php echo esc_html( $disabled_count ); ?></span> <?php esc_html_e( '禁用', 'wpbridge' ); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <?php esc_html_e( '全部使用默认', 'wpbridge' ); ?>
                <?php endif; ?>
            </div>
        </div>
        <a href="#projects" class="wpbridge-metric-link" data-tab-link="projects" aria-label="<?php esc_attr_e( '查看配置', 'wpbridge' ); ?>">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>
    </div>

    <!-- 健康状态 -->
    <div class="wpbridge-metric-card wpbridge-metric-card-health">
        <div class="wpbridge-metric-icon wpbridge-metric-icon-<?php echo esc_attr( $health_status_class ); ?>">
            <span class="dashicons dashicons-heart"></span>
        </div>
        <div class="wpbridge-metric-content">
            <?php if ( $total_checked > 0 ) : ?>
                <div class="wpbridge-metric-value wpbridge-metric-value-<?php echo esc_attr( $health_status_class ); ?>">
                    <?php echo esc_html( $health_percent ); ?>%
                </div>
                <div class="wpbridge-metric-label"><?php esc_html_e( '健康度', 'wpbridge' ); ?></div>
                <div class="wpbridge-metric-sub">
                    <?php echo esc_html( $healthy_count ); ?>/<?php echo esc_html( $total_checked ); ?> <?php esc_html_e( '源正常', 'wpbridge' ); ?>
                </div>
            <?php else : ?>
                <div class="wpbridge-metric-value wpbridge-metric-value-muted">--</div>
                <div class="wpbridge-metric-label"><?php esc_html_e( '健康度', 'wpbridge' ); ?></div>
                <div class="wpbridge-metric-sub"><?php esc_html_e( '暂无检查数据', 'wpbridge' ); ?></div>
            <?php endif; ?>
        </div>
        <a href="#diagnostics" class="wpbridge-metric-link" data-tab-link="diagnostics" aria-label="<?php esc_attr_e( '运行诊断', 'wpbridge' ); ?>">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>
    </div>
</div>

<!-- 快速入口 + 系统信息 -->
<div class="wpbridge-overview-panels">
    <!-- 快速入口 -->
    <div class="wpbridge-panel wpbridge-panel-actions">
        <div class="wpbridge-panel-header">
            <h3><?php esc_html_e( '快速入口', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-panel-body">
            <div class="wpbridge-action-list">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=add' ) ); ?>" class="wpbridge-action-item">
                    <span class="wpbridge-action-icon">
                        <span class="dashicons dashicons-plus-alt2"></span>
                    </span>
                    <span class="wpbridge-action-text">
                        <span class="wpbridge-action-title"><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></span>
                        <span class="wpbridge-action-desc"><?php esc_html_e( '配置新的自定义更新源', 'wpbridge' ); ?></span>
                    </span>
                </a>
                <a href="#diagnostics" class="wpbridge-action-item" data-tab-link="diagnostics">
                    <span class="wpbridge-action-icon">
                        <span class="dashicons dashicons-admin-tools"></span>
                    </span>
                    <span class="wpbridge-action-text">
                        <span class="wpbridge-action-title"><?php esc_html_e( '诊断工具', 'wpbridge' ); ?></span>
                        <span class="wpbridge-action-desc"><?php esc_html_e( '检查源连通性和系统环境', 'wpbridge' ); ?></span>
                    </span>
                </a>
                <a href="#api" class="wpbridge-action-item" data-tab-link="api">
                    <span class="wpbridge-action-icon">
                        <span class="dashicons dashicons-rest-api"></span>
                    </span>
                    <span class="wpbridge-action-text">
                        <span class="wpbridge-action-title"><?php esc_html_e( 'Bridge API', 'wpbridge' ); ?></span>
                        <span class="wpbridge-action-desc"><?php esc_html_e( '管理 API 密钥和访问控制', 'wpbridge' ); ?></span>
                    </span>
                </a>
                <a href="#settings" class="wpbridge-action-item" data-tab-link="settings">
                    <span class="wpbridge-action-icon">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </span>
                    <span class="wpbridge-action-text">
                        <span class="wpbridge-action-title"><?php esc_html_e( '设置', 'wpbridge' ); ?></span>
                        <span class="wpbridge-action-desc"><?php esc_html_e( '配置缓存、超时和调试选项', 'wpbridge' ); ?></span>
                    </span>
                </a>
            </div>
        </div>
    </div>

    <!-- 系统信息 -->
    <div class="wpbridge-panel wpbridge-panel-info">
        <div class="wpbridge-panel-header">
            <h3><?php esc_html_e( '系统信息', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-panel-body">
            <div class="wpbridge-info-list">
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( 'WordPress', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( 'PHP', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( PHP_VERSION ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '插件版本', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( WPBRIDGE_VERSION ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '缓存 TTL', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( ( $settings['cache_ttl'] ?? 43200 ) / 3600 ); ?>h</span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '请求超时', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( $settings['request_timeout'] ?? 10 ); ?>s</span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '缓存降级', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value wpbridge-info-value-<?php echo $cache_enabled ? 'success' : 'muted'; ?>">
                        <?php echo $cache_enabled ? esc_html__( '已启用', 'wpbridge' ) : esc_html__( '已禁用', 'wpbridge' ); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
