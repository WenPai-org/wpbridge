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
}

namespace WPBridge\Security {
	final class Encryption {
		public static function get_secure( string $key, string $default = '' ): string { unset( $key ); return $default; }
	}
}

namespace WPBridge\HubSpoke {
	final class HubSpokeStore {
		public function has_active_spoke_link(): bool { return (bool) $GLOBALS['boundary_active']; }
	}
	require_once dirname( __DIR__ ) . '/includes/HubSpoke/CredentialBoundary.php';
	$failed = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
		$failed += $condition ? 0 : 1;
	};
	$assert( CredentialBoundary::has_upstream_credentials(), 'Credential inventory rejects a network Spoke when another blog retains a source token' );
	$GLOBALS['boundary_active'] = true;
	$assert( ! CredentialBoundary::credential_write_allowed( [ 'headers' => [ 'Authorization' => 'secret' ] ] ) && CredentialBoundary::credential_write_allowed( [ 'headers' => [] ] ), 'Every blog blocks active-network-Spoke credential additions but permits deletion' );
	exit( $failed > 0 ? 1 : 0 );
}
