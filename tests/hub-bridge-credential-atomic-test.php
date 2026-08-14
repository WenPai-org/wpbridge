<?php
/** Dynamic atomicity regressions for BridgeManager credential configuration. */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['atomic_options'] = [];
	$GLOBALS['atomic_fail_option'] = '';
	$GLOBALS['atomic_lifecycle_locked'] = false;
	$GLOBALS['atomic_lock_failure'] = false;
	$GLOBALS['atomic_transaction_failure'] = false;
	function __( string $text, string $domain = '' ): string { return $text; }
	function is_multisite(): bool { return false; }
	function get_option( string $name, $default = false ) { return array_key_exists( $name, $GLOBALS['atomic_options'] ) ? $GLOBALS['atomic_options'][ $name ] : $default; }
	function update_option( string $name, $value ): bool {
		if ( $name === $GLOBALS['atomic_fail_option'] ) { return false; }
		$GLOBALS['atomic_options'][ $name ] = $value;
		return true;
	}
	function delete_option( string $name ): bool { unset( $GLOBALS['atomic_options'][ $name ] ); return true; }
	function wp_cache_delete( string $key, string $group = '' ): bool { return true; }
	function is_wp_error( $value ): bool { return $value instanceof \WP_Error; }
	class WP_Error {}
	final class AtomicWpdb {
		private array $snapshot = [];
		public function query( string $sql ) {
			if ( 'START TRANSACTION' === $sql ) { if ( $GLOBALS['atomic_transaction_failure'] ) { return false; } $this->snapshot = $GLOBALS['atomic_options']; return 1; }
			if ( 'ROLLBACK' === $sql ) { $GLOBALS['atomic_options'] = $this->snapshot; return 1; }
			if ( 'COMMIT' === $sql ) { $this->snapshot = []; return 1; }
			return false;
		}
	}
	$GLOBALS['wpdb'] = new AtomicWpdb();
	function atomic_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { fwrite( STDERR, "[FAIL] {$message}\n" ); exit( 1 ); }
		echo "[PASS] {$message}\n";
	}
}

namespace WPBridge\HubSpoke {
	final class HubSpokeStore {
		public function guarded_credential_write( bool $increasing, callable $writer ): bool {
			if ( $GLOBALS['atomic_lock_failure'] || $GLOBALS['atomic_lifecycle_locked'] ) { return false; }
			$GLOBALS['atomic_lifecycle_locked'] = true;
			try { return (bool) $writer(); } finally { $GLOBALS['atomic_lifecycle_locked'] = false; }
		}
	}
	require_once dirname( __DIR__ ) . '/includes/HubSpoke/CredentialBoundary.php';
}

namespace WPBridge\Core {
	use WPBridge\HubSpoke\CredentialBoundary;
	class Settings {
		public const OPTION_SETTINGS = 'wpbridge_settings';
		public function get( string $key, $default = null ) { $settings = (array) \get_option( self::OPTION_SETTINGS, [] ); return $settings[ $key ] ?? $default; }
		public function set( string $key, $value ): bool {
			$settings = (array) \get_option( self::OPTION_SETTINGS, [] );
			$previous = [ $key => $settings[ $key ] ?? null ];
			$settings[ $key ] = $value;
			return CredentialBoundary::guarded_write( [ $key => $value ], $previous, static fn(): bool => \update_option( self::OPTION_SETTINGS, $settings ) );
		}
	}
	class RemoteConfig { public function refresh(): bool { return true; } }
	class Logger { public static function info( string $message, array $context = [] ): void {} }
}

namespace WPBridge\Security {
	use WPBridge\HubSpoke\CredentialBoundary;
	final class Encryption {
		public static function get_secure( string $key, string $default = '' ): string {
			$value = (string) \get_option( 'wpbridge_secure_' . $key, '' );
			return '' === $value ? $default : preg_replace( '/^enc:/', '', $value );
		}
		public static function store_secure( string $key, string $value ): bool {
			$previous = self::get_secure( $key, '' );
			return CredentialBoundary::guarded_secure_write( $value, $previous, static fn(): bool => \update_option( 'wpbridge_secure_' . $key, 'enc:' . $value ) );
		}
	}
}

namespace WPBridge\Commercial\Vendors {
	class VendorManager {
		private static ?self $instance = null;
		public static function get_instance( $settings = null ): self { return self::$instance ?? ( self::$instance = new self() ); }
		public function register( $vendor ): void {}
	}
	class WooCommerceVendor { public function __construct( ...$args ) {} }
	class BridgeApiVendor { public function __construct( ...$args ) {} }
}

namespace WPBridge\Commercial {
	class BridgeClient {
		public function __construct( string $url, string $key ) {}
		public function health_check(): bool { return true; }
	}
	class SubscriptionManager { public function __construct( ...$args ) {} }
	require_once dirname( __DIR__ ) . '/includes/Commercial/BridgeManager.php';

	use WPBridge\Core\RemoteConfig;
	use WPBridge\Core\Settings;
	use WPBridge\Security\Encryption;

	$GLOBALS['atomic_options'] = [
		Settings::OPTION_SETTINGS => [ 'bridge_server_url' => 'https://old.example' ],
		'wpbridge_secure_bridge_server_api_key' => 'enc:old-key',
	];
	$manager = new BridgeManager( new Settings(), new RemoteConfig() );
	$GLOBALS['atomic_fail_option'] = 'wpbridge_secure_bridge_server_api_key';
	$result = $manager->set_bridge_server( 'https://new.example', 'new-key' );
	\atomic_assert( false === $result['success'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Bridge server secure-write failure rolls URL and key back together' );

	$GLOBALS['atomic_fail_option'] = '';
	$GLOBALS['atomic_options'][ Settings::OPTION_SETTINGS ]['concurrent_setting'] = 'preserve-me';
	$GLOBALS['atomic_transaction_failure'] = true;
	$result = $manager->set_bridge_server( 'https://db-fail.example', 'db-fail-key' );
	$GLOBALS['atomic_transaction_failure'] = false;
	\atomic_assert( false === $result['success'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Bridge server transaction-start failure returns failure without a partial write' );

	$GLOBALS['atomic_lock_failure'] = true;
	$result = $manager->set_bridge_server( 'https://blocked.example', 'blocked-key' );
	$GLOBALS['atomic_lock_failure'] = false;
	\atomic_assert( false === $result['success'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Bridge server lifecycle-lock contention exposes neither a new origin nor a new key' );

	$result = $manager->set_bridge_server( 'https://new.example', 'new-key' );
	\atomic_assert( true === $result['success'] && 'https://new.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'new-key' === Encryption::get_secure( 'bridge_server_api_key' ) && 'preserve-me' === ( new Settings() )->get( 'concurrent_setting' ), 'Bridge server transaction reloads locked settings and preserves a concurrent setting' );

	$GLOBALS['atomic_fail_option'] = Settings::OPTION_SETTINGS;
	$result = $manager->add_vendor_v2( 'broken', [ 'type' => 'bridge_api', 'api_key' => 'vendor-key', 'consumer_secret' => 'vendor-secret' ] );
	\atomic_assert( false === $result['success'] && false === \get_option( 'wpbridge_secure_vendor_broken_api_key', false ) && false === \get_option( 'wpbridge_secure_vendor_broken_consumer_secret', false ) && [] === ( new Settings() )->get( 'vendors', [] ), 'Vendor index failure rolls every secure vendor field back' );

	$GLOBALS['atomic_fail_option'] = '';
	$GLOBALS['atomic_lock_failure'] = true;
	$result = $manager->add_vendor_v2( 'lock-fail', [ 'type' => 'bridge_api', 'api_key' => 'vendor-key' ] );
	$GLOBALS['atomic_lock_failure'] = false;
	\atomic_assert( false === $result['success'] && false === \get_option( 'wpbridge_secure_vendor_lock-fail_api_key', false ) && [] === ( new Settings() )->get( 'vendors', [] ), 'Vendor lifecycle-lock failure leaves no secure field, manifest or vendor index' );

	$GLOBALS['atomic_fail_option'] = 'wpbridge_secure_vendor_manifest';
	$result = $manager->add_vendor_v2( 'manifest-fail', [ 'type' => 'bridge_api', 'api_key' => 'vendor-key' ] );
	\atomic_assert( false === $result['success'] && false === \get_option( 'wpbridge_secure_vendor_manifest-fail_api_key', false ) && [] === ( new Settings() )->get( 'vendors', [] ), 'Vendor manifest failure rolls secure fields and vendor index back' );

	$GLOBALS['atomic_fail_option'] = '';
	$GLOBALS['atomic_options'][ Settings::OPTION_SETTINGS ]['vendors'] = [ 'concurrent' => [ 'type' => 'bridge_api', 'enabled' => true ] ];
	$result = $manager->add_vendor_v2( 'complete', [ 'type' => 'bridge_api', 'api_key' => 'vendor-key', 'consumer_secret' => 'vendor-secret' ] );
	$manifest = (array) \get_option( 'wpbridge_secure_vendor_manifest', [] );
	$committed_vendors = (array) ( new Settings() )->get( 'vendors', [] );
	\atomic_assert( true === $result['success'] && isset( $committed_vendors['complete'], $committed_vendors['concurrent'] ) && in_array( 'vendor_complete_api_key', $manifest, true ) && in_array( 'vendor_complete_consumer_secret', $manifest, true ), 'Vendor transaction reloads the locked index and commits all secure fields and manifest together' );
}
