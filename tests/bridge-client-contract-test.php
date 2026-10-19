<?php
/**
 * CT-8 capability discovery fallback / endpoint guard, Bridge health contract,
 * and CT-4 self-updater version + transport guard. WordPress-independent.
 *
 * Signature, checksum and grant paths are intentionally not exercised here.
 */

declare(strict_types=1);

namespace WPBridge\Core {
	class Logger {
		public static function __callStatic( string $name, array $args ): void {
			unset( $name, $args );
		}
	}
}

namespace WPBridge\Security {
	class Validator {
		public static function is_valid_url( string $url ): bool {
			return 'https' === parse_url( $url, PHP_URL_SCHEME ) && 'bridge.example' === parse_url( $url, PHP_URL_HOST );
		}
	}
	class SafeHttpClient {
		public static array $calls = [];
		/** @var callable|null */
		public static $responder = null;
		public static function request( string $url, array $args ) {
			self::$calls[] = [ $url, $args ];
			return call_user_func( self::$responder, $url, $args );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
	function add_filter(): bool {
		return true;
	}
	function apply_filters( string $tag, $value ) {
		unset( $tag );
		return $value;
	}
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
	}
	function sanitize_title( $value ): string {
		return strtolower( trim( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $value ), '-' ) );
	}
	function sanitize_file_name( string $value ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', $value );
	}
	function esc_url_raw( string $url ): string {
		return preg_match( '#^(https?:)?//#i', $url ) ? $url : '';
	}
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
	function wp_json_encode( $value ) {
		return json_encode( $value, JSON_UNESCAPED_SLASHES );
	}
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
	function wp_remote_retrieve_header( $response, string $name ): string {
		return (string) ( $response['headers'][ $name ] ?? '' );
	}
	function get_transient( string $key ) {
		return $GLOBALS['bc_transients'][ $key ] ?? false;
	}
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['bc_transients'][ $key ] = $value;
		return true;
	}
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
	class WP_Error {
		private string $code;
		private $data;
		public function __construct( string $code = '', string $message = '', $data = [] ) {
			unset( $message );
			$this->code = $code;
			$this->data = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_data() {
			return $this->data;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/Commercial/BridgeClient.php';
	require_once dirname( __DIR__ ) . '/includes/class-wenpai-updater.php';

	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	};
	$reply = static function ( int $code, $body ): array {
		return [ 'response' => [ 'code' => $code ], 'body' => is_string( $body ) ? $body : json_encode( $body ), 'headers' => [] ];
	};

	/**
	 * Builds a BridgeClient whose API transport answers from $routes (path => response).
	 * Every call is appended to $calls as [url, args].
	 */
	$client = static function ( array $routes, array &$calls, string $server = 'https://bridge.example' ) use ( $reply ): \WPBridge\Commercial\BridgeClient {
		$transport = static function ( string $url, array $args ) use ( $routes, &$calls, $reply ) {
			$calls[] = [ $url, $args ];
			$path    = (string) parse_url( $url, PHP_URL_PATH );
			return $routes[ $path ] ?? $reply( 404, [ 'error' => [ 'code' => 'not_found', 'message' => 'nope' ] ] );
		};
		return new \WPBridge\Commercial\BridgeClient( $server, 'api-key-1', 5, null, $transport );
	};

	$fallback_diag = [
		'service'     => 'wpbridge-server',
		'api_version' => 'v1',
		'legacy'      => true,
		'auth_header' => 'X-API-Key',
		'features'    => [ 'batch_update' => false, 'signed_download' => false, 'sha256' => false ],
	];

	// CT-8: capability discovery failures fall back to the frozen legacy profile.
	foreach ( [
		'404'                => $reply( 404, [ 'error' => 'missing' ] ),
		'non-JSON 200'       => $reply( 200, '<html>ok</html>' ),
		'JSON w/o endpoints' => $reply( 200, [ 'service' => 'x', 'api_version' => 'v9' ] ),
		'endpoints not map'  => $reply( 200, [ 'endpoints' => 'nope' ] ),
		'transport error'    => new WP_Error( 'http_request_failed', 'down' ),
	] as $label => $response ) {
		$calls = [];
		$c     = $client( [ '/api/v1/capabilities' => $response ], $calls );
		$assert( $fallback_diag === $c->get_diagnostics(), "CT-8 capabilities {$label}: diagnostics fall back to legacy=true default profile" );
		$assert( 1 === count( $calls ) && 'https://bridge.example/api/v1/capabilities' === $calls[0][0], "CT-8 capabilities {$label}: discovery is attempted once" );
		$calls = [];
		$c     = $client( [ '/api/v1/capabilities' => $response, '/health' => $reply( 200, [ 'status' => 'ok' ] ) ], $calls );
		$assert( true === $c->health_check() && 'https://bridge.example/health' === ( end( $calls )[0] ?? '' ), "CT-8 capabilities {$label}: health uses the default /health endpoint" );
	}
	$calls = [];
	$c     = $client( [ '/api/v1/capabilities' => $reply( 200, [ 'service' => 'wpbridge-server-pro', 'api_version' => 'v2', 'endpoints' => [ 'health' => '/v2/health' ], 'features' => [ 'batch_update' => 1 ] ] ) ], $calls );
	$diag  = $c->get_diagnostics();
	$assert( false === $diag['legacy'] && 'wpbridge-server-pro' === $diag['service'] && 'v2' === $diag['api_version'] && true === $diag['features']['batch_update'], 'CT-8 capabilities 200 with endpoints: legacy=false and server values override defaults' );
	$assert( [ 'service', 'api_version', 'legacy', 'auth_header', 'features' ] === array_keys( $diag ), 'Diagnostics shape is exactly service, api_version, legacy, auth_header, features' );
	$c->get_diagnostics();
	$assert( 1 === count( $calls ), 'CT-8 capabilities are discovered once per client and cached' );
	$assert( 'https://bridge.example/api/v1/capabilities' === ( $calls[0][0] ?? '' ) && 'api-key-1' === ( $calls[0][1]['headers']['X-API-Key'] ?? '' ) && 'GET' === ( $calls[0][1]['method'] ?? '' ) && true === ( $calls[0][1]['sslverify'] ?? null ), 'Capabilities are fetched with GET, X-API-Key and sslverify=true' );

	// CT-8: advertised endpoints must be same-origin relative paths.
	foreach ( [
		'absolute URL'     => [ 'https://evil.example/health', 'https://bridge.example/health' ],
		'scheme-relative'  => [ '//evil.example/health', 'https://bridge.example/health' ],
		'empty string'     => [ '', 'https://bridge.example/health' ],
		'no leading slash' => [ 'health', 'https://bridge.example/health' ],
		'relative path'    => [ '/v2/health', 'https://bridge.example/v2/health' ],
	] as $label => [ $advertised, $expected_url ] ) {
		$calls = [];
		$c     = $client( [ '/api/v1/capabilities' => $reply( 200, [ 'endpoints' => [ 'health' => $advertised ] ] ), '/health' => $reply( 200, [ 'status' => 'ok' ] ), '/v2/health' => $reply( 200, [ 'status' => 'ok' ] ) ], $calls );
		$c->health_check();
		$assert( $expected_url === ( end( $calls )[0] ?? '' ), "CT-8 endpoint guard {$label}: health request goes to {$expected_url}" );
	}

	// Bridge /health response contract: only {"status":"ok"} is healthy.
	foreach ( [
		'status ok'       => [ $reply( 200, [ 'status' => 'ok' ] ), true ],
		'status degraded' => [ $reply( 200, [ 'status' => 'degraded' ] ), false ],
		'status OK'       => [ $reply( 200, [ 'status' => 'OK' ] ), false ],
		'missing status'  => [ $reply( 200, [ 'healthy' => true ] ), false ],
		'empty 204'       => [ $reply( 204, '' ), false ],
		'non-JSON'        => [ $reply( 200, 'ok' ), false ],
		'HTTP 401'        => [ $reply( 401, [ 'error' => [ 'code' => 'unauthorized' ] ] ), false ],
		'HTTP 503'        => [ $reply( 503, [ 'status' => 'ok' ] ), false ],
	] as $label => [ $response, $expected ] ) {
		$calls = [];
		$c     = $client( [ '/api/v1/capabilities' => $reply( 404, '' ), '/health' => $response ], $calls );
		$assert( $expected === $c->health_check(), "Bridge health {$label} => " . ( $expected ? 'healthy' : 'unhealthy' ) );
	}
	$calls = [];
	$c     = $client( [ '/api/v1/capabilities' => $reply( 404, '' ), '/health' => $reply( 200, [ 'status' => 'ok' ] ) ], $calls );
	$c->health_check();
	$assert( 'api-key-1' === ( end( $calls )[1]['headers']['X-API-Key'] ?? '' ), 'Bridge health request is authenticated with X-API-Key' );

	$calls = [];
	$c     = $client( [ '/api/v1/capabilities' => $reply( 404, '' ), '/health' => $reply( 200, [ 'status' => 'ok' ] ) ], $calls, 'http://bridge.example/' );
	$c->health_check();
	$assert( [] !== $calls && [] === array_filter( $calls, static fn( array $call ): bool => 0 !== strpos( $call[0], 'https://bridge.example/' ) ), 'http:// server URL is upgraded to https:// before any request' );

	// Error contract of BridgeClient::request (error code + HTTP status carried through).
	$calls = [];
	$c     = $client( [ '/api/v1/capabilities' => $reply( 404, '' ) ], $calls );
	$req   = new ReflectionMethod( \WPBridge\Commercial\BridgeClient::class, 'request' );
	$req->setAccessible( true );
	$err   = $req->invoke( $c, 'GET', '/api/v1/vendors', [], true, false );
	$assert( $err instanceof WP_Error && 'not_found' === $err->get_error_code() && 404 === ( $err->get_error_data()['status'] ?? null ) && false === ( $err->get_error_data()['retryable'] ?? null ), 'Bridge 404 maps to server error code with status 404, retryable=false' );
	$c     = $client( [ '/x' => $reply( 429, [ 'message' => 'slow down' ] ) ], $calls );
	$err   = $req->invoke( $c, 'GET', '/x', [], true, false );
	$assert( $err instanceof WP_Error && 'bridge_server_error' === $err->get_error_code() && 429 === ( $err->get_error_data()['status'] ?? null ) && true === ( $err->get_error_data()['retryable'] ?? null ), 'Bridge 429 without error.code maps to bridge_server_error 429, retryable=true' );
	$c     = $client( [ '/x' => $reply( 200, 'not json' ) ], $calls );
	$err   = $req->invoke( $c, 'GET', '/x', [], true, false );
	$assert( $err instanceof WP_Error && 'json_decode_error' === $err->get_error_code(), 'Bridge 200 non-JSON maps to json_decode_error' );
	$c     = $client( [ '/x' => $reply( 500, [ 'error' => [ 'code' => 'Bad Code!<x>', 'request_id' => 'r-1' ] ] ) ], $calls );
	$err   = $req->invoke( $c, 'GET', '/x', [], true, false );
	$assert( $err instanceof WP_Error && 'badcodex' === $err->get_error_code() && 'r-1' === ( $err->get_error_data()['request_id'] ?? '' ), 'Remote error codes are sanitize_key()-ed and request_id is preserved' );

	// CT-4: self-updater rejects downgrade, same version and non-HTTPS packages.
	$plugin_file = 'wpbridge/wpbridge.php';
	$updater     = new WPBridge_Updater( $plugin_file, '1.3.0' );
	$plugin_data = [ 'Version' => '1.3.0' ]; // #16 version guard: disk version must match the loaded updater.
	$offer       = static function ( array $plugin ) use ( $plugin_file, $reply ): void {
		\WPBridge\Security\SafeHttpClient::$responder = static function () use ( $plugin, $plugin_file, $reply ) {
			return $reply( 200, [ 'plugins' => [ $plugin_file => $plugin ] ] );
		};
	};
	$sentinel = [ 'kept' => true ];
	foreach ( [
		'same version'      => [ 'version' => '1.3.0', 'package' => 'https://updates.wenpai.net/p.zip' ],
		'downgrade'         => [ 'version' => '1.2.9', 'package' => 'https://updates.wenpai.net/p.zip' ],
		'http package'      => [ 'version' => '1.4.0', 'package' => 'http://updates.wenpai.net/p.zip' ],
		'ftp package'       => [ 'version' => '1.4.0', 'package' => 'ftp://updates.wenpai.net/p.zip' ],
		'relative package'  => [ 'version' => '1.4.0', 'package' => '/p.zip' ],
		'missing package'   => [ 'version' => '1.4.0' ],
		'missing version'   => [ 'package' => 'https://updates.wenpai.net/p.zip' ],
	] as $label => $plugin ) {
		$offer( $plugin );
		$assert( $sentinel === $updater->check_update( $sentinel, $plugin_data, $plugin_file, [] ), "CT-4 updater {$label}: existing update value is returned unchanged" );
	}
	$offer( [ 'version' => '1.4.0', 'package' => 'https://updates.wenpai.net/p.zip', 'slug' => 'wpbridge' ] );
	$update = $updater->check_update( false, $plugin_data, $plugin_file, [] );
	$assert( is_object( $update ) && '1.4.0' === $update->new_version && 'https://updates.wenpai.net/p.zip' === $update->package && $plugin_file === $update->plugin, 'CT-4 updater: newer HTTPS package is offered (positive control)' );
	\WPBridge\Security\SafeHttpClient::$calls = [];
	$assert( $sentinel === $updater->check_update( $sentinel, $plugin_data, 'other/other.php', [] ) && [] === \WPBridge\Security\SafeHttpClient::$calls, 'CT-4 updater: other plugin files are passed through without a request' );
	\WPBridge\Security\SafeHttpClient::$responder = static function () use ( $reply ) {
		return $reply( 500, [ 'error' => 'x' ] );
	};
	$assert( $sentinel === $updater->check_update( $sentinel, $plugin_data, $plugin_file, [] ), 'CT-4 updater: API HTTP 500 keeps the existing update value' );
	\WPBridge\Security\SafeHttpClient::$responder = static function () use ( $reply ) {
		return $reply( 200, '<html>' );
	};
	$assert( $sentinel === $updater->check_update( $sentinel, $plugin_data, $plugin_file, [] ), 'CT-4 updater: non-JSON API response keeps the existing update value' );
	$last = end( \WPBridge\Security\SafeHttpClient::$calls );
	$assert( 'https://updates.wenpai.net/api/v1/update-check' === ( $last[0] ?? '' ) && 'POST' === ( $last[1]['method'] ?? '' ) && '1.3.0' === ( json_decode( (string) ( $last[1]['body'] ?? '' ), true )['plugins'][ $plugin_file ]['Version'] ?? '' ), 'CT-4 updater: update-check is a POST to the HTTPS update API carrying the installed version' );

	if ( $failures > 0 ) {
		echo "{$failures} Bridge client contract assertion(s) failed." . PHP_EOL;
		exit( 1 );
	}
	echo 'Bridge client contract tests passed.' . PHP_EOL;
}
