<?php
/**
 * 供应商管理 Tab
 *
 * @package WPBridge
 * @since 1.2.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBridge\Commercial\Vendors\PresetRegistry;
use WPBridge\Core\SourceRegistry;
use WPBridge\Core\ItemSourceManager;

// $bridge_manager 和 $subscription 由 main.php 提供
$vendors = $bridge_manager->get_vendors();
$presets = PresetRegistry::get_presets();

// 标记已激活的预设
foreach ( $presets as $preset_id => &$_preset ) {
	$_preset['activated'] = isset( $vendors[ $preset_id ] ) && ! empty( $vendors[ $preset_id ]['enabled'] );
}
unset( $_preset );

// 统计 Bridge API 连接数
$bridge_count = 0;
foreach ( $vendors as $_vc ) {
	if ( ( $_vc['type'] ?? '' ) === 'bridge_api' ) {
		++$bridge_count;
	}
}

$field_labels  = PresetRegistry::get_auth_field_labels();
$field_ph      = PresetRegistry::get_auth_field_placeholders();
$status_labels = PresetRegistry::get_status_labels();

// 可用插件列表
$all_plugins = $bridge_manager->get_all_available_plugins();
?>

<div class="wpbridge-vendors-section">
	<!-- 供应商 -->
	<div class="wpbridge-section">
		<div class="wpbridge-section-header">
			<h3>
				<span class="dashicons dashicons-store"></span>
				<?php esc_html_e( '供应商', 'wpbridge' ); ?>
			</h3>
		</div>

		<p class="wpbridge-section-desc wpbridge-section-desc-padded">
			<?php esc_html_e( '连接你购买插件的商店，自动获取已购产品的更新推送。', 'wpbridge' ); ?>
		</p>

		<?php
		// 分组：商城类 vs Bridge API
		$marketplace_presets = [];
		$bridge_presets      = [];
		foreach ( $presets as $pid => $p ) {
			if ( ! empty( $p['multi_instance'] ) ) {
				$bridge_presets[ $pid ] = $p;
			} else {
				$marketplace_presets[ $pid ] = $p;
			}
		}
		?>

		<!-- 商城供应商（一排） -->
		<div class="wpbridge-vendor-presets-grid wpbridge-vendor-grid-2">
			<?php
			foreach ( $marketplace_presets as $preset_id => $preset ) :
				$is_activated = ! empty( $preset['activated'] );
				$is_coming    = ( $preset['status'] ?? '' ) === 'coming_soon';
				$card_class   = 'wpbridge-vendor-preset-card';
				if ( $is_activated ) {
					$card_class .= ' is-activated';
				}
				if ( $is_coming ) {
					$card_class .= ' is-coming-soon';
				}

				$vendor_data  = $vendors[ $preset_id ] ?? [];
				$plugin_count = isset( $vendor_data['plugin_count'] ) ? (int) $vendor_data['plugin_count'] : null;
				$last_sync    = $vendor_data['last_sync'] ?? null;
				$has_logo     = ! empty( $preset['logo'] );
				?>
				<div class="<?php echo esc_attr( $card_class ); ?>" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
					<!-- 卡片主体：Logo + 信息 + 操作 -->
					<div class="wpbridge-vendor-card-main">
						<div class="wpbridge-vendor-preset-logo">
							<?php
							if ( $has_logo ) :
								$logo_url = ( strpos( $preset['logo'], 'http' ) === 0 )
									? $preset['logo']
									: WPBRIDGE_URL . 'assets/images/' . $preset['logo'];
								?>
								<img src="<?php echo esc_url( $logo_url ); ?>"
									alt="<?php echo esc_attr( $preset['name'] ); ?>"
									class="wpbridge-vendor-logo-img">
							<?php else : ?>
								<span class="dashicons <?php echo esc_attr( $preset['icon'] ?? 'dashicons-store' ); ?> wpbridge-vendor-logo-icon"></span>
							<?php endif; ?>
						</div>

						<div class="wpbridge-vendor-preset-body">
							<div class="wpbridge-vendor-preset-header">
								<span class="wpbridge-vendor-preset-name"><?php echo esc_html( $preset['name'] ); ?></span>
								<?php if ( $is_coming ) : ?>
									<span class="wpbridge-badge-coming-soon"><?php echo esc_html( $status_labels['coming_soon'] ); ?></span>
								<?php elseif ( $is_activated ) : ?>
									<span class="wpbridge-badge-active"><?php echo esc_html( $status_labels['activated'] ); ?></span>
								<?php else : ?>
									<span class="wpbridge-badge-inactive"><?php echo esc_html( $status_labels['inactive'] ); ?></span>
								<?php endif; ?>
							</div>

							<div class="wpbridge-vendor-preset-desc">
								<?php echo esc_html( $preset['description'] ?? '' ); ?>
							</div>

							<?php if ( $is_activated && null !== $plugin_count ) : ?>
								<div class="wpbridge-vendor-preset-meta">
									<span class="wpbridge-vendor-meta-item">
										<span class="dashicons dashicons-admin-plugins"></span>
										<?php printf( esc_html__( '%d 个插件', 'wpbridge' ), $plugin_count ); ?>
									</span>
									<?php if ( $last_sync ) : ?>
										<span class="wpbridge-vendor-meta-item">
											<span class="dashicons dashicons-clock"></span>
											<?php echo esc_html( human_time_diff( $last_sync ) ); ?><?php esc_html_e( '前同步', 'wpbridge' ); ?>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>

						<div class="wpbridge-vendor-preset-actions">
							<?php if ( $is_coming ) : ?>
								<span class="wpbridge-text-muted"><?php esc_html_e( '敬请期待', 'wpbridge' ); ?></span>
							<?php elseif ( $is_activated ) : ?>
								<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-vendor" data-vendor-id="<?php echo esc_attr( $preset_id ); ?>">
									<?php esc_html_e( '测试', 'wpbridge' ); ?>
								</button>
								<button type="button" class="wpbridge-btn wpbridge-btn-danger wpbridge-btn-sm wpbridge-deactivate-preset" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
									<?php esc_html_e( '停用', 'wpbridge' ); ?>
								</button>
							<?php else : ?>
								<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm wpbridge-activate-preset-btn" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
									<?php esc_html_e( '添加密钥', 'wpbridge' ); ?>
								</button>
								<?php if ( ! empty( $preset['api_url'] ) ) : ?>
									<a href="<?php echo esc_url( rtrim( $preset['api_url'], '/' ) . '/my-account/api-keys' ); ?>"
										target="_blank"
										class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm">
										<?php esc_html_e( '获取 ↗', 'wpbridge' ); ?>
									</a>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>

					<!-- 激活后的订阅底栏 -->
					<?php
					if ( $is_activated && ! empty( $preset['subscription_vendor'] ) ) :
						$plan_label = $subscription['label'] ?? $subscription['plan'] ?? 'free';
						$plan_name  = $subscription['plan'] ?? 'free';
						$is_free    = $plan_name === 'free';
						$sub_status = $subscription['status'] ?? '';
						$checked_at = $subscription['checked_at'] ?? 0;
						$features   = $subscription['features'] ?? [];
						$daily_dl   = $subscription['daily_downloads'] ?? 0;
						?>
					<div class="wpbridge-vendor-card-footer">
						<div class="wpbridge-vendor-footer-left">
							<span class="wpbridge-subscription-badge <?php echo $is_free ? 'is-free' : ''; ?>">
								<?php echo esc_html( $plan_label ); ?>
							</span>
							<?php if ( $sub_status === 'active' && ! $is_free ) : ?>
								<span class="wpbridge-subscription-dot"></span>
								<span class="wpbridge-subscription-active"><?php esc_html_e( '有效', 'wpbridge' ); ?></span>
							<?php endif; ?>
							<?php if ( ! $is_free ) : ?>
								<span class="wpbridge-footer-sep"></span>
								<?php if ( $daily_dl >= PHP_INT_MAX ) : ?>
									<span class="wpbridge-vendor-perk"><?php esc_html_e( '无限下载', 'wpbridge' ); ?></span>
								<?php else : ?>
									<span class="wpbridge-vendor-perk"><?php printf( esc_html__( '%d 次/天', 'wpbridge' ), $daily_dl ); ?></span>
								<?php endif; ?>
								<?php if ( in_array( 'bridge_api', $features, true ) ) : ?>
									<span class="wpbridge-vendor-perk">API</span>
								<?php endif; ?>
								<?php if ( in_array( 'bridge_server', $features, true ) ) : ?>
									<span class="wpbridge-vendor-perk">Server</span>
								<?php endif; ?>
								<?php if ( in_array( 'priority_support', $features, true ) ) : ?>
									<span class="wpbridge-vendor-perk"><?php esc_html_e( '优先支持', 'wpbridge' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="wpbridge-footer-sep"></span>
								<a href="https://mall.weixiaoduo.com/item/wpbridge-pro" target="_blank" class="wpbridge-vendor-upgrade-link">
									<?php esc_html_e( '升级 Pro', 'wpbridge' ); ?>
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</a>
							<?php endif; ?>
						</div>
						<div class="wpbridge-vendor-footer-right">
							<?php if ( $checked_at > 0 ) : ?>
								<span class="wpbridge-subscription-checked">
									<?php printf( esc_html__( '%s前验证', 'wpbridge' ), esc_html( human_time_diff( $checked_at ) ) ); ?>
								</span>
							<?php endif; ?>
							<button type="button" class="wpbridge-refresh-subscription" title="<?php esc_attr_e( '刷新', 'wpbridge' ); ?>">
								<span class="dashicons dashicons-update"></span>
							</button>
						</div>
					</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Bridge API（单独一排） -->
		<?php
		foreach ( $bridge_presets as $preset_id => $preset ) :
			$is_activated = ! empty( $preset['activated'] );
			$card_class   = 'wpbridge-vendor-preset-card wpbridge-vendor-bridge-card';
			if ( $is_activated ) {
				$card_class .= ' is-activated';
			}

			$vendor_data  = $vendors[ $preset_id ] ?? [];
			$plugin_count = isset( $vendor_data['plugin_count'] ) ? (int) $vendor_data['plugin_count'] : null;
			$last_sync    = $vendor_data['last_sync'] ?? null;
			?>
			<div class="wpbridge-vendor-bridge-row">
				<div class="<?php echo esc_attr( $card_class ); ?>" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
					<div class="wpbridge-vendor-card-main">
						<div class="wpbridge-vendor-preset-logo">
							<span class="dashicons <?php echo esc_attr( $preset['icon'] ?? 'dashicons-admin-links' ); ?> wpbridge-vendor-logo-icon"></span>
						</div>

						<div class="wpbridge-vendor-preset-body">
							<div class="wpbridge-vendor-preset-header">
								<span class="wpbridge-vendor-preset-name"><?php echo esc_html( $preset['name'] ); ?></span>
								<span class="wpbridge-badge-inactive"><?php echo esc_html( $bridge_count ); ?> <?php esc_html_e( '个连接', 'wpbridge' ); ?></span>
							</div>

							<div class="wpbridge-vendor-preset-desc">
								<?php echo esc_html( $preset['description'] ?? '' ); ?>
							</div>

							<?php if ( $plugin_count !== null ) : ?>
								<div class="wpbridge-vendor-preset-meta">
									<span class="wpbridge-vendor-meta-item">
										<span class="dashicons dashicons-admin-plugins"></span>
										<?php printf( esc_html__( '%d 个插件', 'wpbridge' ), $plugin_count ); ?>
									</span>
									<?php if ( $last_sync ) : ?>
										<span class="wpbridge-vendor-meta-item">
											<span class="dashicons dashicons-clock"></span>
											<?php echo esc_html( human_time_diff( $last_sync ) ); ?><?php esc_html_e( '前同步', 'wpbridge' ); ?>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>

						<div class="wpbridge-vendor-preset-actions">
							<?php if ( $is_feature_locked( 'bridge_api' ) ) : ?>
								<a href="https://mall.weixiaoduo.com/item/wpbridge-pro"
									target="_blank"
									class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm">
									<?php esc_html_e( '升级专业版  ↗', 'wpbridge' ); ?>
								</a>
						<?php else : ?>
							<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm wpbridge-add-bridge-vendor-btn">
								<span class="dashicons dashicons-plus-alt2"></span>
								<?php esc_html_e( '添加连接', 'wpbridge' ); ?>
							</button>
						<?php endif; ?>
						</div>
					</div><!-- .wpbridge-vendor-card-main -->
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- 可用插件 -->
	<div class="wpbridge-section wpbridge-mt-6">
		<div class="wpbridge-section-header">
			<h3>
				<span class="dashicons dashicons-plugins-checked"></span>
				<?php esc_html_e( '可用插件', 'wpbridge' ); ?>
				<?php if ( ! empty( $all_plugins ) ) : ?>
					<span class="wpbridge-badge-inactive"><?php echo esc_html( count( $all_plugins ) ); ?></span>
				<?php endif; ?>
			</h3>
		</div>

		<?php if ( empty( $all_plugins ) ) : ?>
			<div class="wpbridge-empty-state">
				<span class="dashicons dashicons-admin-plugins"></span>
				<p><?php esc_html_e( '暂无可用插件，请先连接供应商或添加自定义更新源', 'wpbridge' ); ?></p>
			</div>
			<?php
		else :
			// 按来源分组统计
			$grouped = [];
			foreach ( $all_plugins as $slug => $info ) {
				$group_key = $info['vendor'] ?? ( ( $info['source'] ?? '' ) === 'custom' ? __( '自定义', 'wpbridge' ) : __( '其他', 'wpbridge' ) );
				if ( ! isset( $grouped[ $group_key ] ) ) {
					$grouped[ $group_key ] = [];
				}
				$grouped[ $group_key ][ $slug ] = $info;
			}

			// 已安装插件 slug 列表
			$installed_plugins = get_plugins();
			$installed_slugs   = [];
			$installed_map     = []; // slug => plugin_file
			foreach ( $installed_plugins as $file => $data ) {
				$dir_slug                                 = dirname( $file );
				$installed_slugs[]                        = strtolower( $dir_slug );
				$installed_map[ strtolower( $dir_slug ) ] = $file;
				// 也记录插件名称做模糊匹配
				$name_slug = sanitize_title( $data['Name'] ?? '' );
				if ( $name_slug && $name_slug !== $dir_slug ) {
					$installed_slugs[]           = $name_slug;
					$installed_map[ $name_slug ] = $file;
				}
			}

			// 读取已绑定到供应商源的项目
			$_sr          = new SourceRegistry();
			$_im          = new ItemSourceManager( $_sr );
			$vendor_bound = []; // slug => vendor_id
			foreach ( $installed_map as $_slug => $_pfile ) {
				$_conf = $_im->get( 'plugin:' . $_pfile );
				if ( $_conf && ( $_conf['mode'] ?? '' ) === ItemSourceManager::MODE_CUSTOM ) {
					foreach ( ( $_conf['source_ids'] ?? [] ) as $_sk => $_pri ) {
						if ( strpos( $_sk, 'vendor_' ) === 0 ) {
							$vendor_bound[ $_slug ] = substr( $_sk, 7 );
							break;
						}
					}
				}
			}
			// 已安装主题 slug 列表
			$installed_themes = wp_get_themes();
			foreach ( $installed_themes as $theme_slug => $theme_obj ) {
				$installed_slugs[]                          = strtolower( $theme_slug );
				$installed_map[ strtolower( $theme_slug ) ] = 'theme:' . $theme_slug;
			}
			$installed_slugs = array_unique( $installed_slugs );

			// 主题也检查供应商绑定
			foreach ( $installed_themes as $theme_slug => $theme_obj ) {
				$_conf = $_im->get( 'theme:' . $theme_slug );
				if ( $_conf && ( $_conf['mode'] ?? '' ) === ItemSourceManager::MODE_CUSTOM ) {
					foreach ( ( $_conf['source_ids'] ?? [] ) as $_sk => $_pri ) {
						if ( strpos( $_sk, 'vendor_' ) === 0 ) {
							$vendor_bound[ strtolower( $theme_slug ) ] = substr( $_sk, 7 );
							break;
						}
					}
				}
			}
			?>
			<!-- 来源筛选 Tab -->
			<nav class="wpbridge-plugin-filter-tabs">
				<button type="button" class="wpbridge-plugin-filter-tab is-active" data-filter="all">
					<?php esc_html_e( '全部', 'wpbridge' ); ?>
					<span class="wpbridge-filter-count"><?php echo esc_html( count( $all_plugins ) ); ?></span>
				</button>
				<?php foreach ( $grouped as $group_name => $group_plugins ) : ?>
					<button type="button" class="wpbridge-plugin-filter-tab" data-filter="<?php echo esc_attr( sanitize_title( $group_name ) ); ?>">
						<?php echo esc_html( $group_name ); ?>
						<span class="wpbridge-filter-count"><?php echo esc_html( count( $group_plugins ) ); ?></span>
					</button>
				<?php endforeach; ?>
			</nav>

			<!-- 批量操作栏 -->
			<div class="wpbridge-plugin-bulk-bar" style="display:none;">
				<label class="wpbridge-bulk-select-all">
					<input type="checkbox" class="wpbridge-bulk-check-all">
					<?php esc_html_e( '全选已安装', 'wpbridge' ); ?>
				</label>
				<span class="wpbridge-bulk-count"></span>
				<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm wpbridge-bulk-bind">
					<?php esc_html_e( '批量接管更新', 'wpbridge' ); ?>
				</button>
				<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-bulk-unbind">
					<?php esc_html_e( '批量取消接管', 'wpbridge' ); ?>
				</button>
			</div>

			<!-- 插件列表 -->
			<div class="wpbridge-plugin-list" data-limit="20">
				<?php
				$index = 0; foreach ( $all_plugins as $slug => $info ) :
					$is_installed = in_array( strtolower( $slug ), $installed_slugs, true );
					$group_id     = sanitize_title( $info['vendor'] ?? ( ( $info['source'] ?? '' ) === 'custom' ? __( '自定义', 'wpbridge' ) : __( '其他', 'wpbridge' ) ) );
					$version      = $info['version'] ?? '';
					$author       = $info['author'] ?? '';
					$tested       = $info['tested'] ?? '';
					$requires_php = $info['requires_php'] ?? '';
					$homepage     = $info['homepage'] ?? '';
					$vendor_label = $info['vendor'] ?? '';
					$hidden       = $index >= 20 ? ' is-overflow' : '';
					$vendor_id    = $info['vendor_id'] ?? '';
					$is_bound     = $is_installed && isset( $vendor_bound[ strtolower( $slug ) ] );
					$item_type    = 'plugin';
					if ( $is_installed && isset( $installed_map[ strtolower( $slug ) ] ) && strpos( $installed_map[ strtolower( $slug ) ], 'theme:' ) === 0 ) {
						$item_type = 'theme';
					}
					// vendor 和 author 去重
					$show_author = $author && $author !== $vendor_label;
					++$index;
					?>
					<div class="wpbridge-plugin-list-item<?php echo esc_attr( $hidden ); ?>" data-group="<?php echo esc_attr( $group_id ); ?>">
						<div class="wpbridge-plugin-list-main">
							<?php if ( $is_installed && ! empty( $vendor_id ) ) : ?>
								<input type="checkbox" class="wpbridge-bulk-check"
									data-slug="<?php echo esc_attr( $slug ); ?>"
									data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>"
									data-item-type="<?php echo esc_attr( $item_type ); ?>"
									data-bound="<?php echo $is_bound ? '1' : '0'; ?>">
							<?php endif; ?>
							<span class="wpbridge-plugin-list-name"><?php echo esc_html( $info['name'] ?? $slug ); ?></span>
							<?php if ( $version ) : ?>
								<span class="wpbridge-plugin-list-version">v<?php echo esc_html( $version ); ?></span>
							<?php endif; ?>
							<?php if ( $is_bound ) : ?>
								<span class="wpbridge-plugin-list-bound"><?php esc_html_e( '已接管', 'wpbridge' ); ?></span>
							<?php elseif ( $is_installed ) : ?>
								<span class="wpbridge-plugin-list-installed"><?php esc_html_e( '已安装', 'wpbridge' ); ?></span>
							<?php endif; ?>
							<span class="wpbridge-plugin-list-actions">
								<?php if ( $is_installed && ! empty( $vendor_id ) ) : ?>
									<label class="wpbridge-vendor-update-toggle" title="<?php esc_attr_e( '接管更新', 'wpbridge' ); ?>">
										<input type="checkbox"
											class="wpbridge-bind-vendor-update"
											data-slug="<?php echo esc_attr( $slug ); ?>"
											data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>"
											data-item-type="<?php echo esc_attr( $item_type ); ?>"
											<?php checked( $is_bound ); ?>>
										<span class="wpbridge-toggle-track"></span>
										<span class="wpbridge-vendor-toggle-label"><?php esc_html_e( '接管更新', 'wpbridge' ); ?></span>
									</label>
								<?php endif; ?>
								<?php if ( ! $is_installed ) : ?>
									<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-xs wpbridge-install-plugin"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-vendor-id="<?php echo esc_attr( $info['vendor_id'] ?? '' ); ?>">
										<span class="dashicons dashicons-download"></span>
										<?php esc_html_e( '安装', 'wpbridge' ); ?>
									</button>
								<?php endif; ?>
								<?php if ( $homepage ) : ?>
									<a href="<?php echo esc_url( $homepage ); ?>" target="_blank" class="wpbridge-plugin-action-link" title="<?php esc_attr_e( '查看产品', 'wpbridge' ); ?>">
										<span class="dashicons dashicons-external"></span>
									</a>
								<?php endif; ?>
							</span>
						</div>
						<div class="wpbridge-plugin-list-meta">
							<?php if ( $vendor_label ) : ?>
								<span class="wpbridge-plugin-list-vendor"><?php echo esc_html( $vendor_label ); ?></span>
							<?php endif; ?>
							<?php if ( $show_author ) : ?>
								<span class="wpbridge-plugin-list-author"><?php echo esc_html( $author ); ?></span>
							<?php endif; ?>
							<?php if ( $tested ) : ?>
								<span class="wpbridge-plugin-list-compat"><?php printf( esc_html__( '已测 WP %s', 'wpbridge' ), esc_html( $tested ) ); ?></span>
							<?php endif; ?>
							<?php if ( $requires_php ) : ?>
								<span class="wpbridge-plugin-list-compat">PHP <?php echo esc_html( $requires_php ); ?>+</span>
							<?php endif; ?>
							<code class="wpbridge-plugin-list-slug"><?php echo esc_html( $slug ); ?></code>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( count( $all_plugins ) > 20 ) : ?>
				<div class="wpbridge-plugin-list-more">
					<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-show-more-plugins">
						<?php printf( esc_html__( '显示全部 %d 个插件', 'wpbridge' ), count( $all_plugins ) ); ?>
					</button>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<!-- 删除确认表单 -->
<form id="wpbridge-delete-form" method="post" style="display: none;">
	<?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
	<input type="hidden" name="wpbridge_action" value="delete_source">
	<input type="hidden" name="source_id" id="wpbridge-delete-source-id" value="">
</form>

<!-- 预设激活弹窗 -->
<div id="wpbridge-preset-modal" class="wpbridge-modal" style="display:none;">
	<div class="wpbridge-modal-content">
		<div class="wpbridge-modal-header">
			<h2 id="wpbridge-preset-modal-title"><?php esc_html_e( '连接供应商', 'wpbridge' ); ?></h2>
			<button type="button" class="wpbridge-modal-close">&times;</button>
		</div>
		<div class="wpbridge-modal-body">
			<form id="wpbridge-preset-form">
				<input type="hidden" id="preset_id" name="preset_id" value="">
				<div class="wpbridge-form-group">
					<label for="preset_email">
						<?php echo esc_html( $field_labels['email'] ); ?>
						<span class="required">*</span>
					</label>
					<input type="email" id="preset_email" name="email" required
						placeholder="<?php echo esc_attr( $field_ph['email'] ); ?>">
				</div>
				<div class="wpbridge-form-group">
					<label for="preset_license_key">
						<?php echo esc_html( $field_labels['license_key'] ); ?>
						<span class="required">*</span>
					</label>
					<input type="text" id="preset_license_key" name="license_key" required
						placeholder="<?php echo esc_attr( $field_ph['license_key'] ); ?>">
				</div>
			</form>
		</div>
		<div class="wpbridge-modal-footer">
			<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-modal-cancel"><?php esc_html_e( '取消', 'wpbridge' ); ?></button>
			<button type="button" class="wpbridge-btn wpbridge-btn-primary" id="wpbridge-save-preset"><?php esc_html_e( '激活', 'wpbridge' ); ?></button>
		</div>
	</div>
</div>

<!-- Bridge API 添加弹窗 -->
<div id="wpbridge-bridge-modal" class="wpbridge-modal" style="display:none;">
	<div class="wpbridge-modal-content">
		<div class="wpbridge-modal-header">
			<h2><?php esc_html_e( '添加 Bridge API 连接', 'wpbridge' ); ?></h2>
			<button type="button" class="wpbridge-modal-close">&times;</button>
		</div>
		<div class="wpbridge-modal-body">
			<form id="wpbridge-bridge-form">
				<div class="wpbridge-form-group">
					<label for="bridge_name">
						<?php esc_html_e( '名称', 'wpbridge' ); ?>
					</label>
					<input type="text" id="bridge_name" name="name"
						placeholder="<?php esc_attr_e( '我的 WPBridge 站点', 'wpbridge' ); ?>">
					<p class="wpbridge-form-hint"><?php esc_html_e( '可选，留空将使用域名作为名称', 'wpbridge' ); ?></p>
				</div>
				<div class="wpbridge-form-group">
					<label for="bridge_api_url">
						<?php echo esc_html( $field_labels['api_url'] ); ?>
						<span class="required">*</span>
					</label>
					<input type="url" id="bridge_api_url" name="api_url" required
						placeholder="<?php echo esc_attr( $field_ph['api_url'] ); ?>">
					<p class="wpbridge-form-hint"><?php esc_html_e( '目标 WPBridge 站点的地址', 'wpbridge' ); ?></p>
				</div>
				<div class="wpbridge-form-group">
					<label for="bridge_api_key">
						<?php echo esc_html( $field_labels['api_key'] ); ?>
						<span class="required">*</span>
					</label>
					<input type="password" id="bridge_api_key" name="api_key" required
						placeholder="<?php echo esc_attr( $field_ph['api_key'] ); ?>">
					<p class="wpbridge-form-hint"><?php esc_html_e( '在目标站点的云桥设置中生成的 API Key', 'wpbridge' ); ?></p>
				</div>
			</form>
		</div>
		<div class="wpbridge-modal-footer">
			<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-modal-cancel"><?php esc_html_e( '取消', 'wpbridge' ); ?></button>
			<button type="button" class="wpbridge-btn wpbridge-btn-primary" id="wpbridge-save-bridge"><?php esc_html_e( '添加', 'wpbridge' ); ?></button>
		</div>
	</div>
</div>
