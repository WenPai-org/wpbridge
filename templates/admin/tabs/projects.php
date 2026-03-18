<?php
/**
 * 项目管理 Tab 内容
 *
 * 方案 B：项目优先架构 - 显示已安装的插件/主题列表
 *
 * @package WPBridge
 * @since 0.6.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBridge\Core\SourceRegistry;
use WPBridge\Core\ItemSourceManager;
use WPBridge\Core\DefaultsManager;

// 获取管理器实例
$source_registry  = new SourceRegistry();
$item_manager     = new ItemSourceManager( $source_registry );
$defaults_manager = new DefaultsManager();

// 获取所有可用源
$all_sources = $source_registry->get_enabled();

// 获取已安装的插件
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$installed_plugins = get_plugins();

// 获取已安装的主题
$installed_themes = wp_get_themes();

// 当前子 Tab - 白名单验证
$allowed_subtabs = [ 'sources', 'plugins', 'themes' ];
$current_subtab  = 'sources';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 仅用于 UI 显示
if ( isset( $_GET['subtab'] ) && in_array( $_GET['subtab'], $allowed_subtabs, true ) ) {
	$current_subtab = $_GET['subtab'];
}
?>

<!-- 子 Tab 导航 -->
<div class="wpbridge-subtabs">
	<a href="#" class="wpbridge-subtab <?php echo $current_subtab === 'sources' ? 'wpbridge-subtab-active' : ''; ?>" data-subtab="sources">
		<span class="dashicons dashicons-cloud"></span>
		<?php esc_html_e( '更新源', 'wpbridge' ); ?>
	</a>
	<a href="#" class="wpbridge-subtab <?php echo $current_subtab === 'plugins' ? 'wpbridge-subtab-active' : ''; ?>" data-subtab="plugins">
		<span class="dashicons dashicons-admin-plugins"></span>
		<?php esc_html_e( '插件', 'wpbridge' ); ?>
		<span class="wpbridge-subtab-count"><?php echo count( $installed_plugins ); ?></span>
	</a>
	<a href="#" class="wpbridge-subtab <?php echo $current_subtab === 'themes' ? 'wpbridge-subtab-active' : ''; ?>" data-subtab="themes">
		<span class="dashicons dashicons-admin-appearance"></span>
		<?php esc_html_e( '主题', 'wpbridge' ); ?>
		<span class="wpbridge-subtab-count"><?php echo count( $installed_themes ); ?></span>
	</a>
</div>

<!-- 更新源 -->
<div id="subtab-sources" class="wpbridge-subtab-pane <?php echo $current_subtab === 'sources' ? 'wpbridge-subtab-pane-active' : ''; ?>">
	<?php require WPBRIDGE_PATH . 'templates/admin/partials/sources-list.php'; ?>
</div>

<!-- 插件列表 -->
<div id="subtab-plugins" class="wpbridge-subtab-pane <?php echo $current_subtab === 'plugins' ? 'wpbridge-subtab-pane-active' : ''; ?>">
	<?php require WPBRIDGE_PATH . 'templates/admin/partials/project-list-plugins.php'; ?>
</div>

<!-- 主题列表 -->
<div id="subtab-themes" class="wpbridge-subtab-pane <?php echo $current_subtab === 'themes' ? 'wpbridge-subtab-pane-active' : ''; ?>">
	<?php require WPBRIDGE_PATH . 'templates/admin/partials/project-list-themes.php'; ?>
</div>
