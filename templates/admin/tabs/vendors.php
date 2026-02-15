<?php
/**
 * 供应商管理 Tab
 *
 * @package WPBridge
 * @since 0.9.8
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBridge\Admin\VendorAdmin;
use WPBridge\Core\Settings;

$settings_obj  = new Settings();
$vendor_admin  = new VendorAdmin( $settings_obj );
$vendor_data   = $vendor_admin->get_vendor_data();

$vendors       = $vendor_data['vendors'];
$custom        = $vendor_data['custom'];
$all_plugins   = $vendor_data['all_plugins'];
$stats         = $vendor_data['stats'];
$vendor_types  = $vendor_data['vendor_types'];
?>

<div class="wpbridge-vendors-section">
	<!-- 统计卡片 -->
	<div class="wpbridge-stats-row">
		<div class="wpbridge-stat-card">
			<span class="wpbridge-stat-number"><?php echo esc_html( count( $vendors ) ); ?></span>
			<span class="wpbridge-stat-label"><?php esc_html_e( '供应商', 'wpbridge' ); ?></span>
		</div>
		<div class="wpbridge-stat-card">
			<span class="wpbridge-stat-number"><?php echo esc_html( count( $all_plugins ) ); ?></span>
			<span class="wpbridge-stat-label"><?php esc_html_e( '可用插件', 'wpbridge' ); ?></span>
		</div>
		<div class="wpbridge-stat-card">
			<span class="wpbridge-stat-number"><?php echo esc_html( $stats['bridged_count'] ?? 0 ); ?></span>
			<span class="wpbridge-stat-label"><?php esc_html_e( '已桥接', 'wpbridge' ); ?></span>
		</div>
		<div class="wpbridge-stat-card">
			<span class="wpbridge-stat-number"><?php echo esc_html( count( $custom ) ); ?></span>
			<span class="wpbridge-stat-label"><?php esc_html_e( '自定义', 'wpbridge' ); ?></span>
		</div>
	</div>

	<!-- 供应商列表 -->
	<div class="wpbridge-section">
		<div class="wpbridge-section-header">
			<h3><?php esc_html_e( '供应商渠道', 'wpbridge' ); ?></h3>
			<button type="button" class="button button-primary" id="wpbridge-add-vendor-btn">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( '添加供应商', 'wpbridge' ); ?>
			</button>
		</div>

		<p class="wpbridge-section-desc">
			<?php esc_html_e( '接入第三方 GPL 插件分发商，获取更多商业插件的更新支持。', 'wpbridge' ); ?>
		</p>

		<?php if ( empty( $vendors ) ) : ?>
			<div class="wpbridge-empty-state">
				<span class="dashicons dashicons-store"></span>
				<p><?php esc_html_e( '暂无供应商，点击上方按钮添加', 'wpbridge' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped wpbridge-vendors-table">
				<thead>
					<tr>
						<th class="column-status"><?php esc_html_e( '状态', 'wpbridge' ); ?></th>
						<th class="column-name"><?php esc_html_e( '名称', 'wpbridge' ); ?></th>
						<th class="column-type"><?php esc_html_e( '类型', 'wpbridge' ); ?></th>
						<th class="column-plugins"><?php esc_html_e( '插件数', 'wpbridge' ); ?></th>
						<th class="column-actions"><?php esc_html_e( '操作', 'wpbridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vendors as $vendor_id => $vendor ) : ?>
						<tr data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>">
							<td class="column-status">
								<label class="wpbridge-toggle">
									<input type="checkbox"
										class="wpbridge-vendor-toggle"
										data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>"
										<?php checked( ! empty( $vendor['enabled'] ) ); ?>>
									<span class="wpbridge-toggle-slider"></span>
								</label>
							</td>
							<td class="column-name">
								<strong><?php echo esc_html( $vendor['name'] ); ?></strong>
								<div class="row-actions">
									<span class="wpbridge-vendor-url">
										<?php echo esc_html( $vendor['api_url'] ?? '' ); ?>
									</span>
								</div>
							</td>
							<td class="column-type">
								<?php echo esc_html( $vendor_types[ $vendor['type'] ] ?? $vendor['type'] ); ?>
							</td>
							<td class="column-plugins">
								<span class="wpbridge-plugin-count">-</span>
							</td>
							<td class="column-actions">
								<button type="button" class="button wpbridge-test-vendor"
									data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>">
									<?php esc_html_e( '测试', 'wpbridge' ); ?>
								</button>
								<button type="button" class="button wpbridge-sync-vendor"
									data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>">
									<?php esc_html_e( '同步', 'wpbridge' ); ?>
								</button>
								<button type="button" class="button button-link-delete wpbridge-remove-vendor"
									data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>">
									<?php esc_html_e( '删除', 'wpbridge' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- 自定义插件 -->
	<div class="wpbridge-section">
		<div class="wpbridge-section-header">
			<h3><?php esc_html_e( '自定义插件', 'wpbridge' ); ?></h3>
			<button type="button" class="button" id="wpbridge-add-custom-btn">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( '添加插件', 'wpbridge' ); ?>
			</button>
		</div>

		<p class="wpbridge-section-desc">
			<?php esc_html_e( '手动添加不在官方列表或供应商渠道中的插件。', 'wpbridge' ); ?>
		</p>

		<?php if ( empty( $custom ) ) : ?>
			<div class="wpbridge-empty-state">
				<span class="dashicons dashicons-admin-plugins"></span>
				<p><?php esc_html_e( '暂无自定义插件', 'wpbridge' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped wpbridge-custom-table">
				<thead>
					<tr>
						<th class="column-slug"><?php esc_html_e( 'Slug', 'wpbridge' ); ?></th>
						<th class="column-name"><?php esc_html_e( '名称', 'wpbridge' ); ?></th>
						<th class="column-url"><?php esc_html_e( '更新地址', 'wpbridge' ); ?></th>
						<th class="column-actions"><?php esc_html_e( '操作', 'wpbridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $custom as $slug => $info ) : ?>
						<tr data-plugin-slug="<?php echo esc_attr( $slug ); ?>">
							<td class="column-slug">
								<code><?php echo esc_html( $slug ); ?></code>
							</td>
							<td class="column-name">
								<?php echo esc_html( $info['name'] ?? $slug ); ?>
							</td>
							<td class="column-url">
								<?php echo esc_html( $info['update_url'] ?? '-' ); ?>
							</td>
							<td class="column-actions">
								<button type="button" class="button button-link-delete wpbridge-remove-custom"
									data-plugin-slug="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( '删除', 'wpbridge' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- 可用插件列表 -->
	<div class="wpbridge-section">
		<div class="wpbridge-section-header">
			<h3><?php esc_html_e( '可用插件', 'wpbridge' ); ?></h3>
			<button type="button" class="button" id="wpbridge-sync-all-btn">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( '同步全部', 'wpbridge' ); ?>
			</button>
		</div>

		<p class="wpbridge-section-desc">
			<?php esc_html_e( '来自官方列表、供应商渠道和自定义的所有可桥接插件。', 'wpbridge' ); ?>
		</p>

		<?php if ( empty( $all_plugins ) ) : ?>
			<div class="wpbridge-empty-state">
				<span class="dashicons dashicons-admin-plugins"></span>
				<p><?php esc_html_e( '暂无可用插件，请添加供应商或同步官方列表', 'wpbridge' ); ?></p>
			</div>
		<?php else : ?>
			<div class="wpbridge-plugins-grid">
				<?php foreach ( $all_plugins as $slug => $info ) : ?>
					<div class="wpbridge-plugin-card" data-slug="<?php echo esc_attr( $slug ); ?>">
						<div class="wpbridge-plugin-header">
							<span class="wpbridge-plugin-name">
								<?php echo esc_html( $info['name'] ?? $slug ); ?>
							</span>
							<span class="wpbridge-plugin-source wpbridge-source-<?php echo esc_attr( $info['source'] ?? 'unknown' ); ?>">
								<?php
								$source_labels = [
									'official' => __( '官方', 'wpbridge' ),
									'vendor'   => __( '供应商', 'wpbridge' ),
									'custom'   => __( '自定义', 'wpbridge' ),
								];
								echo esc_html( $source_labels[ $info['source'] ] ?? $info['source'] );
								?>
							</span>
						</div>
						<div class="wpbridge-plugin-meta">
							<code><?php echo esc_html( $slug ); ?></code>
							<?php if ( ! empty( $info['vendor'] ) ) : ?>
								<span class="wpbridge-plugin-vendor">
									<?php echo esc_html( $info['vendor'] ); ?>
								</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<!-- 添加供应商弹窗 -->
<div id="wpbridge-vendor-modal" class="wpbridge-modal" style="display:none;">
	<div class="wpbridge-modal-content">
		<div class="wpbridge-modal-header">
			<h2><?php esc_html_e( '添加供应商', 'wpbridge' ); ?></h2>
			<button type="button" class="wpbridge-modal-close">&times;</button>
		</div>
		<div class="wpbridge-modal-body">
			<form id="wpbridge-vendor-form">
				<table class="form-table">
					<tr>
						<th><label for="vendor_id"><?php esc_html_e( '供应商 ID', 'wpbridge' ); ?></label></th>
						<td>
							<input type="text" id="vendor_id" name="vendor_id" class="regular-text" required
								pattern="[a-z0-9_-]+" placeholder="my-vendor">
							<p class="description"><?php esc_html_e( '唯一标识符，只能包含小写字母、数字、下划线和连字符', 'wpbridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="vendor_name"><?php esc_html_e( '名称', 'wpbridge' ); ?></label></th>
						<td>
							<input type="text" id="vendor_name" name="name" class="regular-text" required
								placeholder="<?php esc_attr_e( '我的供应商', 'wpbridge' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label for="vendor_type"><?php esc_html_e( '类型', 'wpbridge' ); ?></label></th>
						<td>
							<select id="vendor_type" name="type">
								<?php foreach ( $vendor_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="vendor_api_url"><?php esc_html_e( 'API 地址', 'wpbridge' ); ?></label></th>
						<td>
							<input type="url" id="vendor_api_url" name="api_url" class="regular-text" required
								placeholder="https://example.com">
							<p class="description"><?php esc_html_e( 'WooCommerce 商店的根地址', 'wpbridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="vendor_consumer_key"><?php esc_html_e( 'Consumer Key', 'wpbridge' ); ?></label></th>
						<td>
							<input type="text" id="vendor_consumer_key" name="consumer_key" class="regular-text"
								placeholder="ck_xxxxxxxx">
							<p class="description"><?php esc_html_e( 'WooCommerce REST API Consumer Key（可选）', 'wpbridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="vendor_consumer_secret"><?php esc_html_e( 'Consumer Secret', 'wpbridge' ); ?></label></th>
						<td>
							<input type="password" id="vendor_consumer_secret" name="consumer_secret" class="regular-text"
								placeholder="cs_xxxxxxxx">
							<p class="description"><?php esc_html_e( 'WooCommerce REST API Consumer Secret（可选）', 'wpbridge' ); ?></p>
						</td>
					</tr>
				</table>
			</form>
		</div>
		<div class="wpbridge-modal-footer">
			<button type="button" class="button wpbridge-modal-cancel"><?php esc_html_e( '取消', 'wpbridge' ); ?></button>
			<button type="button" class="button button-primary" id="wpbridge-save-vendor"><?php esc_html_e( '保存', 'wpbridge' ); ?></button>
		</div>
	</div>
</div>

<!-- 添加自定义插件弹窗 -->
<div id="wpbridge-custom-modal" class="wpbridge-modal" style="display:none;">
	<div class="wpbridge-modal-content">
		<div class="wpbridge-modal-header">
			<h2><?php esc_html_e( '添加自定义插件', 'wpbridge' ); ?></h2>
			<button type="button" class="wpbridge-modal-close">&times;</button>
		</div>
		<div class="wpbridge-modal-body">
			<form id="wpbridge-custom-form">
				<table class="form-table">
					<tr>
						<th><label for="custom_slug"><?php esc_html_e( '插件 Slug', 'wpbridge' ); ?></label></th>
						<td>
							<input type="text" id="custom_slug" name="plugin_slug" class="regular-text" required
								pattern="[a-z0-9_-]+" placeholder="my-plugin">
							<p class="description"><?php esc_html_e( '插件目录名称', 'wpbridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="custom_name"><?php esc_html_e( '名称', 'wpbridge' ); ?></label></th>
						<td>
							<input type="text" id="custom_name" name="name" class="regular-text"
								placeholder="<?php esc_attr_e( '我的插件', 'wpbridge' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label for="custom_url"><?php esc_html_e( '更新地址', 'wpbridge' ); ?></label></th>
						<td>
							<input type="url" id="custom_url" name="update_url" class="regular-text"
								placeholder="https://example.com/update.json">
							<p class="description"><?php esc_html_e( '插件更新检查地址（可选）', 'wpbridge' ); ?></p>
						</td>
					</tr>
				</table>
			</form>
		</div>
		<div class="wpbridge-modal-footer">
			<button type="button" class="button wpbridge-modal-cancel"><?php esc_html_e( '取消', 'wpbridge' ); ?></button>
			<button type="button" class="button button-primary" id="wpbridge-save-custom"><?php esc_html_e( '保存', 'wpbridge' ); ?></button>
		</div>
	</div>
</div>
