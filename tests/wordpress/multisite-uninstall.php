<?php
/** Multisite uninstall boundary contract; prepare before and verify after wp plugin uninstall. */

if ( ! is_multisite() ) {
	echo "SKIP: multisite required\n";
	exit( 2 );
}

$mode = $args[0] ?? 'verify';
if ( 'prepare' === $mode ) {
	$network = get_network();
	$site_id = wp_insert_site(
		[
			'domain'     => $network->domain,
			'path'       => trailingslashit( $network->path ) . 'wpbridge-uninstall-' . wp_generate_password( 8, false, false ) . '/',
			'network_id' => $network->id,
			'user_id'    => get_current_user_id() ?: 1,
			'title'      => 'WPBridge Uninstall Fixture',
		]
	);
	if ( is_wp_error( $site_id ) ) {
		echo 'FAIL: create uninstall fixture: ' . $site_id->get_error_message() . "\n";
		exit( 1 );
	}

	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $id ) {
		switch_to_blog( (int) $id );
		update_option( 'wpbridge_uninstall_fixture', 'present' );
		if ( ! wp_next_scheduled( 'wpbridge_update_sources' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'wpbridge_update_sources' );
		}
		restore_current_blog();
	}
	echo 'PASS: prepared uninstall markers on ' . count( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) ) . " sites\n";
	exit( 0 );
}

$failures = [];
foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $id ) {
	switch_to_blog( (int) $id );
	if ( false !== get_option( 'wpbridge_uninstall_fixture', false ) || false !== wp_next_scheduled( 'wpbridge_update_sources' ) ) {
		$failures[] = (int) $id;
	}
	restore_current_blog();
}

if ( ! empty( $failures ) ) {
	echo 'FAIL: uninstall data remains on sites ' . implode( ',', $failures ) . "\n";
	exit( 1 );
}
echo "PASS: multisite uninstall removed per-site options and cron\n";
