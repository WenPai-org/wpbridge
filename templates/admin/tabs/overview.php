<?php
/**
 * 概览 Tab 内容 - 状态仪表板
 *
 * @package WPBridge
 * @since 1.2.0
 * @var array $sources 源列表
 * @var array $stats   统计信息
 * @var array $settings 设置
 * @var array $health_status 健康状态
 * @var array $subscription 订阅信息
 * @var \WPBridge\Commercial\BridgeManager $bridge_manager
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 统计数据
$plugins_count = count( get_plugins() );
$themes_count  = count( wp_get_themes() );

// 供应商统计
$vendor_manager = $bridge_manager->get_vendor_manager();
$vendors_info   = $vendor_manager->get_vendors_info();
$vendor_count   = count( $vendors_info );

// 健康源统计
$healthy_count = 0;
$failed_count  = 0;
foreach ( $health_status as $status ) {
	if ( is_array( $status ) && isset( $status['status'] ) ) {
		if ( $status['status'] === 'healthy' ) {
			++$healthy_count;
		} elseif ( $status['status'] === 'failed' ) {
			++$failed_count;
		}
	}
}
$total_checked       = count( $health_status );
$health_percent      = $total_checked > 0 ? round( ( $healthy_count / $total_checked ) * 100 ) : 0;
$health_status_class = $failed_count > 0 ? 'error' : ( $total_checked > $healthy_count ? 'warning' : 'success' );

// 订阅
$plan_label      = $subscription['label'] ?? 'Free';
$plan_name       = $subscription['plan'] ?? 'free';
$is_free         = $plan_name === 'free';
$daily_downloads = $subscription['daily_downloads'] ?? 0;
$features        = $subscription['features'] ?? [];

$debug_mode = ! empty( $settings['debug_mode'] );
?>

<?php if ( $stats['total'] === 0 && $vendor_count === 0 ) : ?>
<!-- 新用户引导 -->
<div class="wpbridge-welcome">
	<div class="wpbridge-welcome-header">
		<span class="dashicons dashicons-welcome-learn-more"></span>
		<h2><?php esc_html_e( '欢迎使用文派云桥', 'wpbridge' ); ?></h2>
		<p><?php esc_html_e( '完成以下步骤，开始管理你的更新源。', 'wpbridge' ); ?></p>
	</div>
	<div class="wpbridge-welcome-steps">
		<div class="wpbridge-welcome-step">
			<span class="wpbridge-welcome-step-num">1</span>
			<div class="wpbridge-welcome-step-content">
				<h4><?php esc_html_e( '激活供应商', 'wpbridge' ); ?></h4>
				<p><?php esc_html_e( '激活薇晓朵等预设供应商，自动获取已购产品更新。', 'wpbridge' ); ?></p>
				<a href="#vendors" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm" data-tab-link="vendors">
					<?php esc_html_e( '前往供应商', 'wpbridge' ); ?>
				</a>
			</div>
		</div>
		<div class="wpbridge-welcome-step">
			<span class="wpbridge-welcome-step-num">2</span>
			<div class="wpbridge-welcome-step-content">
				<h4><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></h4>
				<p><?php esc_html_e( '或手动添加自定义更新源，连接私有仓库或商业插件服务器。', 'wpbridge' ); ?></p>
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
	<p class="wpbridge-overview-hero-subtitle"><?php esc_html_e( '开源 WordPress 自定义更新源桥接器 — 连接供应商、管理更新源，完全掌控你的站点更新。', 'wpbridge' ); ?></p>
	<div class="wpbridge-overview-hero-features">
		<span class="wpbridge-hero-feature">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( '更新源数量不限', 'wpbridge' ); ?>
		</span>
		<span class="wpbridge-hero-feature">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( '供应商自由接入', 'wpbridge' ); ?>
		</span>
		<span class="wpbridge-hero-feature">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( '永久免费使用', 'wpbridge' ); ?>
		</span>
	</div>
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
		<a href="#projects" class="wpbridge-metric-link" data-tab-link="projects" data-subtab="sources">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
		</a>
	</div>

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
		<a href="#projects" class="wpbridge-metric-link" data-tab-link="projects">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
		</a>
	</div>

	<div class="wpbridge-metric-card">
		<div class="wpbridge-metric-icon">
			<span class="dashicons dashicons-store"></span>
		</div>
		<div class="wpbridge-metric-content">
			<div class="wpbridge-metric-value"><?php echo esc_html( $vendor_count ); ?></div>
			<div class="wpbridge-metric-label"><?php esc_html_e( '供应商', 'wpbridge' ); ?></div>
			<div class="wpbridge-metric-sub"><?php echo esc_html( $plan_label ); ?></div>
		</div>
		<a href="#vendors" class="wpbridge-metric-link" data-tab-link="vendors">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
		</a>
	</div>

	<div class="wpbridge-metric-card">
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
		<a href="#projects" class="wpbridge-metric-link" data-tab-link="projects" data-subtab="sources">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
		</a>
	</div>
</div>

<!-- 快速入口 + 帮助 | 方案对比 -->
<div class="wpbridge-overview-panels">
	<!-- 左栏：快速入口 + 帮助链接 -->
	<div class="wpbridge-overview-panels-stack">
		<!-- 快速入口 -->
		<div class="wpbridge-panel">
			<div class="wpbridge-panel-header">
				<h3><?php esc_html_e( '快速入口', 'wpbridge' ); ?></h3>
			</div>
			<div class="wpbridge-panel-body">
				<div class="wpbridge-action-list">
					<a href="#vendors" class="wpbridge-action-item" data-tab-link="vendors">
						<span class="wpbridge-action-icon">
							<span class="dashicons dashicons-store"></span>
						</span>
						<span class="wpbridge-action-text">
							<span class="wpbridge-action-title"><?php esc_html_e( '连接供应商', 'wpbridge' ); ?></span>
							<span class="wpbridge-action-desc"><?php esc_html_e( '激活商业插件供应商，获取正版更新', 'wpbridge' ); ?></span>
						</span>
					</a>
					<a href="#projects" class="wpbridge-action-item" data-tab-link="projects" data-subtab="sources">
						<span class="wpbridge-action-icon">
							<span class="dashicons dashicons-plus-alt2"></span>
						</span>
						<span class="wpbridge-action-text">
							<span class="wpbridge-action-title"><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></span>
							<span class="wpbridge-action-desc"><?php esc_html_e( '配置自定义更新源或私有仓库', 'wpbridge' ); ?></span>
						</span>
					</a>
					<a href="#api" class="wpbridge-action-item" data-tab-link="api">
						<span class="wpbridge-action-icon">
							<span class="dashicons dashicons-rest-api"></span>
						</span>
						<span class="wpbridge-action-text">
							<span class="wpbridge-action-title"><?php esc_html_e( 'Bridge API', 'wpbridge' ); ?></span>
							<span class="wpbridge-action-desc"><?php esc_html_e( '向子站点分发更新（Hub-Spoke）', 'wpbridge' ); ?></span>
						</span>
					</a>
					<a href="#settings" class="wpbridge-action-item" data-tab-link="settings">
						<span class="wpbridge-action-icon">
							<span class="dashicons dashicons-admin-generic"></span>
						</span>
						<span class="wpbridge-action-text">
							<span class="wpbridge-action-title"><?php esc_html_e( '设置', 'wpbridge' ); ?></span>
							<span class="wpbridge-action-desc"><?php esc_html_e( '缓存、调试、导入导出', 'wpbridge' ); ?></span>
						</span>
					</a>
				</div>
			</div>
		</div>
	</div>

	<!-- 右栏：方案 -->
		<div class="wpbridge-plan-section">
			<div class="wpbridge-plan-section-header">
				<div class="wpbridge-plan-section-title">
					<h3><?php echo $is_free ? esc_html__( '方案对比', 'wpbridge' ) : esc_html__( '当前权益', 'wpbridge' ); ?></h3>
					<span class="wpbridge-plan-badge wpbridge-plan-badge-<?php echo esc_attr( $is_free ? 'free' : 'pro' ); ?>">
						<?php echo esc_html( $plan_label ); ?>
					</span>
				</div>
				<?php if ( $is_free ) : ?>
					<a href="https://mall.weixiaoduo.com/item/wpbridge-pro" target="_blank" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm">
						<?php esc_html_e( '升级 Pro', 'wpbridge' ); ?>
					</a>
				<?php elseif ( $plan_name === 'pro' ) : ?>
					<a href="https://mall.weixiaoduo.com/item/wpbridge-pro" target="_blank" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm">
						<?php esc_html_e( '升级企业版', 'wpbridge' ); ?>
					</a>
				<?php elseif ( $plan_name === 'pro_enterprise' ) : ?>
					<a href="https://mall.weixiaoduo.com/item/wpbridge-all-access" target="_blank" class="wpbridge-btn wpbridge-btn-sm wpbridge-btn-secondary">
						<?php esc_html_e( '了解 All Access', 'wpbridge' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<?php if ( $is_free ) : ?>
			<div class="wpbridge-plan-grid wpbridge-plan-grid--4col">
				<?php $current_plan = $plan_name; ?>
				<div class="wpbridge-plan-grid-header">
					<span></span>
					<span<?php echo $current_plan === 'free' ? ' class="wpbridge-plan-grid-current"' : ''; ?>><?php esc_html_e( '免费版', 'wpbridge' ); ?></span>
					<span<?php echo $current_plan === 'pro' ? ' class="wpbridge-plan-grid-current"' : ''; ?>><?php esc_html_e( '专业版', 'wpbridge' ); ?></span>
					<span<?php echo in_array( $current_plan, [ 'pro_enterprise', 'all_access' ], true ) ? ' class="wpbridge-plan-grid-current"' : ''; ?>><?php esc_html_e( '企业版', 'wpbridge' ); ?></span>
				</div>
				<div class="wpbridge-plan-grid-row">
					<span class="wpbridge-plan-grid-label wpbridge-plan-grid-label--stacked">
						<span class="dashicons dashicons-download"></span>
						<span>
							<?php esc_html_e( '商业产品下载', 'wpbridge' ); ?>
							<small class="wpbridge-plan-grid-hint"><?php esc_html_e( '从供应商处安装和更新商业插件/主题', 'wpbridge' ); ?></small>
						</span>
					</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-free">&mdash;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro"><?php esc_html_e( '50 次/天', 'wpbridge' ); ?></span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro"><?php esc_html_e( '500 次/天', 'wpbridge' ); ?></span>
				</div>
				<div class="wpbridge-plan-grid-row">
					<span class="wpbridge-plan-grid-label wpbridge-plan-grid-label--stacked">
						<span class="dashicons dashicons-rest-api"></span>
						<span>
							<?php esc_html_e( 'Bridge API', 'wpbridge' ); ?>
							<small class="wpbridge-plan-grid-hint"><?php esc_html_e( '管 N 个站只维护一份源，本站对外分发更新', 'wpbridge' ); ?></small>
						</span>
					</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-free">&mdash;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro">&#10003;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro">&#10003;</span>
				</div>
				<div class="wpbridge-plan-grid-row">
					<span class="wpbridge-plan-grid-label wpbridge-plan-grid-label--stacked">
						<span class="dashicons dashicons-networking"></span>
						<span>
							<?php esc_html_e( 'Bridge Server', 'wpbridge' ); ?>
							<small class="wpbridge-plan-grid-hint"><?php esc_html_e( '子站连接主站 API，自动接收更新推送', 'wpbridge' ); ?></small>
						</span>
					</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-free">&mdash;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro">&#10003;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro">&#10003;</span>
				</div>
				<div class="wpbridge-plan-grid-row">
					<span class="wpbridge-plan-grid-label wpbridge-plan-grid-label--stacked">
						<span class="dashicons dashicons-businessman"></span>
						<span>
							<?php esc_html_e( '优先技术支持', 'wpbridge' ); ?>
							<small class="wpbridge-plan-grid-hint"><?php esc_html_e( '工单 24h 内响应', 'wpbridge' ); ?></small>
						</span>
					</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-free">&mdash;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro">&mdash;</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-pro">&#10003;</span>
				</div>
				<?php if ( $current_plan !== 'all_access' ) : ?>
				<div class="wpbridge-plan-grid-row wpbridge-plan-grid-row--allaccess">
					<span class="wpbridge-plan-grid-label wpbridge-plan-grid-label--stacked">
						<span class="dashicons dashicons-star-filled"></span>
						<span>
							<a href="https://mall.weixiaoduo.com/item/wpbridge-all-access" target="_blank">All Access</a>
							<small class="wpbridge-plan-grid-hint"><?php esc_html_e( '获得全部产品更新 + 去限制补丁 + 原创中文语言包', 'wpbridge' ); ?></small>
						</span>
					</span>
					<span class="wpbridge-plan-grid-value wpbridge-plan-grid-value-allaccess"><?php esc_html_e( '一次订阅 · 全部产品 · 无限下载', 'wpbridge' ); ?></span>
				</div>
				<?php endif; ?>
			</div>
			<?php else : ?>
			<!-- 付费用户：当前权益列表 -->
			<div class="wpbridge-benefits">
				<?php
				$daily_label = $daily_downloads >= PHP_INT_MAX ? __( '无限', 'wpbridge' ) : $daily_downloads;
				$benefits    = [
					[ 'yes', 'cloud', __( '自定义更新源', 'wpbridge' ), __( '无限', 'wpbridge' ), __( '私有仓库、自托管服务器随意连', 'wpbridge' ) ],
					[ 'yes', 'store', __( '供应商连接', 'wpbridge' ), __( '无限', 'wpbridge' ), __( '接入任意商业插件供应商', 'wpbridge' ) ],
					[ 'yes', 'download', __( '商业产品下载', 'wpbridge' ), $daily_label, __( '从供应商处安装和更新商业插件/主题', 'wpbridge' ) ],
					[ in_array( 'bridge_api', $features, true ) ? 'yes' : 'no', 'rest-api', 'Bridge API', '', __( '管 N 个站只维护一份源', 'wpbridge' ) ],
					[ in_array( 'bridge_server', $features, true ) ? 'yes' : 'no', 'networking', 'Bridge Server', '', __( '子站连接主站，自动接收更新', 'wpbridge' ) ],
					[ in_array( 'priority_support', $features, true ) ? 'yes' : 'no', 'businessman', __( '优先技术支持', 'wpbridge' ), '', __( '工单 24h 内响应', 'wpbridge' ) ],
				];

				if ( $plan_name === 'all_access' ) {
					$benefits[] = [ 'yes', 'star-filled', 'All Access', __( '已激活', 'wpbridge' ), __( '全部插件和主题的持续更新推送', 'wpbridge' ) ];
					$benefits[] = [ 'yes', 'unlock', __( '去限制补丁', 'wpbridge' ), '', __( '解除域名和功能限制', 'wpbridge' ) ];
					$benefits[] = [ 'yes', 'translation', __( '中文翻译语言包', 'wpbridge' ), '', __( '商业产品完整汉化', 'wpbridge' ) ];
					$benefits[] = [ 'yes', 'media-document', __( '技术文档', 'wpbridge' ), '', __( '完整访问权限', 'wpbridge' ) ];
				}
				?>
				<?php foreach ( $benefits as $b ) : ?>
				<div class="wpbridge-benefit-item <?php echo $b[0] === 'yes' ? 'is-active' : 'is-inactive'; ?>">
					<span class="wpbridge-benefit-icon">
						<span class="dashicons dashicons-<?php echo $b[0] === 'yes' ? 'yes-alt' : 'minus'; ?>"></span>
					</span>
					<span class="wpbridge-benefit-text">
						<span class="wpbridge-benefit-name">
							<?php echo esc_html( $b[2] ); ?>
							<?php if ( ! empty( $b[3] ) ) : ?>
								<span class="wpbridge-benefit-value"><?php echo esc_html( $b[3] ); ?></span>
							<?php endif; ?>
						</span>
						<small class="wpbridge-benefit-desc"><?php echo esc_html( $b[4] ); ?></small>
					</span>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
</div>

<!-- 帮助文档 & 浏览更多 -->
<div class="wpbridge-overview-panels wpbridge-mt-5">
	<!-- 帮助文档 -->
	<div class="wpbridge-panel">
		<div class="wpbridge-panel-header">
			<h3>
				<span class="dashicons dashicons-book wpbridge-panel-header-icon"></span>
				<?php esc_html_e( '帮助文档', 'wpbridge' ); ?>
			</h3>
		</div>
		<div class="wpbridge-panel-body">
			<div class="wpbridge-link-list">
				<a href="https://wpcy.com/bridge" target="_blank" class="wpbridge-link-item">
					<span class="dashicons dashicons-media-document"></span>
					<?php esc_html_e( '快速入门指南', 'wpbridge' ); ?>
					<span class="dashicons dashicons-external wpbridge-link-external"></span>
				</a>
				<a href="https://wpcy.com/bridge#faq" target="_blank" class="wpbridge-link-item">
					<span class="dashicons dashicons-editor-help"></span>
					<?php esc_html_e( '常见问题', 'wpbridge' ); ?>
					<span class="dashicons dashicons-external wpbridge-link-external"></span>
				</a>
				<a href="https://wpcy.com/bridge#changelog" target="_blank" class="wpbridge-link-item">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( '更新日志', 'wpbridge' ); ?>
					<span class="dashicons dashicons-external wpbridge-link-external"></span>
				</a>
				<a href="https://wpcy.com/support" target="_blank" class="wpbridge-link-item">
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
				<span class="dashicons dashicons-admin-links wpbridge-panel-header-icon"></span>
				<?php esc_html_e( '浏览更多', 'wpbridge' ); ?>
			</h3>
		</div>
		<div class="wpbridge-panel-body">
			<div class="wpbridge-link-list">
				<a href="https://wpcy.com" target="_blank" class="wpbridge-link-item">
					<span class="dashicons dashicons-wordpress"></span>
					<?php esc_html_e( '文派叶子 🍃', 'wpbridge' ); ?>
					<span class="dashicons dashicons-external wpbridge-link-external"></span>
				</a>
				<a href="https://github.com/WenPai-org/wpbridge" target="_blank" class="wpbridge-link-item">
					<span class="dashicons dashicons-editor-code"></span>
					GitHub <?php esc_html_e( '仓库', 'wpbridge' ); ?>
					<span class="dashicons dashicons-external wpbridge-link-external"></span>
				</a>
				<a href="https://wpcy.com/plugins" target="_blank" class="wpbridge-link-item">
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
