<?php
/**
 * WPBridge 卸载脚本
 *
 * @package WPBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 删除当前博客的 WPBridge 数据.
 */
function wpbridge_uninstall_site(): void {
	global $wpdb;

	$prefixes = [ 'wpbridge_', '_transient_wpbridge_', '_transient_timeout_wpbridge_' ];
	foreach ( $prefixes as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}

	wp_clear_scheduled_hook( 'wpbridge_update_sources' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		try {
			wpbridge_uninstall_site();
		} finally {
			restore_current_blog();
		}
	}

	global $wpdb;
	foreach ( [ '_site_transient_wpbridge_', '_site_transient_timeout_wpbridge_' ] as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}
} else {
	wpbridge_uninstall_site();
}

if ( wp_using_ext_object_cache() ) {
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		call_user_func( 'wp_cache_flush_group', 'wpbridge' );
	} else {
		wp_cache_delete( 'wpbridge', 'wpbridge' );
	}
}
