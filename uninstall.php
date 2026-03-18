<?php
/**
 * WPBridge 卸载脚本
 *
 * 当用户从 WordPress 删除插件时执行
 *
 * @package WPBridge
 */

// 如果不是通过 WordPress 卸载，则退出
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 删除所有插件选项
delete_option( 'wpbridge_sources' );
delete_option( 'wpbridge_settings' );
delete_option( 'wpbridge_ai_settings' );
delete_option( 'wpbridge_logs' );
delete_option( 'wpbridge_activated' );
delete_option( 'wpbridge_admin_notices' );
delete_option( 'wpbridge_encryption_key' );
delete_option( 'wpbridge_source_groups' );
delete_option( 'wpbridge_version_locks' );
delete_option( 'wpbridge_notifications' );
delete_option( 'wpbridge_api' );

// 删除所有加密存储的数据
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wpbridge_secure_' ) . '%'
	)
);

// 删除所有 transient 缓存
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_wpbridge_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_wpbridge_' ) . '%'
	)
);

// 清除定时任务
wp_clear_scheduled_hook( 'wpbridge_update_sources' );

// 清除对象缓存
if ( wp_using_ext_object_cache() ) {
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'wpbridge' );
	} else {
		wp_cache_delete( 'wpbridge', 'wpbridge' );
	}
}
