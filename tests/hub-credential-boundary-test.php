<?php
/** Cross-blog credential-boundary regression. */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['boundary_blog'] = 1;
	$GLOBALS['boundary_options'] = [ 1 => [], 2 => [ 'wpbridge_sources' => [ [ 'auth_token' => 'encrypted-secret' ] ] ] ];
	$GLOBALS['boundary_active'] = false;
	function is_multisite(): bool { return true; }
	function get_sites( array $args = [] ): array { unset( $args ); return [ 1, 2 ]; }
	function switch_to_blog( int $id ): void { $GLOBALS['boundary_blog'] = $id; }
	function restore_current_blog(): void { $GLOBALS['boundary_blog'] = 1; }
	function get_option( string $key, $default = false ) { return $GLOBALS['boundary_options'][ $GLOBALS['boundary_blog'] ][ $key ] ?? $default; }
	function update_option( string $key, $value, bool $autoload = true ): bool { unset( $autoload ); $GLOBALS['boundary_options'][ $GLOBALS['boundary_blog'] ][ $key ] = $value; return true; }
	function get_site_option( string $key, $default = false ) { unset( $key ); return $default; }
	function wp_parse_args( array $args, array $defaults ): array { return array_merge( $defaults, $args ); }
	function wp_generate_uuid4(): string { return '10000000-0000-4000-8000-000000000001'; }
	function current_time( string $type ): string { unset( $type ); return '2026-08-14 00:00:00'; }
	function do_action( string $hook, ...$args ): void { unset( $hook, $args ); }
	function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }
}

namespace WPBridge\Security {
	final class Encryption {
		public static function get_secure( string $key, string $default = '' ): string { unset( $key ); return $default; }
		public static function is_encrypted( string $value ): bool { return 0 === strpos( $value, 'enc:' ); }
		public static function encrypt( string $value ): string { return 'enc:' . $value; }
	}
}

namespace WPBridge\Core {
	final class Logger { public static function warning( string $message, array $context = [] ): void { unset( $message, $context ); } }
}

namespace WPBridge\HubSpoke {
	final class HubSpokeStore {
		public function has_active_spoke_link(): bool { return (bool) $GLOBALS['boundary_active']; }
	}
	require_once dirname( __DIR__ ) . '/includes/HubSpoke/CredentialBoundary.php';
	require_once dirname( __DIR__ ) . '/includes/Core/Settings.php';
	require_once dirname( __DIR__ ) . '/includes/Core/SourceRegistry.php';
	require_once dirname( __DIR__ ) . '/includes/Commercial/Vendors/VendorManager.php';
	$failed = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
		$failed += $condition ? 0 : 1;
	};
	$assert( CredentialBoundary::has_upstream_credentials(), 'Credential inventory rejects a network Spoke when another blog retains a source token' );
	$GLOBALS['boundary_active'] = true;
	$assert( ! CredentialBoundary::credential_write_allowed( [ 'headers' => [ 'Authorization' => 'secret' ] ] ) && CredentialBoundary::credential_write_allowed( [ 'headers' => [] ] ), 'Every blog blocks active-network-Spoke credential additions but permits deletion' );
	$settings = new \WPBridge\Core\Settings();
	$settings_blocked = ! $settings->add_source( [ 'id' => 'secret-source', 'auth_token' => 'new-secret' ] );
	$settings_clean = $settings->add_source( [ 'id' => 'clean-source', 'auth_token' => '' ] );
	$GLOBALS['boundary_options'][1]['wpbridge_sources'][] = [ 'id' => 'existing-source', 'auth_token' => 'old-secret' ];
	$settings_delete = ( new \WPBridge\Core\Settings() )->update_source( 'existing-source', [ 'auth_token' => '' ] );
	$assert( $settings_blocked && $settings_clean && $settings_delete, 'Settings public source APIs reject secret additions and permit credential deletion' );
	$GLOBALS['boundary_options'][1]['wpbridge_source_registry'] = [ [ 'source_key' => 'existing-registry', 'type' => 'custom', 'auth_secret_ref' => 'old-ref' ] ];
	$registry = new \WPBridge\Core\SourceRegistry();
	$registry_blocked = false === $registry->add( [ 'source_key' => 'blocked-registry', 'auth_secret_ref' => 'new-ref' ] );
	$registry_delete = $registry->update( 'existing-registry', [ 'auth_secret_ref' => '' ] );
	$assert( $registry_blocked && $registry_delete, 'SourceRegistry public APIs reject secret additions and permit credential deletion' );
	$GLOBALS['boundary_options'][1]['wpbridge_settings'] = [ 'vendors' => [ 'existing-vendor' => [ 'type' => 'unknown', 'api_key' => 'old-key' ] ] ];
	$vendor_manager = new \WPBridge\Commercial\Vendors\VendorManager( new \WPBridge\Core\Settings() );
	$vendor_blocked = ! $vendor_manager->add_vendor_config( 'new-vendor', [ 'type' => 'unknown', 'api_key' => 'new-key' ] );
	$vendor_delete = $vendor_manager->add_vendor_config( 'existing-vendor', [ 'type' => 'unknown', 'api_key' => '' ] );
	$assert( $vendor_blocked && $vendor_delete, 'VendorManager public API rejects secret additions and permits credential deletion' );
	$GLOBALS['boundary_blog'] = 2;
	$assert( [] === get_option( 'wpbridge_source_registry', [] ) && [] === get_option( 'wpbridge_defaults', [] ), 'Source registry and defaults remain per-blog rather than becoming Stage 3A network state' );
	exit( $failed > 0 ? 1 : 0 );
}
