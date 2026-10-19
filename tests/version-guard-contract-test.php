<?php
/**
 * Self-updater version guard and Site Health version check contracts.
 *
 * Standalone (no WordPress): stubs the few WordPress functions used and counts
 * every SafeHttpClient request so a skipped branch provably makes 0 network calls.
 */

namespace WPBridge\Security {
	class SafeHttpClient {
		public static int $calls = 0;

		public static function request( string $url, array $args ) {
			++self::$calls;
			return new \WP_Error( 'test_network_disabled', 'network disabled in contract test' );
		}
	}
}

namespace WPBridge\Core {
	class Logger {
		public static array $entries = [];
		public static bool $debug    = true;

		public static function is_enabled( string $level ): bool {
			return 'error' === $level || self::$debug;
		}

		public static function debug( string $message, array $context = [] ): void {
			if ( ! self::$debug ) {
				return; // Mirrors Logger::log(): debug entries are dropped while debug mode is off.
			}
			self::$entries[] = [ $message, $context ];
		}
	}

	// Already-loaded Settings: the updater must see it via class_exists( ..., false ).
	class Settings {
	}
}

namespace {

	$wpbridge_test_root = sys_get_temp_dir() . '/wpbridge-vguard-' . getmypid();
	@mkdir( $wpbridge_test_root . '/plugins/wpbridge', 0700, true );
	file_put_contents( $wpbridge_test_root . '/plugins/wpbridge/wpbridge.php', "<?php\n" );

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_PLUGIN_DIR', $wpbridge_test_root . '/plugins' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WPBRIDGE_VERSION', '1.3.1' );
	define( 'WPBRIDGE_FILE', WP_PLUGIN_DIR . '/wpbridge/wpbridge.php' );
	define( 'WPBRIDGE_BASENAME', 'wpbridge/wpbridge.php' );

	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '' ) {
			$this->code = $code;
		}
	}

	$GLOBALS['wpbridge_test_header'] = [ 'Name' => 'WPBridge', 'Version' => WPBRIDGE_VERSION ];
	$GLOBALS['wpbridge_test_transients'] = [];
	$GLOBALS['wpbridge_test_ttls']       = [];
	$GLOBALS['wpbridge_test_multisite']  = false;
	$GLOBALS['wpbridge_test_network']    = [];

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
	function sanitize_text_field( $text ): string {
		return trim( (string) $text );
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
	function esc_url( $url ) {
		return (string) $url;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function network_admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/network/' . $path;
	}
	function is_multisite(): bool {
		return $GLOBALS['wpbridge_test_multisite'];
	}
	function is_plugin_active_for_network( $plugin ): bool {
		return in_array( $plugin, $GLOBALS['wpbridge_test_network'], true );
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function add_filter( ...$args ): bool {
		return true;
	}
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
	function get_plugin_data( $file, $markup = true, $translate = true ): array {
		return $GLOBALS['wpbridge_test_header'];
	}
	function get_transient( $key ) {
		return $GLOBALS['wpbridge_test_transients'][ $key ] ?? false;
	}
	function set_transient( $key, $value, $ttl = 0 ): bool {
		$GLOBALS['wpbridge_test_transients'][ $key ] = $value;
		$GLOBALS['wpbridge_test_ttls'][ $key ]       = $ttl;
		return true;
	}

	require_once dirname( __DIR__ ) . '/includes/class-wenpai-updater.php';
	require_once dirname( __DIR__ ) . '/includes/Core/SiteHealth.php';

	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
		if ( ! $condition ) {
			++$failures;
		}
	};
	$reset = static function (): void {
		\WPBridge\Security\SafeHttpClient::$calls = 0;
		\WPBridge\Core\Logger::$entries           = [];
		\WPBridge\Core\Logger::$debug             = true;
		$GLOBALS['wpbridge_test_transients']      = [];
		$GLOBALS['wpbridge_test_ttls']            = [];
	};

	$updater  = new WPBridge_Updater( 'wpbridge/wpbridge.php', WPBRIDGE_VERSION );
	$previous = (object) [ 'marker' => 'previous' ];
	$args     = (object) [ 'slug' => 'wpbridge' ];

	// check_update(): empty header version.
	$reset();
	$result = $updater->check_update( $previous, [ 'Version' => '' ], 'wpbridge/wpbridge.php', [] );
	$assert( $previous === $result && 0 === \WPBridge\Security\SafeHttpClient::$calls, "check_update with Version => '' returns the previous value with 0 network calls" );
	$result = $updater->check_update( $previous, [], 'wpbridge/wpbridge.php', [] );
	$assert( $previous === $result && 0 === \WPBridge\Security\SafeHttpClient::$calls, 'check_update with a missing Version key makes 0 network calls' );
	$assert( 1 === count( \WPBridge\Core\Logger::$entries ), 'Repeated skips for the same version pair log one debug entry per day' );
	$assert( '(empty)' === ( \WPBridge\Core\Logger::$entries[0][1]['header_version'] ?? null ) && 'check_update' === ( \WPBridge\Core\Logger::$entries[0][1]['context'] ?? null ), 'Skip log records the empty header version and the check_update context' );

	$assert( [ DAY_IN_SECONDS ] === array_values( $GLOBALS['wpbridge_test_ttls'] ), 'Skip-log dedupe transient TTL is exactly DAY_IN_SECONDS' );

	// check_update(): mismatched header version logs again (different pair), still no network.
	$updater->check_update( $previous, [ 'Version' => '1.3.0' ], 'wpbridge/wpbridge.php', [] );
	$assert( 0 === \WPBridge\Security\SafeHttpClient::$calls && 2 === count( \WPBridge\Core\Logger::$entries ), 'A different mismatched version pair is logged separately and makes 0 network calls' );

	// Debug logging disabled: skip must not write the dedupe transient or log.
	$reset();
	\WPBridge\Core\Logger::$debug = false;
	$updater->check_update( $previous, [ 'Version' => '' ], 'wpbridge/wpbridge.php', [] );
	$assert( [] === $GLOBALS['wpbridge_test_transients'] && [] === \WPBridge\Core\Logger::$entries && 0 === \WPBridge\Security\SafeHttpClient::$calls, 'With debug logging disabled the skip writes no dedupe transient and no log entry' );
	\WPBridge\Core\Logger::$debug = true;
	$updater->check_update( $previous, [ 'Version' => '' ], 'wpbridge/wpbridge.php', [] );
	$assert( 1 === count( \WPBridge\Core\Logger::$entries ), 'Enabling debug logging later the same day logs the next skip immediately' );

	// Positive control: matching versions do reach the network client.
	$reset();
	$result = $updater->check_update( $previous, [ 'Version' => WPBRIDGE_VERSION ], 'wpbridge/wpbridge.php', [] );
	$assert( $previous === $result && 1 === \WPBridge\Security\SafeHttpClient::$calls && [] === \WPBridge\Core\Logger::$entries, 'check_update with matching versions makes exactly one request and logs nothing' );

	// plugin_info(): empty header version.
	$reset();
	$GLOBALS['wpbridge_test_header'] = [ 'Name' => 'WPBridge', 'Version' => '' ];
	$info = $updater->plugin_info( false, 'plugin_information', $args );
	$assert( 0 === \WPBridge\Security\SafeHttpClient::$calls, "plugin_info with Version => '' makes 0 network calls" );
	$assert( is_object( $info ) && 'wpbridge' === $info->slug && WPBRIDGE_VERSION === $info->version, 'plugin_info falls back to local header data when the guard blocks the request' );
	$assert( 'plugin_info' === ( \WPBridge\Core\Logger::$entries[0][1]['context'] ?? null ), 'plugin_info guard skip is logged with its context' );

	// plugin_info(): mismatched header version reports the on-disk version.
	$reset();
	$GLOBALS['wpbridge_test_header'] = [ 'Name' => 'WPBridge', 'Version' => '1.3.0' ];
	$info = $updater->plugin_info( false, 'plugin_information', $args );
	$assert( 0 === \WPBridge\Security\SafeHttpClient::$calls && is_object( $info ) && '1.3.0' === $info->version, 'plugin_info with a mismatched header makes 0 network calls and shows the on-disk version' );

	// plugin_info(): positive control.
	$reset();
	$GLOBALS['wpbridge_test_header'] = [ 'Name' => 'WPBridge', 'Version' => WPBRIDGE_VERSION ];
	$updater->plugin_info( false, 'plugin_information', $args );
	$assert( 1 === \WPBridge\Security\SafeHttpClient::$calls, 'plugin_info with matching versions makes exactly one request' );
	$updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'other-plugin' ] );
	$assert( 1 === \WPBridge\Security\SafeHttpClient::$calls, 'plugin_info ignores other plugin slugs' );

	// Site Health.
	$health = ( new ReflectionClass( \WPBridge\Core\SiteHealth::class ) )->newInstanceWithoutConstructor();
	$tests  = $health->add_tests( [] );
	$assert( isset( $tests['direct']['wpbridge_updater_version'] ) && [ $health, 'test_updater_version' ] === $tests['direct']['wpbridge_updater_version']['test'], 'Site Health registers the direct wpbridge_updater_version test' );

	$reset();
	$GLOBALS['wpbridge_test_header'] = [ 'Version' => WPBRIDGE_VERSION ];
	$status = $health->test_updater_version();
	$assert( 'good' === $status['status'] && 'wpbridge_updater_version' === $status['test'], 'Site Health version test is good when header and WPBRIDGE_VERSION match' );

	$GLOBALS['wpbridge_test_header'] = [ 'Version' => '1.3.0' ];
	$status = $health->test_updater_version();
	$assert( 'recommended' === $status['status'] && false !== strpos( $status['description'], '1.3.0' ) && false !== strpos( $status['description'], WPBRIDGE_VERSION ), 'Site Health version test is recommended on mismatch and names both versions' );

	$GLOBALS['wpbridge_test_header'] = [ 'Version' => '' ];
	$status = $health->test_updater_version();
	$assert( 'recommended' === $status['status'], 'Site Health version test is recommended when the header version is empty' );
	$assert( false !== strpos( $status['actions'], 'https://example.test/wp-admin/plugins.php' ), 'Single site: the plugins link points at the site admin' );

	$GLOBALS['wpbridge_test_multisite'] = true;
	$GLOBALS['wpbridge_test_network']   = [ 'wpbridge/wpbridge.php' ];
	$status = $health->test_updater_version();
	$assert( false !== strpos( $status['actions'], 'https://example.test/wp-admin/network/plugins.php' ), 'Multisite + network-active: the plugins link points at the network admin' );

	$GLOBALS['wpbridge_test_network'] = [];
	$status = $health->test_updater_version();
	$assert( false === strpos( $status['actions'], '/network/' ) && false !== strpos( $status['actions'], 'wp-admin/plugins.php' ), 'Multisite but only site-active: the plugins link stays on the site admin' );
	$GLOBALS['wpbridge_test_multisite'] = false;

	$assert( 0 === \WPBridge\Security\SafeHttpClient::$calls && [] === $GLOBALS['wpbridge_test_transients'], 'Site Health version test is stateless and makes no network calls' );

	@unlink( WPBRIDGE_FILE );
	@rmdir( WP_PLUGIN_DIR . '/wpbridge' );
	@rmdir( WP_PLUGIN_DIR );
	@rmdir( $wpbridge_test_root );

	exit( $failures > 0 ? 1 : 0 );
}
