<?php
/**
 * Plugin Name: WPBridge
 * Plugin URI: https://wenpai.org/plugins/wpbridge
 * Description: 自定义源桥接器 - 让用户完全控制 WordPress 的外部连接
 * Version: 0.9.1
 * Author: WenPai.org
 * Author URI: https://wenpai.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpbridge
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 *
 * @package WPBridge
 */

namespace WPBridge;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 插件常量
define( 'WPBRIDGE_VERSION', '0.9.1' );
define( 'WPBRIDGE_FILE', __FILE__ );
define( 'WPBRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPBRIDGE_URL', plugin_dir_url( __FILE__ ) );
define( 'WPBRIDGE_BASENAME', plugin_basename( __FILE__ ) );

// 加载自动加载器
require_once WPBRIDGE_PATH . 'includes/Core/Loader.php';

// 初始化插件
function wpbridge_init() {
    return Core\Plugin::get_instance();
}

// 激活钩子
register_activation_hook( __FILE__, function() {
    Core\Plugin::activate();

    // 调度后台更新任务
    $settings = new Core\Settings();
    $updater  = new Performance\BackgroundUpdater( $settings );
    $updater->schedule_update();
} );

// 停用钩子
register_deactivation_hook( __FILE__, function() {
    Core\Plugin::deactivate();
} );

// 启动插件
add_action( 'plugins_loaded', __NAMESPACE__ . '\\wpbridge_init' );

// 注册 WP-CLI 命令
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'bridge', CLI\BridgeCommand::class );
}
