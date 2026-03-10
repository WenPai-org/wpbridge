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
foreach ( $item_configs as $config ) {
    if ( isset( $config['mode'] ) && $config['mode'] === 'custom' ) {
        $custom_count++;
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

// 每日可下载次数（来自订阅）
$daily_downloads = $subscription['daily_downloads'] ?? 0;
if ( $daily_downloads >= PHP_INT_MAX || $daily_downloads < 0 ) {
	$daily_downloads_label = '∞';
} elseif ( $daily_downloads > 0 ) {
	$daily_downloads_label = (string) $daily_downloads;
} else {
	$daily_downloads_label = '0';
}
$plan_label = $subscription['label'] ?? $subscription['plan'] ?? 'free';

$debug_mode    = ! empty( $settings['debug_mode'] );

// 调试模式

// 计算健康百分比
$total_checked   = count( $health_status );
$health_percent  = $total_checked > 0 ? round( ( $healthy_count / $total_checked ) * 100 ) : 0;
$health_status_class = $failed_count > 0 ? 'error' : ( $degraded_count > 0 ? 'warning' : 'success' );
?>

<?php if ( $stats['total'] === 0 ) : ?>
<!-- 新用户引导 -->
<div class="wpbridge-welcome">
    <div class="wpbridge-welcome-header">
        <span class="dashicons dashicons-welcome-learn-more"></span>
        <h2><?php esc_html_e( '欢迎使用 WPBridge', 'wpbridge' ); ?></h2>
        <p><?php esc_html_e( '完成以下步骤，开始管理你的更新源。', 'wpbridge' ); ?></p>
    </div>
    <div class="wpbridge-welcome-steps">
        <div class="wpbridge-welcome-step">
            <span class="wpbridge-welcome-step-num">1</span>
            <div class="wpbridge-welcome-step-content">
                <h4><?php esc_html_e( '激活供应商', 'wpbridge' ); ?></h4>
                <p><?php esc_html_e( '在供应商 Tab 中激活薇晓朵等预设供应商，自动获取已购产品更新。', 'wpbridge' ); ?></p>
                <a href="#vendors" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm" data-tab-link="vendors">
                    <?php esc_html_e( '前往供应商', 'wpbridge' ); ?>
                </a>
            </div>
        </div>
        <div class="wpbridge-welcome-step">
            <span class="wpbridge-welcome-step-num">2</span>
            <div class="wpbridge-welcome-step-content">
                <h4><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></h4>
                <p><?php esc_html_e( '或手动添加自定义更新源，连接你的私有仓库或商业插件服务器。', 'wpbridge' ); ?></p>
                <a href="#projects" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm" data-tab-link="projects" data-subtab="sources">
                    <?php esc_html_e( '添加更新源', 'wpbridge' ); ?>
                </a>
            </div>
        </div>
        <div class="wpbridge-welcome-step">
            <span class="wpbridge-welcome-step-num">3</span>
            <div class="wpbridge-welcome-step-content">
                <h4><?php esc_html_e( '配置项目', 'wpbridge' ); ?></h4>
                <p><?php esc_html_e( '为已安装的插件和主题选择更新源，或使用默认规则自动匹配。', 'wpbridge' ); ?></p>
                <a href="#projects" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm" data-tab-link="projects">
                    <?php esc_html_e( '管理项目', 'wpbridge' ); ?>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="wpbridge-overview-hero">
    <span class="wpbridge-overview-hero-icon"><span class="dashicons dashicons-networking"></span></span>
    <h2 class="wpbridge-overview-hero-title"><?php esc_html_e( '文派云桥', 'wpbridge' ); ?></h2>
    <p class="wpbridge-overview-hero-subtitle"><?php esc_html_e( 'WordPress 自定义源桥接器 — 连接供应商、管理更新源、桥接 AI 服务，完全掌控你的站点外部连接。', 'wpbridge' ); ?></p>
    <p class="wpbridge-overview-hero-meta">
        v<?php echo esc_html( WPBRIDGE_VERSION ); ?>
        &middot; <?php echo esc_html( $stats['total'] ); ?> <?php esc_html_e( '个更新源', 'wpbridge' ); ?>
        &middot; <?php echo esc_html( $plugins_count + $themes_count ); ?> <?php esc_html_e( '个项目', 'wpbridge' ); ?>
    </p>
    <div class="wpbridge-overview-hero-actions">
        <a href="#vendors" class="wpbridge-overview-hero-btn wpbridge-overview-hero-btn--primary" data-tab-link="vendors"><?php esc_html_e( '连接供应商', 'wpbridge' ); ?></a>
        <a href="#projects" class="wpbridge-overview-hero-btn wpbridge-overview-hero-btn--secondary" data-tab-link="projects" data-subtab="sources"><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></a>
    </div>
</div>

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
        <a href="#projects" class="wpbridge-metric-link" data-tab-link="projects" data-subtab="sources" aria-label="<?php esc_attr_e( '管理更新源', 'wpbridge' ); ?>">
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
        <a href="#projects" class="wpbridge-metric-link" data-tab-link="projects" data-subtab="sources" aria-label="<?php esc_attr_e( '查看更新源', 'wpbridge' ); ?>">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>
    </div>

    <!-- 每日下载 -->
    <div class="wpbridge-metric-card">
        <div class="wpbridge-metric-icon">
            <span class="dashicons dashicons-download"></span>
        </div>
        <div class="wpbridge-metric-content">
            <div class="wpbridge-metric-value"><?php echo esc_html( $daily_downloads_label ); ?></div>
            <div class="wpbridge-metric-label"><?php esc_html_e( '每日可下载', 'wpbridge' ); ?></div>
            <div class="wpbridge-metric-sub">
                <?php echo esc_html( $plan_label ); ?>
            </div>
        </div>
        <a href="#vendors" class="wpbridge-metric-link" data-tab-link="vendors" aria-label="<?php esc_attr_e( '查看订阅', 'wpbridge' ); ?>">
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
                <a href="#projects" class="wpbridge-action-item" data-tab-link="projects" data-subtab="sources">
                    <span class="wpbridge-action-icon">
                        <span class="dashicons dashicons-plus-alt2"></span>
                    </span>
                    <span class="wpbridge-action-text">
                        <span class="wpbridge-action-title"><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></span>
                        <span class="wpbridge-action-desc"><?php esc_html_e( '配置新的自定义更新源', 'wpbridge' ); ?></span>
                    </span>
                </a>
                <a href="#settings" class="wpbridge-action-item" data-tab-link="settings">
                    <span class="wpbridge-action-icon">
                        <span class="dashicons dashicons-admin-tools"></span>
                    </span>
                    <span class="wpbridge-action-text">
                        <span class="wpbridge-action-title"><?php esc_html_e( '维护工具', 'wpbridge' ); ?></span>
                        <span class="wpbridge-action-desc"><?php esc_html_e( '清除缓存、调试日志、导入导出', 'wpbridge' ); ?></span>
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

    <!-- 更新统计 -->
    <div class="wpbridge-panel wpbridge-panel-info">
        <div class="wpbridge-panel-header">
            <h3><?php esc_html_e( '更新统计', 'wpbridge' ); ?></h3>
        </div>
        <div class="wpbridge-panel-body">
            <div class="wpbridge-info-list">
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '更新源', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( $stats['total'] ); ?> <?php esc_html_e( '个', 'wpbridge' ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '已启用', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value wpbridge-info-value-success"><?php echo esc_html( $stats['enabled'] ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '插件数', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( $plugins_count ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '主题数', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( $themes_count ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '自定义配置', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( $custom_count ); ?></span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '缓存 TTL', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( ( $settings['cache_ttl'] ?? 43200 ) / 3600 ); ?>h</span>
                </div>
                <div class="wpbridge-info-item">
                    <span class="wpbridge-info-label"><?php esc_html_e( '插件版本', 'wpbridge' ); ?></span>
                    <span class="wpbridge-info-value"><?php echo esc_html( WPBRIDGE_VERSION ); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 帮助文档 + 浏览更多 -->
<div class="wpbridge-overview-panels wpbridge-mt-5">
    <!-- 帮助文档 -->
    <div class="wpbridge-panel">
        <div class="wpbridge-panel-header">
            <h3>
                <span class="dashicons dashicons-book" style="margin-right: 4px; vertical-align: text-bottom;"></span>
                <?php esc_html_e( '帮助文档', 'wpbridge' ); ?>
            </h3>
        </div>
        <div class="wpbridge-panel-body">
            <div class="wpbridge-link-list">
                <a href="https://wenpai.org/plugins/wpbridge" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-media-document"></span>
                    <?php esc_html_e( '快速入门指南', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
                <a href="https://wenpai.org/plugins/wpbridge#faq" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-editor-help"></span>
                    <?php esc_html_e( '常见问题', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
                <a href="https://wenpai.org/plugins/wpbridge#changelog" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e( '更新日志', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
                <a href="https://wenpai.org/support" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-sos"></span>
                    <?php esc_html_e( '获取支持', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
            </div>
        </div>
    </div>

    <!-- 浏览更多 -->
    <div class="wpbridge-panel">
        <div class="wpbridge-panel-header">
            <h3>
                <span class="dashicons dashicons-admin-links" style="margin-right: 4px; vertical-align: text-bottom;"></span>
                <?php esc_html_e( '浏览更多', 'wpbridge' ); ?>
            </h3>
        </div>
        <div class="wpbridge-panel-body">
            <div class="wpbridge-link-list">
                <a href="https://wenpai.org" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-wordpress"></span>
                    <?php esc_html_e( '文派官网', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
                <a href="https://github.com/WenPai-org/wpbridge" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-editor-code"></span>
                    <?php esc_html_e( 'GitHub 仓库', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
                <a href="https://wenpai.org/plugins" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <?php esc_html_e( '更多文派插件', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
                <a href="https://wpcommunity.com" target="_blank" class="wpbridge-link-item">
                    <span class="dashicons dashicons-groups"></span>
                    <?php esc_html_e( '文派社区', 'wpbridge' ); ?>
                    <span class="dashicons dashicons-external wpbridge-link-external"></span>
                </a>
            </div>
        </div>
    </div>
</div>
