<?php
/**
 * Plugin Name: WPBridge
 * Plugin URI: https://wenpai.org/plugins/wpbridge
 * Description: 自定义源桥接器 - 让用户完全控制 WordPress 的外部连接
 * Version: 1.2.3
 * Author: WenPai.org
 * Author URI: https://wenpai.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpbridge
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Update URI: https://updates.wenpai.net
 *
 * @package WPBridge
 */

namespace WPBridge;

// 防止直接访问.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 插件常量.
define( 'WPBRIDGE_VERSION', '1.2.3' );
define( 'WPBRIDGE_FILE', __FILE__ );
define( 'WPBRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPBRIDGE_URL', plugin_dir_url( __FILE__ ) );
define( 'WPBRIDGE_BASENAME', plugin_basename( __FILE__ ) );

// 加载自动加载器.
require_once WPBRIDGE_PATH . 'includes/Core/Loader.php';

/**
 * 初始化插件.
 *
 * @return Core\Plugin 插件单例.
 */
function wpbridge_init() {
	return Core\Plugin::get_instance();
}

/**
 * 激活当前站点的 WPBridge 数据与任务.
 */
function wpbridge_activate_site(): void {
	Core\Plugin::activate();

	$settings = new Core\Settings();
	$updater  = new Performance\BackgroundUpdater( $settings );
	$updater->schedule_update();
}

/**
 * 激活插件，网络激活时初始化每个既有站点.
 *
 * @param bool $network_wide 是否网络激活.
 */
function wpbridge_activate( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			wpbridge_activate_site();
			restore_current_blog();
		}
		return;
	}

	wpbridge_activate_site();
}

/**
 * 停用插件，网络停用时清理每个站点的运行期状态.
 *
 * @param bool $network_wide 是否网络停用.
 */
function wpbridge_deactivate( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			Core\Plugin::deactivate();
			restore_current_blog();
		}
		return;
	}

	Core\Plugin::deactivate();
}

// 激活与停用钩子.
register_activation_hook( __FILE__, __NAMESPACE__ . '\\wpbridge_activate' );

register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\wpbridge_deactivate' );

// WenPai 自更新检查器.
require_once WPBRIDGE_PATH . 'includes/class-wenpai-updater.php';
new \WPBridge_Updater( WPBRIDGE_BASENAME, WPBRIDGE_VERSION );

// 启动插件.
add_action( 'plugins_loaded', static function (): void {
	wpbridge_init();
} );

// 注册 WP-CLI 命令.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'bridge', CLI\BridgeCommand::class );
}
