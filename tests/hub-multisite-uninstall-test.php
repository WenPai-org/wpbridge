<?php
/** Stage 3A multisite uninstall regression. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', true );
$GLOBALS['deleted_network_options'] = [];
function is_multisite(): bool { return true; }
function delete_site_option( string $key ): void { $GLOBALS['deleted_network_options'][] = $key; }
function get_sites( array $args = [] ): array { unset( $args ); return []; }
function wp_clear_scheduled_hook(): void {}
function wp_using_ext_object_cache(): bool { return false; }
$GLOBALS['wpdb'] = new class() {
	public string $options = 'options';
	public string $sitemeta = 'sitemeta';
	public function esc_like( string $value ): string { return $value; }
	public function prepare( string $sql, string $value ): string { return $sql . $value; }
	public function query( string $sql ): void { unset( $sql ); }
};
require dirname( __DIR__ ) . '/uninstall.php';
$required = [ 'wpbridge_link_private_key', 'wpbridge_hub_links_v1', 'wpbridge_spoke_links_v1', 'wpbridge_spoke_reconcile_v1', 'wpbridge_spoke_uncertain_accept_v1', 'wpbridge_hub_spoke_role_v1', 'wpbridge_hub_network_origin_v1' ];
$ok = [] === array_diff( $required, $GLOBALS['deleted_network_options'] );
echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . "Multisite uninstall deletes all Stage 3A network identity, secret, role and reconciliation state\n";
exit( $ok ? 0 : 1 );
