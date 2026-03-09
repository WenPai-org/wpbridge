<?php
/**
 * 渠道管理 Tab
 *
 * @package WPBridge
 * @since 1.2.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBridge\Commercial\Vendors\PresetRegistry;

// $bridge_manager 和 $subscription 由 main.php 提供
$vendors        = $bridge_manager->get_vendors();
$presets        = PresetRegistry::get_presets();

// 标记已激活的预设
foreach ( $presets as $preset_id => &$_preset ) {
	$_preset['activated'] = isset( $vendors[ $preset_id ] ) && ! empty( $vendors[ $preset_id ]['enabled'] );
}
unset( $_preset );

// 统计 Bridge API 连接数
$bridge_count = 0;
foreach ( $vendors as $_vc ) {
	if ( ( $_vc['type'] ?? '' ) === 'bridge_api' ) {
		$bridge_count++;
	}
}

$field_labels  = PresetRegistry::get_auth_field_labels();
$field_ph      = PresetRegistry::get_auth_field_placeholders();
$status_labels = PresetRegistry::get_status_labels();

// 自定义更新源
$custom_sources = array_filter( $sources, function( $s ) { return ! $s->is_preset; } );
$custom_count   = count( $custom_sources );
?>

<div class="wpbridge-vendors-section">
	<!-- 更新渠道 -->
	<div class="wpbridge-section">
		<div class="wpbridge-section-header">
			<h3>
				<span class="dashicons dashicons-store"></span>
				<?php esc_html_e( '更新渠道', 'wpbridge' ); ?>
			</h3>
		</div>

		<p class="wpbridge-section-desc wpbridge-section-desc-padded">
			<?php esc_html_e( '连接你购买插件的商店，自动获取已购产品的更新推送。', 'wpbridge' ); ?>
		</p>

		<div class="wpbridge-vendor-presets-grid">
			<?php foreach ( $presets as $preset_id => $preset ) :
				$is_activated  = ! empty( $preset['activated'] );
				$is_coming     = ( $preset['status'] ?? '' ) === 'coming_soon';
				$is_multi      = ! empty( $preset['multi_instance'] );
				$card_class    = 'wpbridge-vendor-preset-card';
				if ( $is_activated ) $card_class .= ' is-activated';
				if ( $is_coming ) $card_class .= ' is-coming-soon';

				// 已激活供应商的插件数和最后同步时间
				$vendor_data   = $vendors[ $preset_id ] ?? [];
				$plugin_count  = isset( $vendor_data['plugin_count'] ) ? (int) $vendor_data['plugin_count'] : null;
				$last_sync     = $vendor_data['last_sync'] ?? null;
			?>
				<div class="<?php echo esc_attr( $card_class ); ?>" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
					<div class="wpbridge-vendor-preset-header">
						<span class="dashicons <?php echo esc_attr( $preset['icon'] ?? 'dashicons-store' ); ?>"></span>
						<span class="wpbridge-vendor-preset-name"><?php echo esc_html( $preset['name'] ); ?></span>
						<div class="wpbridge-vendor-preset-badges">
							<span class="wpbridge-badge-preset"><?php esc_html_e( '预置', 'wpbridge' ); ?></span>
							<?php if ( $is_coming ) : ?>
								<span class="wpbridge-badge-coming-soon"><?php echo esc_html( $status_labels['coming_soon'] ); ?></span>
							<?php elseif ( $is_activated ) : ?>
								<span class="wpbridge-badge-active"><?php echo esc_html( $status_labels['activated'] ); ?></span>
							<?php elseif ( $is_multi ) : ?>
								<span class="wpbridge-badge-inactive"><?php echo esc_html( $bridge_count ); ?> <?php esc_html_e( '个连接', 'wpbridge' ); ?></span>
							<?php else : ?>
								<span class="wpbridge-badge-inactive"><?php echo esc_html( $status_labels['inactive'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="wpbridge-vendor-preset-desc">
						<?php echo esc_html( $preset['description'] ?? '' ); ?>
					</div>
					<?php if ( $is_activated && ! empty( $preset['subscription_vendor'] ) ) :
						$plan_label = $subscription['label'] ?? $subscription['plan'] ?? 'free';
						$is_free    = ( $subscription['plan'] ?? 'free' ) === 'free';
					?>
						<div class="wpbridge-subscription-status">
							<span><?php esc_html_e( '订阅', 'wpbridge' ); ?>:</span>
							<span class="wpbridge-subscription-badge <?php echo $is_free ? 'is-free' : ''; ?>">
								<?php echo esc_html( $plan_label ); ?>
							</span>
							<button type="button" class="wpbridge-refresh-subscription" title="<?php esc_attr_e( '刷新订阅状态', 'wpbridge' ); ?>">
								<span class="dashicons dashicons-update"></span>
							</button>
						</div>
					<?php endif; ?>
					<?php if ( $is_activated && null !== $plugin_count ) : ?>
						<div class="wpbridge-vendor-preset-meta">
							<span class="wpbridge-vendor-meta-item">
								<span class="dashicons dashicons-admin-plugins"></span>
								<?php
								printf(
									/* translators: %d: number of plugins */
									esc_html__( '%d 个插件', 'wpbridge' ),
									$plugin_count
								);
								?>
							</span>
							<?php if ( $last_sync ) : ?>
								<span class="wpbridge-vendor-meta-item">
									<span class="dashicons dashicons-clock"></span>
									<?php echo esc_html( human_time_diff( $last_sync ) ); ?><?php esc_html_e( '前同步', 'wpbridge' ); ?>
								</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="wpbridge-vendor-preset-actions">
						<?php if ( $is_coming ) : ?>
							<!-- 即将上线，无操作按钮 -->
						<?php elseif ( $is_multi ) : ?>
							<?php if ( $is_feature_locked( 'bridge_api' ) ) : ?>
								<button type="button" class="wpbridge-btn wpbridge-btn-primary" disabled>
									<span class="dashicons dashicons-lock"></span>
									<?php esc_html_e( '需要 Pro', 'wpbridge' ); ?>
								</button>
							<?php else : ?>
								<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-add-bridge-vendor-btn">
									<span class="dashicons dashicons-plus-alt2"></span>
									<?php esc_html_e( '添加连接', 'wpbridge' ); ?>
								</button>
							<?php endif; ?>
						<?php elseif ( $is_activated ) : ?>
							<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-vendor" data-vendor-id="<?php echo esc_attr( $preset_id ); ?>">
								<?php esc_html_e( '测试', 'wpbridge' ); ?>
							</button>
							<button type="button" class="wpbridge-btn wpbridge-btn-danger wpbridge-btn-sm wpbridge-deactivate-preset" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
								<?php esc_html_e( '停用', 'wpbridge' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-activate-preset-btn" data-preset-id="<?php echo esc_attr( $preset_id ); ?>">
								<?php esc_html_e( '激活', 'wpbridge' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- 自定义更新源（折叠） -->
	<div class="wpbridge-section wpbridge-mt-6">
		<div class="wpbridge-section-header wpbridge-collapsible-header is-collapsed"
			 onclick="var p=this.parentElement.querySelector('.wpbridge-collapsible-content');p.style.display=p.style.display==='none'?'':'none';this.classList.toggle('is-collapsed');">
			<h3>
				<span class="dashicons dashicons-cloud"></span>
				<?php esc_html_e( '自定义更新源', 'wpbridge' ); ?>
				<?php if ( $custom_count > 0 ) : ?>
					<span class="wpbridge-badge-inactive"><?php echo esc_html( $custom_count ); ?></span>
				<?php endif; ?>
			</h3>
			<span class="dashicons dashicons-arrow-down-alt2 wpbridge-collapse-icon"></span>
		</div>
		<div class="wpbridge-collapsible-content" style="display: none;">
			<p class="wpbridge-section-desc wpbridge-section-desc-padded">
				<?php esc_html_e( '手动配置自定义更新源，连接私有仓库或自托管服务器。', 'wpbridge' ); ?>
			</p>

			<div class="wpbridge-section-actions wpbridge-section-desc-padded">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=add' ) ); ?>" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( '添加更新源', 'wpbridge' ); ?>
				</a>
			</div>

			<?php if ( empty( $custom_sources ) ) : ?>
				<div class="wpbridge-empty-state">
					<span class="dashicons dashicons-cloud"></span>
					<p><?php esc_html_e( '暂无自定义更新源', 'wpbridge' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '名称', 'wpbridge' ); ?></th>
							<th><?php esc_html_e( 'URL', 'wpbridge' ); ?></th>
							<th><?php esc_html_e( '状态', 'wpbridge' ); ?></th>
							<th><?php esc_html_e( '操作', 'wpbridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $custom_sources as $source ) : ?>
							<tr data-source-id="<?php echo esc_attr( $source->id ); ?>">
								<td>
									<strong><?php echo esc_html( $source->name ?: $source->id ); ?></strong>
								</td>
								<td class="wpbridge-text-mono"><?php echo esc_html( $source->api_url ); ?></td>
								<td>
									<label class="wpbridge-toggle">
										<input type="checkbox" class="wpbridge-toggle-source"
											<?php checked( $source->enabled ); ?>
											data-source-id="<?php echo esc_attr( $source->id ); ?>">
										<span class="wpbridge-toggle-track"></span>
									</label>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=edit&source=' . $source->id ) ); ?>"
									   class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm">
										<?php esc_html_e( '编辑', 'wpbridge' ); ?>
									</a>
									<button type="button" class="wpbridge-btn wpbridge-btn-danger wpbridge-btn-sm wpbridge-delete-source"
										data-source-id="<?php echo esc_attr( $source->id ); ?>">
										<?php esc_html_e( '删除', 'wpbridge' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
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
			<h2 id="wpbridge-preset-modal-title"><?php esc_html_e( '连接更新渠道', 'wpbridge' ); ?></h2>
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
