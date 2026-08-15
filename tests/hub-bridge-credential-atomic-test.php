<?php
/** Dynamic atomicity regressions for BridgeManager credential configuration. */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['atomic_options'] = [];
	$GLOBALS['atomic_autoload'] = [];
	$GLOBALS['atomic_cache'] = [];
	$GLOBALS['atomic_alloptions'] = [];
	$GLOBALS['atomic_cache_deletes'] = [];
	$GLOBALS['atomic_update_hook'] = null;
	$GLOBALS['atomic_fail_option'] = '';
	$GLOBALS['atomic_lifecycle_locked'] = false;
	$GLOBALS['atomic_lock_failure'] = false;
	$GLOBALS['atomic_transaction_failure'] = false;
	$GLOBALS['atomic_engine'] = 'InnoDB';
	$GLOBALS['atomic_db_writes'] = 0;
	$GLOBALS['atomic_engine_queries'] = [];
	function __( string $text, string $domain = '' ): string { return $text; }
	function is_multisite(): bool { return false; }
	function get_option( string $name, $default = false ) {
		if ( array_key_exists( $name, $GLOBALS['atomic_alloptions'] ) ) { return $GLOBALS['atomic_alloptions'][ $name ]; }
		if ( array_key_exists( $name, $GLOBALS['atomic_cache'] ) ) { return $GLOBALS['atomic_cache'][ $name ]; }
		if ( ! array_key_exists( $name, $GLOBALS['atomic_options'] ) ) { return $default; }
		$value = $GLOBALS['atomic_options'][ $name ];
		if ( 'auto' === ( $GLOBALS['atomic_autoload'][ $name ] ?? 'auto' ) ) { $GLOBALS['atomic_alloptions'][ $name ] = $value; } else { $GLOBALS['atomic_cache'][ $name ] = $value; }
		return $value;
	}
	function update_option( string $name, $value ): bool {
		if ( $name === $GLOBALS['atomic_fail_option'] ) { return false; }
		$GLOBALS['atomic_options'][ $name ] = $value;
		$GLOBALS['atomic_autoload'][ $name ] = $GLOBALS['atomic_autoload'][ $name ] ?? 'auto';
		unset( $GLOBALS['atomic_cache'][ $name ], $GLOBALS['atomic_alloptions'][ $name ] );
		return true;
	}
	function delete_option( string $name ): bool { unset( $GLOBALS['atomic_options'][ $name ] ); return true; }
	function wp_cache_delete( string $key, string $group = '' ): bool {
		$GLOBALS['atomic_cache_deletes'][] = $group . ':' . $key;
		if ( 'alloptions' === $key ) { $GLOBALS['atomic_alloptions'] = []; }
		if ( 'notoptions' !== $key ) { unset( $GLOBALS['atomic_cache'][ $key ] ); }
		return true;
	}
	function maybe_serialize( $value ): string { return serialize( $value ); }
	function maybe_unserialize( string $value ) { return unserialize( $value ); }
	function is_wp_error( $value ): bool { return $value instanceof \WP_Error; }
	class WP_Error {}
	final class AtomicWpdb {
		public string $options = 'wp_options';
		private array $snapshot = [];
		private array $autoload_snapshot = [];
		public function prepare( string $query, string $value ): string { return str_replace( '%s', "'" . $value . "'", $query ); }
		public function get_var( string $query ) { $GLOBALS['atomic_engine_queries'][] = $query; return $GLOBALS['atomic_engine']; }
		public function get_row( string $query ) {
			if ( ! preg_match( "/option_name = '([^']+)'/", $query, $matches ) || ! array_key_exists( $matches[1], $GLOBALS['atomic_options'] ) ) { return null; }
			return (object) [ 'option_value' => serialize( $GLOBALS['atomic_options'][ $matches[1] ] ), 'autoload' => 'auto' ];
		}
		public function update( string $table, array $data, array $where, array $formats = [], array $where_formats = [] ) {
			unset( $table, $formats, $where_formats );
			$key = (string) $where['option_name'];
			if ( $key === $GLOBALS['atomic_fail_option'] ) { return false; }
			++$GLOBALS['atomic_db_writes'];
			$GLOBALS['atomic_options'][ $key ] = unserialize( $data['option_value'] );
			if ( is_callable( $GLOBALS['atomic_update_hook'] ) ) { ( $GLOBALS['atomic_update_hook'] )(); $GLOBALS['atomic_update_hook'] = null; }
			return 1;
		}
		public function insert( string $table, array $data, array $formats = [] ) {
			unset( $table, $formats );
			$key = (string) $data['option_name'];
			if ( $key === $GLOBALS['atomic_fail_option'] ) { return false; }
			++$GLOBALS['atomic_db_writes'];
			$GLOBALS['atomic_options'][ $key ] = unserialize( $data['option_value'] );
			$GLOBALS['atomic_autoload'][ $key ] = (string) $data['autoload'];
			if ( is_callable( $GLOBALS['atomic_update_hook'] ) ) { ( $GLOBALS['atomic_update_hook'] )(); $GLOBALS['atomic_update_hook'] = null; }
			return 1;
		}
		public function query( string $sql ) {
			if ( 'START TRANSACTION' === $sql ) { if ( $GLOBALS['atomic_transaction_failure'] ) { return false; } $this->snapshot = $GLOBALS['atomic_options']; $this->autoload_snapshot = $GLOBALS['atomic_autoload']; return 1; }
			if ( 'ROLLBACK' === $sql ) { $GLOBALS['atomic_options'] = $this->snapshot; $GLOBALS['atomic_autoload'] = $this->autoload_snapshot; return 1; }
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
		public function guarded_lifecycle_read( callable $reader ) {
			if ( $GLOBALS['atomic_lifecycle_locked'] ) { return false; }
			$GLOBALS['atomic_lifecycle_locked'] = true;
			try { return $reader(); } finally { $GLOBALS['atomic_lifecycle_locked'] = false; }
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
		public static function encrypt( string $value ): string { return 'enc:' . $value; }
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
	$GLOBALS['atomic_autoload'] = [ Settings::OPTION_SETTINGS => 'auto', 'wpbridge_secure_bridge_server_api_key' => 'no' ];
	$manager = new BridgeManager( new Settings(), new RemoteConfig() );
	\atomic_assert( isset( $GLOBALS['atomic_alloptions'][ Settings::OPTION_SETTINGS ], $GLOBALS['atomic_cache']['wpbridge_secure_bridge_server_api_key'] ), 'Persistent alloptions and per-option caches are prewarmed for atomicity regression' );
	$GLOBALS['atomic_fail_option'] = 'wpbridge_secure_bridge_server_api_key';
	$result = $manager->set_bridge_server( 'https://new.example', 'new-key' );
	\atomic_assert( false === $result['success'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Bridge server secure-write failure rolls URL and key back together' );

	$GLOBALS['atomic_fail_option'] = '';
	$settings_with_concurrent_value = (array) \get_option( Settings::OPTION_SETTINGS, [] );
	$settings_with_concurrent_value['concurrent_setting'] = 'preserve-me';
	\update_option( Settings::OPTION_SETTINGS, $settings_with_concurrent_value );
	$writes_before_engine_failure = $GLOBALS['atomic_db_writes'];
	$GLOBALS['atomic_engine'] = 'MyISAM';
	$result = $manager->set_bridge_server( 'https://myisam.example', 'myisam-key' );
	\atomic_assert( false === $result['success'] && $writes_before_engine_failure === $GLOBALS['atomic_db_writes'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ), 'Non-transactional MyISAM options table fails closed before any write' );
	$GLOBALS['atomic_engine'] = null;
	$result = $manager->set_bridge_server( 'https://unknown-engine.example', 'unknown-key' );
	\atomic_assert( false === $result['success'] && $writes_before_engine_failure === $GLOBALS['atomic_db_writes'] && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Options-table engine lookup failure fails closed before any write' );
	$GLOBALS['atomic_engine'] = 'InnoDB';
	\atomic_assert( false !== strpos( (string) end( $GLOBALS['atomic_engine_queries'] ), "TABLE_NAME = 'wp_options'" ), 'Storage-engine verification binds the exact current-blog options table' );
	$GLOBALS['atomic_transaction_failure'] = true;
	$result = $manager->set_bridge_server( 'https://db-fail.example', 'db-fail-key' );
	$GLOBALS['atomic_transaction_failure'] = false;
	\atomic_assert( false === $result['success'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Bridge server transaction-start failure returns failure without a partial write' );

	$GLOBALS['atomic_lock_failure'] = true;
	$result = $manager->set_bridge_server( 'https://blocked.example', 'blocked-key' );
	$GLOBALS['atomic_lock_failure'] = false;
	\atomic_assert( false === $result['success'] && 'https://old.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'old-key' === Encryption::get_secure( 'bridge_server_api_key' ), 'Bridge server lifecycle-lock contention exposes neither a new origin nor a new key' );

	$concurrent_cached_pair = [];
	$concurrent_guarded_read = null;
	$GLOBALS['atomic_update_hook'] = static function () use ( &$concurrent_cached_pair, &$concurrent_guarded_read ): void {
		$concurrent_cached_pair = [ ( new Settings() )->get( 'bridge_server_url' ), Encryption::get_secure( 'bridge_server_api_key' ) ];
		$concurrent_guarded_read = ( new \WPBridge\HubSpoke\HubSpokeStore() )->guarded_lifecycle_read( static fn(): bool => true );
	};
	$result = $manager->set_bridge_server( 'https://new.example', 'new-key' );
	\atomic_assert( true === $result['success'] && 'https://new.example' === ( new Settings() )->get( 'bridge_server_url' ) && 'new-key' === Encryption::get_secure( 'bridge_server_api_key' ) && 'preserve-me' === ( new Settings() )->get( 'concurrent_setting' ), 'Bridge server transaction reloads locked settings and preserves a concurrent setting' );
	\atomic_assert( [ 'https://old.example', 'old-key' ] === $concurrent_cached_pair && false === $concurrent_guarded_read, 'Direct DB staging publishes no half-generation to persistent-cache readers and composite readers wait on lifecycle lock' );
	\atomic_assert( in_array( 'options:wpbridge_settings', $GLOBALS['atomic_cache_deletes'], true ) && in_array( 'options:alloptions', $GLOBALS['atomic_cache_deletes'], true ) && in_array( 'options:notoptions', $GLOBALS['atomic_cache_deletes'], true ) && in_array( 'options:wpbridge_secure_bridge_server_api_key', $GLOBALS['atomic_cache_deletes'], true ), 'Commit and rollback invalidate per-option, alloptions and notoptions persistent caches' );

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
	$settings_with_concurrent_vendor = (array) \get_option( Settings::OPTION_SETTINGS, [] );
	$settings_with_concurrent_vendor['vendors'] = [ 'concurrent' => [ 'type' => 'bridge_api', 'enabled' => true ] ];
	\update_option( Settings::OPTION_SETTINGS, $settings_with_concurrent_vendor );
	$result = $manager->add_vendor_v2( 'complete', [ 'type' => 'bridge_api', 'api_key' => 'vendor-key', 'consumer_secret' => 'vendor-secret' ] );
	$manifest = (array) \get_option( 'wpbridge_secure_vendor_manifest', [] );
	$committed_vendors = (array) ( new Settings() )->get( 'vendors', [] );
	\atomic_assert( true === $result['success'] && isset( $committed_vendors['complete'], $committed_vendors['concurrent'] ) && in_array( 'vendor_complete_api_key', $manifest, true ) && in_array( 'vendor_complete_consumer_secret', $manifest, true ), 'Vendor transaction reloads the locked index and commits all secure fields and manifest together' );
}
