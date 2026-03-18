<?php
/**
 * 默认规则配置部分模板
 *
 * @package WPBridge
 * @since 0.6.0
 * @var array $all_sources 所有可用源
 * @var SourceRegistry $source_registry 源注册表
 * @var DefaultsManager $defaults_manager 默认规则管理器
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBridge\Core\DefaultsManager;

// 获取当前默认规则
$defaults       = $defaults_manager->get_all();
$global_sources = $defaults['global']['source_order'] ?? [];
$plugin_sources = $defaults['plugin']['source_order'] ?? [];
$theme_sources  = $defaults['theme']['source_order'] ?? [];
?>

<div class="wpbridge-defaults-config">
	<div class="wpbridge-section">
		<h3 class="wpbridge-section-title">
			<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( '默认更新源配置', 'wpbridge' ); ?>
		</h3>
		<p class="wpbridge-section-desc">
			<?php esc_html_e( '配置插件和主题的默认更新源顺序。当项目未单独配置时，将按此顺序查找更新。', 'wpbridge' ); ?>
		</p>
	</div>

	<form method="post" id="wpbridge-defaults-form">
		<?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
		<input type="hidden" name="wpbridge_action" value="save_defaults">

		<!-- 全局默认 -->
		<div class="wpbridge-config-card">
			<div class="wpbridge-config-card-header">
				<h4><?php esc_html_e( '全局默认', 'wpbridge' ); ?></h4>
				<span class="wpbridge-config-card-desc"><?php esc_html_e( '适用于所有未单独配置的项目', 'wpbridge' ); ?></span>
			</div>
			<div class="wpbridge-config-card-body">
				<div class="wpbridge-source-order" id="wpbridge-global-sources" data-scope="global">
					<?php
					foreach ( $all_sources as $source ) :
						$priority = $global_sources[ $source['source_key'] ] ?? $source['default_priority'];
						?>
						<div class="wpbridge-source-order-item" data-source-key="<?php echo esc_attr( $source['source_key'] ); ?>">
							<span class="wpbridge-drag-handle dashicons dashicons-menu"></span>
							<span class="wpbridge-source-order-name"><?php echo esc_html( $source['name'] ); ?></span>
							<span class="wpbridge-source-order-type wpbridge-badge"><?php echo esc_html( $source['type'] ); ?></span>
							<label class="wpbridge-toggle wpbridge-toggle-sm">
								<input type="checkbox" name="global_sources[<?php echo esc_attr( $source['source_key'] ); ?>]"
										value="1" <?php checked( isset( $global_sources[ $source['source_key'] ] ) || empty( $global_sources ) ); ?>>
								<span class="wpbridge-toggle-track"></span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<!-- 插件默认 -->
		<div class="wpbridge-config-card">
			<div class="wpbridge-config-card-header">
				<h4><?php esc_html_e( '插件默认', 'wpbridge' ); ?></h4>
				<span class="wpbridge-config-card-desc"><?php esc_html_e( '覆盖全局默认，仅适用于插件', 'wpbridge' ); ?></span>
			</div>
			<div class="wpbridge-config-card-body">
				<label class="wpbridge-checkbox">
					<input type="checkbox" name="plugin_override" id="plugin_override"
							<?php checked( ! empty( $plugin_sources ) ); ?>>
					<span><?php esc_html_e( '为插件使用单独的默认源配置', 'wpbridge' ); ?></span>
				</label>
				<div class="wpbridge-source-order wpbridge-source-order-override" id="wpbridge-plugin-sources" data-scope="plugin"
					style="<?php echo empty( $plugin_sources ) ? 'display:none;' : ''; ?>">
					<?php
					foreach ( $all_sources as $source ) :
						$priority = $plugin_sources[ $source['source_key'] ] ?? $source['default_priority'];
						?>
						<div class="wpbridge-source-order-item" data-source-key="<?php echo esc_attr( $source['source_key'] ); ?>">
							<span class="wpbridge-drag-handle dashicons dashicons-menu"></span>
							<span class="wpbridge-source-order-name"><?php echo esc_html( $source['name'] ); ?></span>
							<span class="wpbridge-source-order-type wpbridge-badge"><?php echo esc_html( $source['type'] ); ?></span>
							<label class="wpbridge-toggle wpbridge-toggle-sm">
								<input type="checkbox" name="plugin_sources[<?php echo esc_attr( $source['source_key'] ); ?>]"
										value="1" <?php checked( isset( $plugin_sources[ $source['source_key'] ] ) ); ?>>
								<span class="wpbridge-toggle-track"></span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<!-- 主题默认 -->
		<div class="wpbridge-config-card">
			<div class="wpbridge-config-card-header">
				<h4><?php esc_html_e( '主题默认', 'wpbridge' ); ?></h4>
				<span class="wpbridge-config-card-desc"><?php esc_html_e( '覆盖全局默认，仅适用于主题', 'wpbridge' ); ?></span>
			</div>
			<div class="wpbridge-config-card-body">
				<label class="wpbridge-checkbox">
					<input type="checkbox" name="theme_override" id="theme_override"
							<?php checked( ! empty( $theme_sources ) ); ?>>
					<span><?php esc_html_e( '为主题使用单独的默认源配置', 'wpbridge' ); ?></span>
				</label>
				<div class="wpbridge-source-order wpbridge-source-order-override" id="wpbridge-theme-sources" data-scope="theme"
					style="<?php echo empty( $theme_sources ) ? 'display:none;' : ''; ?>">
					<?php
					foreach ( $all_sources as $source ) :
						$priority = $theme_sources[ $source['source_key'] ] ?? $source['default_priority'];
						?>
						<div class="wpbridge-source-order-item" data-source-key="<?php echo esc_attr( $source['source_key'] ); ?>">
							<span class="wpbridge-drag-handle dashicons dashicons-menu"></span>
							<span class="wpbridge-source-order-name"><?php echo esc_html( $source['name'] ); ?></span>
							<span class="wpbridge-source-order-type wpbridge-badge"><?php echo esc_html( $source['type'] ); ?></span>
							<label class="wpbridge-toggle wpbridge-toggle-sm">
								<input type="checkbox" name="theme_sources[<?php echo esc_attr( $source['source_key'] ); ?>]"
										value="1" <?php checked( isset( $theme_sources[ $source['source_key'] ] ) ); ?>>
								<span class="wpbridge-toggle-track"></span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="wpbridge-form-actions">
			<button type="submit" class="wpbridge-btn wpbridge-btn-primary">
				<span class="dashicons dashicons-saved"></span>
				<?php esc_html_e( '保存默认规则', 'wpbridge' ); ?>
			</button>
		</div>
	</form>
</div>
