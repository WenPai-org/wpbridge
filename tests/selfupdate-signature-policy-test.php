<?php
/**
 * Self-update signature policy contracts (P0: signature downgrade).
 *
 * Run twice, because WPBRIDGE_ARTIFACT_PUBLIC_KEYS is a constant:
 *   php tests/selfupdate-signature-policy-test.php keys    (operator pinned a key in wp-config.php)
 *   php tests/selfupdate-signature-policy-test.php nokeys  (shipped default: no key)
 *
 * Policy under test:
 * - Keys configured locally => every self-update must carry a valid Ed25519
 *   signature and SHA-256. No server field (signature_required=false, missing
 *   fields) can weaken this.
 * - No keys => an unsigned update is still offered (existing sites keep
 *   updating), with a Logger warning and a Site Health notice.
 * - A signature that is present but invalid/untrusted is always rejected.
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

namespace WPBridge\Core {
	class Logger {
		public static array $entries = [];

		public static function is_enabled( string $level ): bool {
			return true;
		}
		public static function debug( string $message, array $context = [] ): void {
			self::$entries[] = [ 'debug', $message, $context ];
		}
		public static function warning( string $message, array $context = [] ): void {
			self::$entries[] = [ 'warning', $message, $context ];
		}
		public static function error( string $message, array $context = [] ): void {
			self::$entries[] = [ 'error', $message, $context ];
		}
		public static function levels(): array {
			return array_column( self::$entries, 0 );
		}
	}

	class Settings {
	}
}

namespace {
	$mode = $argv[1] ?? '';
	if ( ! in_array( $mode, [ 'keys', 'nokeys' ], true ) ) {
		fwrite( STDERR, "usage: php selfupdate-signature-policy-test.php keys|nokeys\n" );
		exit( 2 );
	}

	$b64url = static function ( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	};

	// Frozen fixture key (same seed as artifact-signature-test.php).
	$keypair    = sodium_crypto_sign_seed_keypair( str_repeat( "\x33", SODIUM_CRYPTO_SIGN_SEEDBYTES ) );
	$public_key = $b64url( sodium_crypto_sign_publickey( $keypair ) );
	$secret_key = sodium_crypto_sign_secretkey( $keypair );

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/wpbridge-sigpolicy-' . getmypid() );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WPBRIDGE_VERSION', '1.3.1' );
	if ( 'keys' === $mode ) {
		define(
			'WPBRIDGE_ARTIFACT_PUBLIC_KEYS',
			[
				'release-2026q3' => [
					'public_key' => $public_key,
					'status'     => 'active',
					'not_before' => '2026-01-01T00:00:00Z',
				],
			]
		);
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
		return $value;
	}
	function add_filter( ...$args ): bool {
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
		$GLOBALS['wpb_site_transients'][ $key ] = $value;
		return true;
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
		file_put_contents( $file, $GLOBALS['wpb_download_body'] );
		return $file;
	}
	function wp_delete_file( $file ): void {
		@unlink( $file );
	}

	require_once dirname( __DIR__ ) . '/includes/Security/PackageIntegrityVerifier.php';
	require_once dirname( __DIR__ ) . '/includes/class-wenpai-updater.php';
	require_once dirname( __DIR__ ) . '/includes/Core/SiteHealth.php';

	use WPBridge\Security\PackageIntegrityVerifier;
	use WPBridge\Security\SafeHttpClient;
	use WPBridge\Core\Logger;

	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures, $mode ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . "[{$mode}] " . $message . "\n";
		if ( ! $condition ) {
			++$failures;
		}
	};

	// Signed release fixture for wpbridge 1.3.2.
	$body      = 'wpbridge-1.3.2-release-fixture';
	$sha256    = hash( 'sha256', $body );
	$file_name = 'wpbridge-1.3.2.zip';
	$signed_at = '2026-10-19T00:00:00Z';
	$package   = 'https://updates.wenpai.net/downloads/wpbridge-1.3.2.zip';
	$canonical = "WENPAI-RELEASE-SIGNATURE-V1\nslug:wpbridge\nversion:1.3.2\nfile:{$file_name}\nsize:" . strlen( $body ) . "\nsha256:{$sha256}\nsigned_at:{$signed_at}\n";
	$signature = $b64url( sodium_crypto_sign_detached( $canonical, $secret_key ) );

	$base_item   = [
		'id'      => 'wpbridge',
		'slug'    => 'wpbridge',
		'version' => '1.3.2',
		'package' => $package,
	];
	$signed_item = $base_item + [
		'sha256'             => $sha256,
		'signature_scheme'   => 'ed25519',
		'signature_kid'      => 'release-2026q3',
		'signature'          => $signature,
		'signature_required' => true,
		'artifact_file'      => $file_name,
		'artifact_size'      => strlen( $body ),
		'artifact_signed_at' => $signed_at,
	];

	$previous = (object) [ 'marker' => 'previous' ];
	$updater  = new WPBridge_Updater( 'wpbridge/wpbridge.php', WPBRIDGE_VERSION );

	// Run one update check against a canned server item, then (optionally) the download.
	$check = static function ( array $item ) use ( $updater, $previous ) {
		SafeHttpClient::$calls          = 0;
		SafeHttpClient::$response       = [
			'code' => 200,
			'body' => json_encode( [ 'plugins' => [ 'wpbridge/wpbridge.php' => $item ] ] ),
		];
		Logger::$entries                = [];
		$GLOBALS['wpb_site_transients'] = [];
		$result                         = $updater->check_update( $previous, [ 'Version' => WPBRIDGE_VERSION ], 'wpbridge/wpbridge.php', [] );
		if ( is_object( $result ) && isset( $result->package ) ) {
			// Mirror WordPress: the offer is cached in update_plugins until download.
			$GLOBALS['wpb_site_transients']['update_plugins'] = (object) [ 'response' => [ 'wpbridge/wpbridge.php' => $result ] ];
		}
		return $result;
	};
	$download = static function ( string $package_url, string $file_body ) use ( $updater ) {
		$GLOBALS['wpb_download_body'] = $file_body;
		$reply                        = false;
		if ( method_exists( $updater, 'enforce_self_update_record' ) ) {
			$reply = $updater->enforce_self_update_record( $reply, $package_url );
		}
		return PackageIntegrityVerifier::verify( $reply, $package_url );
	};
	$offered = static function ( $result ) use ( $previous ): bool {
		return $result !== $previous && is_object( $result ) && isset( $result->new_version );
	};
	$code = static function ( $value ): string {
		return is_wp_error( $value ) ? (string) $value->get_error_code() : ( is_string( $value ) && is_file( $value ) ? 'file' : var_export( $value, true ) );
	};

	// Shared by both modes: a present-but-wrong signature never installs.
	$bad_sig              = $signed_item;
	$bad_sig['signature'] = str_repeat( 'A', 86 );

	if ( 'keys' === $mode ) {
		// K1 positive control.
		$result = $check( $signed_item );
		$assert( $offered( $result ) && true === $result->signature_required, 'K1 valid signed response is offered and marked signature_required' );
		$assert( 'file' === $code( $download( $package, $body ) ), 'K1 valid signed package verifies at download' );

		// K2 THE downgrade: server strips signature and says signature_required=false.
		$stripped                       = $base_item + [ 'sha256' => $sha256 ];
		$stripped['signature_required'] = false;
		$result                         = $check( $stripped );
		$assert( ! $offered( $result ), 'K2 server signature_required=false + no signature is rejected when a key is configured (downgrade blocked)' );
		$assert( in_array( 'error', Logger::levels(), true ), 'K2 rejected unsigned self-update is logged at error level (always recorded)' );

		// K3 signature fields simply absent (current wenpai-bridge shape: no sha256, no signature).
		$result = $check( $base_item );
		$assert( ! $offered( $result ), 'K3 response without sha256 and signature is rejected when a key is configured' );

		// K4 signature present, sha256 missing.
		$no_sha = $signed_item;
		unset( $no_sha['sha256'] );
		$no_sha['signature_required'] = false;
		$result                       = $check( $no_sha );
		$assert( ! $offered( $result ), 'K4 response without sha256 is rejected when a key is configured' );

		// K5 invalid signature: may be offered (format is valid) but never installs.
		$check( $bad_sig );
		$assert( 'wpbridge_signature_mismatch' === $code( $download( $package, $body ) ), 'K5 wrong signature is rejected at download' );

		// K6 unknown kid with signature_required=false from server.
		$unknown                       = $signed_item;
		$unknown['signature_kid']      = 'attacker-key';
		$unknown['signature_required'] = false;
		$check( $unknown );
		$assert( 'wpbridge_signature_unknown_key' === $code( $download( $package, $body ) ), 'K6 signature from an untrusted kid is rejected at download even if the server says not required' );

		// K7 tampered package with valid metadata.
		$check( $signed_item );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'K7 tampered package is rejected at download' );

		// K8 integrity record lost (cache eviction) but WordPress still has the offer cached.
		$check( $signed_item );
		PackageIntegrityVerifier::forget( $package );
		$assert( is_wp_error( $download( $package, $body ) ), 'K8 offered self-update package without an integrity record is refused at download when a key is configured' );

		// K9 Site Health reports enforcement.
		SafeHttpClient::$calls = 0;
		$health = ( new ReflectionClass( \WPBridge\Core\SiteHealth::class ) )->newInstanceWithoutConstructor();
		$tests  = $health->add_tests( [] );
		$assert( isset( $tests['direct']['wpbridge_selfupdate_signature'] ), 'K9 Site Health registers wpbridge_selfupdate_signature' );
		$status = method_exists( $health, 'test_selfupdate_signature' ) ? $health->test_selfupdate_signature() : [];
		$assert( 'good' === ( $status['status'] ?? '' ), 'K9 Site Health signature check is good when a key is configured' );
	} else {
		// N1 current production shape: no sha256, no signature => still offered, warned.
		$result = $check( $base_item );
		$assert( $offered( $result ) && false === $result->signature_required, 'N1 unsigned response is still offered when no key is configured (existing sites keep updating)' );
		$assert( in_array( 'warning', Logger::levels(), true ), 'N1 unsigned self-update offer logs a warning' );
		$assert( false === $download( $package, $body ), 'N1 unsigned package without a record is left to WordPress (legacy path unchanged)' );

		// N2 sha256 only: still verified at download.
		$check( $base_item + [ 'sha256' => $sha256 ] );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'N2 advertised sha256 is still enforced at download' );

		// N3 signature present but no local key: always rejected.
		$check( $signed_item );
		$assert( 'wpbridge_signature_unknown_key' === $code( $download( $package, $body ) ), 'N3 a present signature with no trusted local key is rejected at download' );
		$check( $bad_sig );
		$assert( is_wp_error( $download( $package, $body ) ), 'N3 a present invalid signature is rejected at download' );

		// N4 server may strengthen: signature_required=true without signature => not offered.
		$result = $check( $base_item + [ 'sha256' => $sha256, 'signature_required' => true ] );
		$assert( ! $offered( $result ), 'N4 server-declared signature_required with no signature is not offered' );

		// N5 Site Health notice.
		SafeHttpClient::$calls = 0;
		$health = ( new ReflectionClass( \WPBridge\Core\SiteHealth::class ) )->newInstanceWithoutConstructor();
		$tests  = $health->add_tests( [] );
		$assert( isset( $tests['direct']['wpbridge_selfupdate_signature'] ), 'N5 Site Health registers wpbridge_selfupdate_signature' );
		$status = method_exists( $health, 'test_selfupdate_signature' ) ? $health->test_selfupdate_signature() : [];
		$assert( 'recommended' === ( $status['status'] ?? '' ) && false !== strpos( (string) ( $status['description'] ?? '' ), 'WPBRIDGE_ARTIFACT_PUBLIC_KEYS' ), 'N5 Site Health shows a notice naming WPBRIDGE_ARTIFACT_PUBLIC_KEYS when no key is configured' );
		$assert( 0 === SafeHttpClient::$calls, 'N5 Site Health signature check makes no network calls' );
	}

	exit( $failures > 0 ? 1 : 0 );
}
