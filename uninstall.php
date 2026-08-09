<?php
/**
 * WPBridge 卸载脚本
 *
 * @package WPBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 删除当前站点的全部插件选项（含注册表、绑定、迁移、密钥和 slug 映射）。
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wpbridge_' ) . '%'
	)
);

// WordPress transient 以独立前缀保存，不会被上面的 wpbridge_ 前缀覆盖。
foreach ( [ '_transient_wpbridge_', '_transient_timeout_wpbridge_', '_site_transient_wpbridge_', '_site_transient_timeout_wpbridge_' ] as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
}

wp_clear_scheduled_hook( 'wpbridge_update_sources' );

if ( wp_using_ext_object_cache() ) {
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'wpbridge' );
	} else {
		wp_cache_delete( 'wpbridge', 'wpbridge' );
	}
}
