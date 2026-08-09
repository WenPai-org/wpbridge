<?php
/** Multisite new-site and deletion lifecycle contract; run with wp eval-file. */

if ( ! defined( 'WPBRIDGE_FILE' ) ) {
	require dirname( __DIR__, 2 ) . '/wpbridge.php';
}

if ( ! is_multisite() ) {
	echo "SKIP: multisite required\n";
	exit( 2 );
}

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! is_plugin_active_for_network( WPBRIDGE_BASENAME ) ) {
	echo "FAIL: WPBridge is not network active\n";
	exit( 1 );
}

$network = get_network();
$path    = trailingslashit( $network->path ) . 'wpbridge-lifecycle-' . wp_generate_password( 8, false, false ) . '/';
$site_id = wp_insert_site(
	[
		'domain'     => $network->domain,
		'path'       => $path,
		'network_id' => $network->id,
		'user_id'    => get_current_user_id() ?: 1,
		'title'      => 'WPBridge Lifecycle Fixture',
	]
);
if ( is_wp_error( $site_id ) ) {
	echo 'FAIL: create site: ' . $site_id->get_error_message() . "\n";
	exit( 1 );
}

switch_to_blog( (int) $site_id );
$activated = get_option( 'wpbridge_activated' );
$cron    = wp_next_scheduled( 'wpbridge_update_sources' );
restore_current_blog();

if ( empty( $activated ) || false === $cron ) {
	echo "FAIL: new network site was not initialized\n";
	exit( 1 );
}
echo "PASS: new network site initialized options and cron\n";

$cleanup_seen = false;
$observer = static function ( $old_site ) use ( $site_id, &$cleanup_seen ): void {
	if ( (int) $old_site->blog_id !== (int) $site_id ) {
		return;
	}
	switch_to_blog( (int) $site_id );
	$cleanup_seen = false === wp_next_scheduled( 'wpbridge_update_sources' );
	restore_current_blog();
};
add_action( 'wp_uninitialize_site', $observer, 1, 1 );
$deleted = wp_delete_site( (int) $site_id );
remove_action( 'wp_uninitialize_site', $observer, 1 );

if ( is_wp_error( $deleted ) || ! $cleanup_seen ) {
	echo "FAIL: deleted site runtime state was not cleared before table removal\n";
	exit( 1 );
}
echo "PASS: deleted site runtime state cleared before table removal\n";
