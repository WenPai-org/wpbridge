<?php
/**
 * Integrity logging must survive the request context (tester report 6c5e6ac vs 9cd750a §3).
 *
 *   php tests/selfupdate-logger-context-test.php admin|cli|cron|switching|unavailable
 *
 * admin:       Logger/Settings already loaded (wp-admin loads them early).
 * cli / cron:  WP_CLI / DOING_CRON request where nothing loaded Logger yet, but the
 *              plugin autoloader can: the updater must load it and write the log.
 * switching:   plugin files on disk are another version (mid-upgrade): no autoload,
 *              error_log() fallback instead of silently dropping the event.
 * unavailable: no autoloader can provide Logger: error_log() fallback.
 */

namespace WPBridge\Security {
	class SafeHttpClient {
		public static int $calls = 0;
		public static $response = null;

		public static function request( string $url, array $args ) {
			++self::$calls;
			return self::$response;
		}
	}
}


namespace {
	$mode = $argv[1] ?? '';
	if ( ! in_array( $mode, [ 'admin', 'cli', 'cron', 'switching', 'unavailable' ], true ) ) {
		fwrite( STDERR, "usage: php selfupdate-logger-context-test.php admin|cli|cron|switching|unavailable\n" );
		exit( 2 );
	}

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/wpbridge-logctx-' . getmypid() );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WPBRIDGE_VERSION', '1.3.1' );
	define( 'WPBRIDGE_BASENAME', 'wpbridge/wpbridge.php' );
	if ( 'cli' === $mode ) {
		define( 'WP_CLI', true );
	}
	if ( 'cron' === $mode ) {
		define( 'DOING_CRON', true );
	}
	@mkdir( WP_PLUGIN_DIR . '/wpbridge', 0700, true );
	file_put_contents( WP_PLUGIN_DIR . '/wpbridge/wpbridge.php', "<?php\n/**\n * Plugin Name: WPBridge\n * Version: " . ( 'switching' === $mode ? '1.3.2' : WPBRIDGE_VERSION ) . "\n */\n" );

	$GLOBALS['wpb_error_log'] = tempnam( sys_get_temp_dir(), 'wpb-logctx-' );
	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', $GLOBALS['wpb_error_log'] );

	// Stand-ins for includes/Core/Logger.php and Settings.php, only reachable through the autoloader.
	$GLOBALS['wpb_autoloaded'] = [];
	$logger_stub               = 'namespace WPBridge\Core; class Settings { public function is_debug(): bool { return true; } }
		class Logger { public static array $history = [];
			public static function is_enabled( string $level ): bool { return "error" === $level || ( new Settings() )->is_debug(); }
			public static function warning( string $m, array $c = [] ): void { self::$history[] = [ "warning", $m, $c ]; }
			public static function error( string $m, array $c = [] ): void { self::$history[] = [ "error", $m, $c ]; } }';
	if ( 'unavailable' !== $mode ) {
		spl_autoload_register(
			static function ( $class ) use ( $logger_stub ) {
				if ( in_array( $class, [ 'WPBridge\\Core\\Logger', 'WPBridge\\Core\\Settings' ], true ) && ! class_exists( 'WPBridge\\Core\\Settings', false ) ) {
					$GLOBALS['wpb_autoloaded'][] = $class;
					eval( $logger_stub ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stand-in for the plugin autoloader.
				}
			}
		);
	}
	if ( 'admin' === $mode ) {
		class_exists( 'WPBridge\\Core\\Logger' );
	}

	function get_plugin_data( $file, $markup = true, $translate = true ): array {
		$GLOBALS['wpb_disk_reads'] = ( $GLOBALS['wpb_disk_reads'] ?? 0 ) + 1;
		return preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) @file_get_contents( $file ), $m ) ? [ 'Version' => $m[1] ] : [];
	}

	class WP_Error {
		private $code;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
		}
		public function get_error_code() {
			return $this->code;
		}
	}

	$GLOBALS['wpb_site_transients'] = [];
	$GLOBALS['wpb_transients']      = [];
	$GLOBALS['wpb_download_body']   = '';

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
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
	function sanitize_text_field( $text ): string {
		return trim( (string) $text );
	}
	function sanitize_file_name( $name ): string {
		return trim( (string) $name );
	}
	function sanitize_title( $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( (string) $value ) ) );
	}
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function apply_filters( $hook, $value ) {
		if ( 'wenpai_updater_override' === $hook && isset( $GLOBALS['wpb_override'] ) ) {
			return $GLOBALS['wpb_override'];
		}
		return $value;
	}
	function get_plugins(): array {
		return [ 'wpbridge/wpbridge.php' => [ 'Name' => 'WPBridge', 'Version' => WPBRIDGE_VERSION ] ];
	}
	function get_site_url(): string {
		return 'https://example.test';
	}
	function add_filter( ...$args ): bool {
		$GLOBALS['wpb_filters'][] = $args;
		return true;
	}
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return $response['code'] ?? 0;
	}
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
	function set_site_transient( $key, $value, $ttl = 0 ): bool {
		// Mirrors WordPress: updating an existing transient to the same value returns false.
		$unchanged                              = array_key_exists( $key, $GLOBALS['wpb_site_transients'] ) && $GLOBALS['wpb_site_transients'][ $key ] === $value;
		$GLOBALS['wpb_site_transients'][ $key ] = $value;
		return ! $unchanged;
	}
	function get_site_transient( $key ) {
		return $GLOBALS['wpb_site_transients'][ $key ] ?? false;
	}
	function delete_site_transient( $key ): bool {
		unset( $GLOBALS['wpb_site_transients'][ $key ] );
		return true;
	}
	function get_transient( $key ) {
		return $GLOBALS['wpb_transients'][ $key ] ?? false;
	}
	function set_transient( $key, $value, $ttl = 0 ): bool {
		$GLOBALS['wpb_transients'][ $key ] = $value;
		return true;
	}
	function download_url( $url, $timeout = 300 ) {
		$file = tempnam( sys_get_temp_dir(), 'wpb-sigpol-' );
		$body = $GLOBALS['wpb_download_body'];
		if ( is_callable( $body ) ) {
			$body = $body( $url );
		}
		file_put_contents( $file, $body );
		$GLOBALS['wpb_dl_files'][] = $file;
		$GLOBALS['wpb_dl_urls'][]  = $url;
		return $file;
	}
	function wp_delete_file( $file ): void {
		@unlink( $file );
	}


	require_once dirname( __DIR__ ) . '/includes/Security/PackageIntegrityVerifier.php';
	require_once dirname( __DIR__ ) . '/includes/class-wenpai-updater.php';

	use WPBridge\Security\SafeHttpClient;

	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures, $mode ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . "[logctx {$mode}] " . $message . "\n";
		if ( ! $condition ) {
			++$failures;
		}
	};

	$GLOBALS['wpb_site_transients'] = [];
	$GLOBALS['wpb_transients']      = [];
	$GLOBALS['wpb_download_body']   = 'x';
	$body     = 'wpbridge-1.3.2-release-fixture';
	$package  = 'https://updates.wenpai.net/downloads/wpbridge-1.3.2.zip';
	$item     = [ 'id' => 'wpbridge', 'slug' => 'wpbridge', 'version' => '1.3.2', 'package' => $package ];
	$previous = (object) [ 'marker' => 'previous' ];
	$updater  = new WPBridge_Updater( 'wpbridge/wpbridge.php', WPBRIDGE_VERSION );
	$check    = static function ( array $server_item ) use ( $updater, $previous ) {
		SafeHttpClient::$response = [ 'code' => 200, 'body' => json_encode( [ 'plugins' => [ 'wpbridge/wpbridge.php' => $server_item ] ] ) ];
		$result                   = $updater->check_update( $previous, [ 'Version' => WPBRIDGE_VERSION ], 'wpbridge/wpbridge.php', [] );
		if ( is_object( $result ) && isset( $result->package ) ) {
			$GLOBALS['wpb_site_transients']['update_plugins'] = (object) [ 'response' => [ 'wpbridge/wpbridge.php' => $result ] ];
		}
		return $result;
	};

	// 1. check stage warning (unsigned, sha-only), 2. check stage error (malformed sha),
	// 3. download stage error twice for the same version (record missing; never deduped).
	$offered = $check( $item + [ 'sha256' => hash( 'sha256', $body ) ] );
	$check( $item + [ 'sha256' => [ 'not-a-string' ] ] );
	$check( $item + [ 'sha256' => hash( 'sha256', $body ) ] );
	$GLOBALS['wpb_site_transients'] = [ 'update_plugins' => $GLOBALS['wpb_site_transients']['update_plugins'] ?? null ];
	$r1 = $updater->enforce_self_update_record( false, $package, null, [ 'plugin' => 'wpbridge/wpbridge.php' ] );
	$r2 = $updater->enforce_self_update_record( false, $package, null, [ 'plugin' => 'wpbridge/wpbridge.php' ] );

	$assert( is_object( $offered ) && isset( $offered->new_version ), 'unsigned sha-only update is still offered' );
	$assert( is_wp_error( $r1 ) && is_wp_error( $r2 ), 'download without integrity record is refused both times' );

	$fallback = (string) file_get_contents( $GLOBALS['wpb_error_log'] );
	$count    = static fn( string $needle ): int => substr_count( $fallback, $needle );
	if ( in_array( $mode, [ 'admin', 'cli', 'cron' ], true ) ) {
		$history = class_exists( 'WPBridge\\Core\\Logger', false ) ? \WPBridge\Core\Logger::$history : [];
		$levels  = array_count_values( array_column( $history, 0 ) );
		$assert( class_exists( 'WPBridge\\Core\\Logger', false ), 'Logger is loaded by the time the integrity event is logged' );
		$assert( 1 === ( $levels['warning'] ?? 0 ), 'check stage warning reaches Logger (got ' . ( $levels['warning'] ?? 0 ) . ')' );
		$assert( 3 === ( $levels['error'] ?? 0 ), 'malformed sha + two download-stage errors reach Logger (got ' . ( $levels['error'] ?? 0 ) . ')' );
		$assert( '' === $fallback, 'no error_log fallback when Logger works' );
		if ( 'admin' !== $mode ) {
			$assert( [] !== $GLOBALS['wpb_autoloaded'], 'Logger was autoloaded on demand in the ' . $mode . ' context' );
		}
	} else {
		$assert( ! class_exists( 'WPBridge\\Core\\Logger', false ), 'Logger is not loaded from disk in the ' . $mode . ' context' );
		$assert( 1 === $count( '[WPBridge] warning unsigned:' ), 'check stage warning falls back to error_log' );
		$assert( 1 === $count( '[WPBridge] error sha256_malformed:' ), 'check stage error falls back to error_log' );
		$assert( 2 === $count( '[WPBridge] error record_missing:' ), 'both download-stage errors fall back to error_log (not deduped)' );
	}

	@unlink( $GLOBALS['wpb_error_log'] );
	@unlink( WP_PLUGIN_DIR . '/wpbridge/wpbridge.php' );
	@rmdir( WP_PLUGIN_DIR . '/wpbridge' );
	@rmdir( WP_PLUGIN_DIR );
	exit( $failures > 0 ? 1 : 0 );
}
