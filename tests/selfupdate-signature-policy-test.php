<?php
/**
 * Self-update signature policy contracts (P0: signature downgrade).
 *
 * Run once per mode, because the key rings are constants:
 *   php tests/selfupdate-signature-policy-test.php dedicated  (WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS set: strict)
 *   php tests/selfupdate-signature-policy-test.php shared     (only WPBRIDGE_ARTIFACT_PUBLIC_KEYS set)
 *   php tests/selfupdate-signature-policy-test.php none       (shipped default: no key)
 *   php tests/selfupdate-signature-policy-test.php empty|string|badkey
 *       (WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS defined but holds no valid key: strict, fail closed)
 *
 * Policy under test:
 * - Dedicated ring set => every self-update must carry a valid Ed25519
 *   signature and SHA-256; no server field can weaken this.
 * - Shared ring only => a present signature is verified against it and
 *   rejected if invalid/untrusted; a missing signature is allowed + warning.
 * - No ring => cannot verify signatures: allowed + warning.
 * - All modes: an advertised SHA-256 must match the downloaded package.
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
		public static array $history = [];

		public static function is_enabled( string $level ): bool {
			return true;
		}
		public static function debug( string $message, array $context = [] ): void {
			self::$entries[] = [ 'debug', $message, $context ];
			self::$history[] = [ 'debug', $message, $context ];
		}
		public static function warning( string $message, array $context = [] ): void {
			self::$entries[] = [ 'warning', $message, $context ];
			self::$history[] = [ 'warning', $message, $context ];
		}
		public static function error( string $message, array $context = [] ): void {
			self::$entries[] = [ 'error', $message, $context ];
			self::$history[] = [ 'error', $message, $context ];
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
	if ( ! in_array( $mode, [ 'dedicated', 'shared', 'none', 'empty', 'string', 'badkey' ], true ) ) {
		fwrite( STDERR, "usage: php selfupdate-signature-policy-test.php dedicated|shared|none|empty|string|badkey\n" );
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
	$ring = static function ( string $key ): array {
		return [
			'release-2026q3' => [
				'public_key' => $key,
				'status'     => 'active',
				'not_before' => '2026-01-01T00:00:00Z',
			],
		];
	};
	define( 'WPBRIDGE_BASENAME', 'wpbridge/wpbridge.php' );
	if ( 'empty' === $mode ) {
		define( 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS', [] );
	} elseif ( 'string' === $mode ) {
		define( 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS', $public_key );
	} elseif ( 'badkey' === $mode ) {
		define( 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS', [ 'release-2026q3' => 'not-a-key' ] );
	}
	if ( 'dedicated' === $mode ) {
		// One valid key plus one broken entry: Site Health must count only the valid one.
		define( 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS', $ring( $public_key ) + [ 'broken' => 'not-a-key' ] );
		// Same kid, different key: proves the self-updater does not read the shared ring in strict mode.
		$other = sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( "\x44", SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
		define( 'WPBRIDGE_ARTIFACT_PUBLIC_KEYS', $ring( $b64url( $other ) ) );
	} elseif ( 'shared' === $mode ) {
		define( 'WPBRIDGE_ARTIFACT_PUBLIC_KEYS', $ring( $public_key ) );
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

	require_once dirname( __DIR__ ) . '/includes/Security/SodiumSafe.php';
	require_once dirname( __DIR__ ) . '/includes/Security/PackageIntegrityVerifier.php';
	require_once dirname( __DIR__ ) . '/includes/class-wenpai-updater.php';
	require_once dirname( __DIR__ ) . '/includes/Core/SiteHealth.php';
	require_once dirname( __DIR__ ) . '/includes/UpdateSource/PluginUpdater.php';

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
	$check = static function ( array $item, bool $fresh = true ) use ( $updater, $previous ) {
		SafeHttpClient::$calls          = 0;
		SafeHttpClient::$response       = [
			'code' => 200,
			'body' => json_encode( [ 'plugins' => [ 'wpbridge/wpbridge.php' => $item ] ] ),
		];
		Logger::$entries                = [];
		if ( $fresh ) {
			$GLOBALS['wpb_site_transients'] = [];
			$GLOBALS['wpb_transients']      = [];
		}
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

	// A present-but-wrong signature, and a tampered body, are shared fixtures.
	$bad_sig              = $signed_item;
	$bad_sig['signature'] = str_repeat( 'A', 86 );
	$sha_only             = $base_item + [ 'sha256' => $sha256 ];
	$health_status        = static function (): array {
		SafeHttpClient::$calls = 0;
		$health                = ( new ReflectionClass( \WPBridge\Core\SiteHealth::class ) )->newInstanceWithoutConstructor();
		$tests                 = $health->add_tests( [] );
		$status                = method_exists( $health, 'test_selfupdate_signature' ) ? $health->test_selfupdate_signature() : [];
		$status['registered']  = isset( $tests['direct']['wpbridge_selfupdate_signature'] );
		return $status;
	};
	$warned_with = static function ( string $needle ): bool {
		foreach ( Logger::$entries as $entry ) {
			if ( 'warning' === $entry[0] && false !== strpos( $entry[1], $needle ) ) {
				return true;
			}
		}
		return false;
	};

	// Signs an arbitrary record with a given secret key (fixtures for other code paths).
	$sign_record = static function ( string $slug, string $secret ) use ( $body, $sha256, $signed_at, $b64url ): array {
		$file      = $slug . '-1.3.2.zip';
		$canonical = "WENPAI-RELEASE-SIGNATURE-V1\nslug:{$slug}\nversion:1.3.2\nfile:{$file}\nsize:" . strlen( $body ) . "\nsha256:{$sha256}\nsigned_at:{$signed_at}\n";
		return [
			'slug'               => $slug,
			'version'            => '1.3.2',
			'artifact_file'      => $file,
			'artifact_size'      => strlen( $body ),
			'artifact_signed_at' => $signed_at,
			'signature_scheme'   => 'ed25519',
			'signature_kid'      => 'release-2026q3',
			'signature'          => $b64url( sodium_crypto_sign_detached( $canonical, $secret ) ),
			'signature_required' => true,
		];
	};
	// Caches an offer for the wpbridge package and a record written by some other code path.
	$plant = static function ( array $record, array $keyring ) use ( $package, $sha256 ): void {
		$GLOBALS['wpb_site_transients']                   = [];
		$GLOBALS['wpb_site_transients']['update_plugins'] = (object) [ 'response' => [ 'wpbridge/wpbridge.php' => (object) [ 'package' => $package, 'new_version' => '1.3.2', 'sha256' => $sha256, 'signature' => $record['signature'] ] ] ];
		PackageIntegrityVerifier::remember( $package, $sha256, 86400, $record + [ 'artifact_public_keys' => $keyring ] );
	};
	$digest_tail_changed = substr( $sha256, 0, 32 ) . strrev( substr( $sha256, 32 ) );
	if ( $digest_tail_changed === $sha256 ) {
		$digest_tail_changed = substr( $sha256, 0, 63 ) . ( 'f' === $sha256[63] ? 'e' : 'f' );
	}
	$warnings_total = static function (): int {
		return count( array_filter( Logger::$history, static fn( $e ) => 'warning' === $e[0] ) );
	};
	$no_php_warning = static function ( callable $fn ) {
		$raised = false;
		set_error_handler(
			static function () use ( &$raised ) {
				$raised = true;
				return true;
			}
		);
		$result = $fn();
		restore_error_handler();
		return [ $result, $raised ];
	};

	// Mimics WP_Upgrader::download_package(): runs every registered upgrader_pre_download
	// callback (plus test-only extras) in priority order with WordPress' argument list,
	// then falls back to core's own download_url() when the chain returns false.
	$run_chain = static function ( string $package_url, array $hook_extra = [ 'plugin' => 'wpbridge/wpbridge.php' ], array $extra = [] ) {
		$chain = [];
		$seq   = 0;
		foreach ( $GLOBALS['wpb_filters'] as $f ) {
			if ( 'upgrader_pre_download' === $f[0] ) {
				$chain[] = [ $f[2] ?? 10, $seq++, $f[1], $f[3] ?? 1 ];
			}
		}
		foreach ( $extra as $prio => $cb ) {
			$chain[] = [ $prio, $seq++, $cb, 2 ];
		}
		usort( $chain, static fn( $a, $b ) => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		$reply = false;
		foreach ( $chain as $link ) {
			$reply = call_user_func_array( $link[2], array_slice( [ $reply, $package_url, null, $hook_extra ], 0, $link[3] ) );
		}
		if ( is_wp_error( $reply ) ) {
			return [ 'error', (string) $reply->get_error_code() ];
		}
		$by = 'filter';
		if ( false === $reply ) {
			$reply = download_url( $package_url );
			$by    = 'core';
		}
		return [ $by, is_string( $reply ) && is_file( $reply ) ? hash_file( 'sha256', $reply ) : var_export( $reply, true ) ];
	};
	$filter_priority = static function ( string $method ): array {
		$found = [];
		foreach ( $GLOBALS['wpb_filters'] as $f ) {
			if ( 'upgrader_pre_download' === $f[0] && is_array( $f[1] ) && ( $f[1][1] ?? '' ) === $method ) {
				$found[] = [ $f[2] ?? 10, $f[3] ?? 1 ];
			}
		}
		return $found;
	};
	$evil       = 'wpbridge-1.3.2-EVIL';
	$mirror     = static fn( string $u ): string => str_replace( 'updates.wenpai.net', 'mirror.example', $u );
	$sha_reject = static function ( array $item ) use ( $check, $offered, $no_php_warning ): bool {
		[ $result, $raised ] = $no_php_warning( static fn() => $check( $item ) );
		return ! $raised && ! $offered( $result );
	};

	// Errors logged since $from (index into Logger::$history), optionally filtered by message needle.
	$errors_since = static function ( int $from, string $needle = '' ): int {
		$n = 0;
		foreach ( array_slice( Logger::$history, $from ) as $entry ) {
			if ( 'error' === $entry[0] && ( '' === $needle || false !== strpos( $entry[1], $needle ) ) ) {
				++$n;
			}
		}
		return $n;
	};

	if ( in_array( $mode, [ 'empty', 'string', 'badkey' ], true ) ) {
		$assert( ! $offered( $check( $signed_item ) ), 'E1 dedicated constant without a valid key: even a validly signed update is not offered (fail closed)' );
		$assert( ! $offered( $check( $base_item ) ), 'E2 dedicated constant without a valid key: unsigned update is not offered' );
		$plant( $sign_record( 'wpbridge', $secret_key ), [ 'release-2026q3' => [ 'public_key' => $public_key, 'status' => 'active' ] ] );
		$assert( is_wp_error( $download( $package, $body ) ), 'E3 dedicated constant without a valid key: install is refused even with a planted valid record' );
		$status = $health_status();
		$assert( 'recommended' === ( $status['status'] ?? '' ) && false !== strpos( (string) ( $status['description'] ?? '' ) . (string) ( $status['label'] ?? '' ), '配置错误：WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS 无有效公钥，自更新已暂停' ), 'E4 Site Health reports the configuration error as recommended' );
	} elseif ( 'dedicated' === $mode ) {
		// Shared constant holds a DIFFERENT key under the same kid: D1 only passes if the dedicated ring is used.
		$result = $check( $signed_item );
		$assert( $offered( $result ) && true === $result->signature_required, 'D1 valid signed response is offered and marked signature_required' );
		$assert( 'file' === $code( $download( $package, $body ) ), 'D1 package verifies against WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS (not the shared ring)' );

		$stripped                       = $sha_only;
		$stripped['signature_required'] = false;
		$result                         = $check( $stripped );
		$assert( ! $offered( $result ), 'D2 server signature_required=false + no signature is rejected in strict mode (downgrade blocked)' );
		$assert( in_array( 'error', Logger::levels(), true ), 'D2 strict rejection is logged at error level' );

		$assert( ! $offered( $check( $base_item ) ), 'D3 response without sha256 and signature is rejected in strict mode' );

		$no_sha = $signed_item;
		unset( $no_sha['sha256'] );
		$assert( ! $offered( $check( $no_sha ) ), 'D4 signed response without sha256 is rejected in strict mode' );

		$check( $bad_sig );
		$assert( 'wpbridge_signature_mismatch' === $code( $download( $package, $body ) ), 'D5 wrong signature is rejected at download' );

		$unknown                       = $signed_item;
		$unknown['signature_kid']      = 'attacker-key';
		$unknown['signature_required'] = false;
		$check( $unknown );
		$assert( 'wpbridge_signature_unknown_key' === $code( $download( $package, $body ) ), 'D6 untrusted kid is rejected at download even if the server says not required' );

		$check( $signed_item );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'D7 SHA-256 mismatch is rejected at download (strict)' );

		$check( $signed_item );
		PackageIntegrityVerifier::forget( $package );
		$assert( is_wp_error( $download( $package, $body ) ), 'D8 offered package without an integrity record is refused at download in strict mode' );

		$check( $signed_item );
		$result = $check( $signed_item, false );
		$assert( $offered( $result ), 'D9 a repeated identical check is still offered (set_site_transient returns false when unchanged)' );

		$status = $health_status();
		$assert( $status['registered'] && 'good' === ( $status['status'] ?? '' ) && false !== strpos( (string) ( $status['description'] ?? '' ), 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' ), 'D10 Site Health is good and names the strict-mode constant' );
		$assert( false !== strpos( (string) ( $status['description'] ?? '' ), '（1 个公钥）' ), 'D10 Site Health counts only valid keys (1 valid + 1 broken entry => 1)' );

		// D11 (review B1/P1): a record written by another path (PluginUpdater / preset source), signed by a key
		// that is only in the SHARED ring and carrying that ring, must not install in strict mode.
		$other_secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( "\x44", SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
		$plant( $sign_record( 'wpbridge', $other_secret ), WPBRIDGE_ARTIFACT_PUBLIC_KEYS );
		$assert( is_wp_error( $download( $package, $body ) ), 'D11 strict: record signed by a shared-ring-only key (stored shared keyring) is re-verified against the dedicated ring and rejected' );

		$plant( $sign_record( 'other-plugin', $secret_key ), WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS );
		$assert( is_wp_error( $download( $package, $body ) ), 'D11 strict: validly signed record for another slug is rejected for the wpbridge package' );

		$plant( [ 'signature_required' => false ] + $sign_record( 'wpbridge', $secret_key ), WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS );
		$assert( is_wp_error( $download( $package, $body ) ), 'D12 strict: record without the signature_required marker is rejected (M10)' );

		$plant( $sign_record( 'wpbridge', $secret_key ), WPBRIDGE_ARTIFACT_PUBLIC_KEYS );
		$assert( 'file' === $code( $download( $package, $body ) ), 'D12 positive control: a dedicated-key signature installs even if the stored ring was the shared one' );

		// D11 PluginUpdater must leave wpbridge's own plugin to WPBridge_Updater.
		$pu = ( new ReflectionClass( \WPBridge\UpdateSource\PluginUpdater::class ) )->newInstanceWithoutConstructor();
		try {
			$skipped_download = false === $pu->filter_download( false, 'https://updates.wenpai.net/api/v1/download/wpbridge', null );
		} catch ( \Throwable $e ) {
			$skipped_download = false;
		}
		$assert( $skipped_download, 'D11 PluginUpdater::filter_download (priority 5) skips the wpbridge package' );
		try {
			$skipped_info = false === $pu->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'wpbridge' ] );
		} catch ( \Throwable $e ) {
			$skipped_info = false;
		}
		$assert( $skipped_info, 'D11 PluginUpdater::plugin_info skips wpbridge' );
		$offer = (object) [ 'package' => $package, 'new_version' => '1.3.2' ];
		try {
			$transient = $pu->check_updates( (object) [ 'response' => [ 'wpbridge/wpbridge.php' => $offer ], 'no_update' => [] ] );
			$kept      = ( $transient->response['wpbridge/wpbridge.php'] ?? null ) === $offer;
		} catch ( \Throwable $e ) {
			$kept = false;
		}
		$assert( $kept, 'D11 PluginUpdater::check_updates leaves the wpbridge self-update offer untouched' );

		// R2: guard runs before PluginUpdater::filter_download (5) and receives hook_extra; final check is last.
		$guard = $filter_priority( 'enforce_self_update_record' );
		$final = $filter_priority( 'final_self_update_check' );
		$assert( 1 === count( $guard ) && $guard[0][0] < 5 && 4 === $guard[0][1], 'R2 download guard priority < 5 and accepts 4 args (hook_extra)' );
		$assert( [ [ PHP_INT_MAX, 4 ] ] === $final, 'R2 final self-update check runs at PHP_INT_MAX with 4 args' );

		// Full upgrader_pre_download chain (WordPress order) with the review's T scenarios.
		$GLOBALS['wpb_download_body'] = $body;
		$check( $signed_item );
		[ $by, $h ] = $run_chain( $package );
		$assert( 'filter' === $by && $sha256 === $h, 'T1 chain: the verified local file is what the upgrader gets' );

		$evil_file = tempnam( sys_get_temp_dir(), 'wpb-evil-' );
		file_put_contents( $evil_file, $evil );
		$check( $signed_item );
		$seen   = null;
		[ $by ] = $run_chain(
			$package,
			[ 'plugin' => 'wpbridge/wpbridge.php' ],
			[
				10 => static function ( $r ) use ( $evil_file, &$seen ) {
					$seen = $r;
					return $evil_file;
				},
			]
		);
		$assert( 'error' === $by, 'T2 a later filter replacing the verified path is rejected by the final check' );
		$assert( is_string( $seen ) && ! file_exists( $seen ) && file_exists( $evil_file ), 'NB6/X9 T2: the guard\'s own verified download is deleted, the foreign replacement path is not' );

		$check( $signed_item );
		[ $by ] = $run_chain(
			$package,
			[ 'plugin' => 'wpbridge/wpbridge.php' ],
			[
				20 => static function ( $r ) use ( $evil, &$seen ) {
					$seen = $r;
					if ( is_string( $r ) && is_file( $r ) ) {
						file_put_contents( $r, $evil );
					}
					return $r;
				},
			]
		);
		$assert( is_string( $seen ) && ! file_exists( $seen ), 'NB6 T2b: the rewritten own download is deleted by the final check' );
		$assert( 'error' === $by, 'T2b in-place rewrite of the verified file after the guard is rejected by the final check' );

		$check( $signed_item );
		$early = tempnam( sys_get_temp_dir(), 'wpb-early-' );
		file_put_contents( $early, $evil );
		[ $by ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 0 => static fn( $r ) => $early ] );
		$assert( 'error' === $by && file_exists( $early ), 'T3/NB2 strict: an earlier filter returning a tampered external file is rejected and NOT deleted' );

		// NB2: an earlier symlink reply is refused without following it; neither link nor target is deleted.
		$link_target = tempnam( sys_get_temp_dir(), 'wpb-lnkt-' );
		$link        = $link_target . '.lnk';
		file_put_contents( $link_target, $evil );
		symlink( $link_target, $link );
		$check( $signed_item );
		[ $by, $c ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 0 => static fn( $r ) => $link ] );
		$assert( 'error' === $by && is_link( $link ) && file_exists( $link_target ), 'NB2 strict: an earlier symlink to a tampered file is rejected; link and target are kept' );
		file_put_contents( $link_target, $body );
		$check( $signed_item );
		[ $by, $c ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 0 => static fn( $r ) => $link ] );
		$assert( 'error' === $by && 'wpbridge_selfupdate_unverifiable_package' === $c && is_link( $link ), 'NB2 strict: an earlier symlink is not followed even when its target is the genuine package' );

		// NB2: a symlink handed to the final check (re-pointed at the verified file) is rejected; the link is kept.
		$late_link = $link_target . '.late';
		$check( $signed_item );
		[ $by ] = $run_chain(
			$package,
			[ 'plugin' => 'wpbridge/wpbridge.php' ],
			[
				20 => static function ( $r ) use ( $late_link ) {
					symlink( $r, $late_link );
					return $late_link;
				},
			]
		);
		$assert( 'error' === $by && is_link( $late_link ), 'NB2 strict: a re-pointed symlink at the final check is rejected and not deleted' );

		// NB2: the verified path itself swapped for a symlink to identical bytes is still refused (target may change
		// after the check), and the symlink is neither followed nor deleted.
		$keep = tempnam( sys_get_temp_dir(), 'wpb-keep-' );
		$swap = null;
		$check( $signed_item );
		[ $by ] = $run_chain(
			$package,
			[ 'plugin' => 'wpbridge/wpbridge.php' ],
			[
				20 => static function ( $r ) use ( $keep, &$swap ) {
					if ( is_string( $r ) && is_file( $r ) ) {
						copy( $r, $keep );
						unlink( $r );
						symlink( $keep, $r );
						$swap = $r;
					}
					return $r;
				},
			]
		);
		$assert( 'error' === $by && is_string( $swap ) && is_link( $swap ) && file_exists( $keep ), 'NB2 strict: verified path replaced by a symlink to identical bytes is rejected; link and target are kept' );

		// NB6/X15: a later WP_Error still removes the guard's own verified download.
		$check( $signed_item );
		$seen   = null;
		[ $by, $c ] = $run_chain(
			$package,
			[ 'plugin' => 'wpbridge/wpbridge.php' ],
			[
				10 => static function ( $r ) use ( &$seen ) {
					$seen = $r;
					return new WP_Error( 'later_failure' );
				},
			]
		);
		$assert( 'later_failure' === $c && is_string( $seen ) && ! file_exists( $seen ), 'NB6/X15 a later WP_Error is kept and the verified own download is deleted' );

		// NB4/B3-3: the guard itself refuses a non-file earlier reply (not only the final check).
		$check( $signed_item );
		$assert( 'wpbridge_selfupdate_unverifiable_package' === $code( $updater->enforce_self_update_record( 'https://elsewhere.example/x.zip', $package, null, [ 'plugin' => 'wpbridge/wpbridge.php' ] ) ), 'NB4 guard refuses a URL reply from an earlier filter' );
		$assert( 'wpbridge_selfupdate_unverifiable_package' === $code( $updater->enforce_self_update_record( true, $package, null, [ 'plugin' => 'wpbridge/wpbridge.php' ] ) ), 'NB4 guard refuses a non-string earlier reply' );

		// NB5/X16 (N1-b): shared-era record (signature, signature_required=false) + strict check with malformed metadata.
		$GLOBALS['wpb_site_transients'] = [];
		$GLOBALS['wpb_transients']      = [];
		$shared_rec                     = $signed_item;
		unset( $shared_rec['package'], $shared_rec['id'] );
		$shared_rec['signature_required']   = false;
		$shared_rec['artifact_public_keys'] = WPBRIDGE_ARTIFACT_PUBLIC_KEYS;
		PackageIntegrityVerifier::remember( $package, $sha256, 86400, $shared_rec );
		$broken                       = $signed_item;
		$broken['artifact_file']      = '';
		$broken['signature_required'] = false;
		$assert( ! $offered( $check( $broken, false ) ), 'NB5 strict: a shared-era record does not make a malformed strict response look recorded (N1-b)' );

		$check( $signed_item );
		$early_ok = tempnam( sys_get_temp_dir(), 'wpb-early-' );
		file_put_contents( $early_ok, $body );
		[ $by, $h ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 0 => static fn( $r ) => $early_ok ] );
		$assert( 'filter' === $by && $sha256 === $h, 'T3 strict: an earlier filter returning the genuine package passes re-verification' );

		$check( $signed_item );
		[ $by, $c ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 0 => static fn( $r ) => new WP_Error( 'early_failure' ) ] );
		$assert( 'error' === $by && 'early_failure' === $c, 'T3 strict: an earlier WP_Error is returned unchanged' );

		$check( $signed_item );
		[ $by ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 0 => static fn( $r ) => 'https://elsewhere.example/x.zip' ] );
		$assert( 'error' === $by, 'T3 strict: an earlier non-file reply is rejected' );

		$check( $signed_item );
		$GLOBALS['wpb_download_body'] = static fn( $u ) => false !== strpos( $u, 'mirror' ) ? $evil : $body;
		[ $by ] = $run_chain( $mirror( $package ) );
		$assert( 'error' === $by, 'T4 URL rewritten by upgrader_package_options (hook_extra plugin) is still strictly verified and rejected' );
		$check( $signed_item );
		[ $by ] = $run_chain( $mirror( $package ), [ 'plugins' => [ 'wpbridge/wpbridge.php' ] ] );
		$assert( 'error' === $by, 'T4 bulk-style hook_extra plugins => [wpbridge] with a rewritten URL is rejected' );
		$GLOBALS['wpb_download_body'] = static fn( $u ) => $body;
		$check( $signed_item );
		[ $by, $h ] = $run_chain( $mirror( $package ) );
		$assert( 'filter' === $by && $sha256 === $h, 'T4 rewritten URL serving the genuine package installs after strict verification' );
		$GLOBALS['wpb_download_body'] = $body;

		// Another plugin's upgrade (hook_extra names it) is never touched, even if it reuses our URL.
		$check( $signed_item );
		$GLOBALS['wpb_download_body'] = $evil;
		$assert( false === $updater->enforce_self_update_record( false, $package, null, [ 'plugin' => 'other/other.php' ] ), 'T4 strict guard leaves another plugin\'s download alone (hook_extra plugin=other)' );
		$assert( 'x' === $updater->final_self_update_check( 'x', $package, null, [ 'plugin' => 'other/other.php' ] ), 'T4 strict final check leaves another plugin\'s reply alone' );
		$GLOBALS['wpb_download_body'] = $body;

		// A later filter turning the guard's refusal into a file is still rejected by the final check.
		$check( $signed_item );
		$GLOBALS['wpb_download_body'] = $evil;
		[ $by ] = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], [ 10 => static fn( $r ) => $evil_file ] );
		$assert( 'error' === $by, 'T2c a later filter replacing the guard\'s WP_Error with a file is rejected (nothing was verified)' );
		$GLOBALS['wpb_download_body'] = $body;

		// R16: temp file removed after a failed strict verification.
		$check( $signed_item );
		$GLOBALS['wpb_dl_files'] = [];
		$download( $package, $evil );
		$assert( [] !== $GLOBALS['wpb_dl_files'] && ! file_exists( end( $GLOBALS['wpb_dl_files'] ) ), 'R16 strict verification failure deletes the downloaded temp file' );

		// D14: strict check_update stores the dedicated ring, so the priority-10 verifier agrees with the guard (kills M6).
		$check( $signed_item );
		$record = PackageIntegrityVerifier::expected_integrity( $package );
		$assert( ( $record['artifact_public_keys'] ?? null ) == PackageIntegrityVerifier::trusted_keys( WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS ), 'D14 strict record carries the dedicated ring' );
		$GLOBALS['wpb_download_body'] = $body;
		$assert( 'file' === $code( PackageIntegrityVerifier::verify( false, $package ) ), 'D14 verify(false, $package) bypassing the guard installs the genuine package' );

		// N1: a stale sha-only record must not make an unsigned response look recorded.
		$GLOBALS['wpb_site_transients'] = [];
		PackageIntegrityVerifier::remember( $package, $sha256, 86400, [ 'slug' => 'wpbridge', 'version' => '1.3.2' ] );
		$assert( ! $offered( $check( $sha_only, false ) ), 'N1 strict: unsigned response is not offered even with a stale sha-only record' );

		// N8: download-stage security errors are not deduped.
		$GLOBALS['wpb_transients'] = [];
		$before                    = count( array_filter( Logger::$history, static fn( $e ) => 'error' === $e[0] ) );
		$check( $signed_item );
		$download( $package, $evil );
		$check( $signed_item, false );
		$download( $package, $evil );
		$errors = count( array_filter( Logger::$history, static fn( $e ) => 'error' === $e[0] ) ) - $before;
		$assert( $errors >= 2, 'N8 two strict verification failures for the same version log two errors' );

		// Every download-stage rejection logs an error (not deduped, N8).
		$reject_cases = [
			'later filter swaps the path'       => [ 10 => static fn( $r ) => $evil_file ],
			'earlier filter WP_Error'           => [ 0 => static fn( $r ) => new WP_Error( 'early_failure' ) ],
			'earlier non-file reply'            => [ 0 => static fn( $r ) => 'https://elsewhere.example/x.zip' ],
			'earlier symlink reply'             => [ 0 => static fn( $r ) => $link ],
			'later filter returns a WP_Error'   => [ 10 => static fn( $r ) => new WP_Error( 'later_failure' ) ],
			'earlier tampered local file'       => [ 0 => static fn( $r ) => $early ],
		];
		foreach ( $reject_cases as $label => $extra ) {
			file_put_contents( $early, $evil );
			$check( $signed_item );
			$GLOBALS['wpb_download_body'] = $body;
			$h0                           = count( Logger::$history );
			[ $by ]                       = $run_chain( $package, [ 'plugin' => 'wpbridge/wpbridge.php' ], $extra );
			$assert( 'error' === $by && 1 === $errors_since( $h0 ), "LOG strict rejection ({$label}) logs exactly one error (got " . $errors_since( $h0 ) . ')' );
		}
		$check( $signed_item );
		$h0 = count( Logger::$history );
		$download( $package, $evil );
		$assert( 1 === $errors_since( $h0 ), 'LOG strict verify failure (tampered download) logs exactly one error' );

		// D13 (review N4): takeover by wp-china-yes means our hooks are not registered.
		$GLOBALS['wpb_override'] = true;
		$status                  = $health_status();
		unset( $GLOBALS['wpb_override'] );
		$assert( false !== strpos( (string) ( $status['label'] ?? '' ), '集中更新' ) && false === strpos( (string) ( $status['label'] ?? '' ), '已启用' ), 'D13 Site Health does not claim strict verification is active when wp-china-yes took over' );
	} elseif ( 'shared' === $mode ) {
		$result = $check( $base_item );
		$assert( $offered( $result ), 'S1 unsigned response is offered when only the shared ring is set (commercial-source sites keep self-updating)' );
		$assert( in_array( 'warning', Logger::levels(), true ), 'S1 unsigned offer logs a warning' );
		$assert( false === $download( $package, $body ), 'S1 unsigned package without sha256 is left to WordPress' );

		$check( $sha_only );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'S2 SHA-256 mismatch is rejected at download (shared-only, unsigned)' );

		$result = $check( $signed_item );
		$assert( $offered( $result ), 'S3 valid signature is offered' );
		$assert( 'file' === $code( $download( $package, $body ) ), 'S3 present signature verifies against the shared ring' );

		$wrong                       = $bad_sig;
		$wrong['signature_required'] = false;
		$check( $wrong );
		$assert( 'wpbridge_signature_mismatch' === $code( $download( $package, $body ) ), 'S4 present wrong signature is rejected even when the server says signature_required=false' );

		$unknown                       = $signed_item;
		$unknown['signature_kid']      = 'attacker-key';
		$unknown['signature_required'] = false;
		$check( $unknown );
		$assert( 'wpbridge_signature_unknown_key' === $code( $download( $package, $body ) ), 'S5 present signature from an untrusted kid is rejected' );

		$broken                       = $signed_item;
		$broken['artifact_file']      = '';
		$broken['signature_required'] = false;
		$assert( ! $offered( $check( $broken ) ), 'S6 present signature with malformed metadata is not offered (cannot fall back to unsigned)' );

		$check( $sha_only );
		PackageIntegrityVerifier::forget( $package );
		$assert( is_wp_error( $download( $package, $body ) ), 'S7 advertised sha256 whose record was lost is refused at download' );

		$result = $check( $sha_only + [ 'signature_required' => true ] );
		$assert( ! $offered( $result ), 'S9 server signature_required=true with the signature missing is rejected in shared mode' );

		$check( $base_item + [ 'sha256' => $digest_tail_changed ] );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body ) ), 'S10 digest differing only in its second half is rejected (M5)' );

		$check( $base_item + [ 'sha256' => '', 'checksum_sha256' => $sha256 ] );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'S11 empty sha256 does not mask a valid checksum_sha256 (review N2)' );
		$assert( $sha_reject( $sha_only + [ 'checksum_sha256' => [ 'x' ] ] ), "S12 shared: malformed checksum_sha256 rejects even with a valid sha256 (N2')" );

		// Tester e293805: A-3/A-5/A-m5 were refused by PackageIntegrityVerifier::verify (priority 10) without any log.
		foreach ( [
			'A-5 tampered package vs advertised sha256 (signature valid)' => [ $signed_item, $evil ],
			'A-3 signature does not match the downloaded package'          => [ $signed_item, $body . 'x' ],
			'sha-only offer, tampered package'                             => [ $sha_only, $evil ],
		] as $label => [ $offer_item, $served ] ) {
			$check( $offer_item );
			$GLOBALS['wpb_download_body'] = $served;
			$h0                           = count( Logger::$history );
			[ $by ]                       = $run_chain( $package );
			$assert( 'error' === $by && 1 === $errors_since( $h0 ), "LOG shared rejection ({$label}) logs one error (got " . $errors_since( $h0 ) . ')' );
		}
		$GLOBALS['wpb_download_body'] = $body;

		$status = $health_status();
		$assert( $status['registered'] && 'good' === ( $status['status'] ?? '' ) && false !== strpos( (string) ( $status['description'] ?? '' ), 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' ), 'S8 Site Health is good (not recommended) and explains WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' );
	} else {
		$result = $check( $base_item );
		$assert( $offered( $result ) && false === $result->signature_required, 'N1 unsigned response is offered when no key is configured' );
		$assert( in_array( 'warning', Logger::levels(), true ), 'N1 unsigned offer logs a warning' );
		$assert( false === $download( $package, $body ), 'N1 unsigned package without sha256 is left to WordPress' );

		$check( $sha_only );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'N2 SHA-256 mismatch is rejected at download (no keys)' );

		$result = $check( $signed_item );
		$assert( $offered( $result ) && $warned_with( '未配置公钥，签名未校验' ), 'N3 present signature with no key configured is allowed with warning 未配置公钥，签名未校验' );
		$assert( 'file' === $code( $download( $package, $body ) ), 'N3 package with an unverifiable signature installs after its SHA-256 matches' );

		$check( $bad_sig );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'N4 unverifiable signature does not skip the SHA-256 check' );

		$result = $check( $sha_only + [ 'signature_required' => true ] );
		$assert( ! $offered( $result ) && in_array( 'error', Logger::levels(), true ), 'N5 server signature_required=true with the signature missing is rejected even without keys (server may only tighten)' );

		$result = $check( $signed_item );
		$assert( $offered( $result ) && $warned_with( '未配置公钥，签名未校验' ), 'N5b server signature_required=true with a signature present but no key is allowed with warning' );

		$malformed           = $base_item;
		$malformed['sha256'] = 'not-a-sha256';
		$assert( ! $offered( $check( $malformed ) ), 'N6 a malformed advertised sha256 is not offered' );

		$check( $sha_only );
		PackageIntegrityVerifier::forget( $package );
		$assert( is_wp_error( $download( $package, $body ) ), 'N7 advertised sha256 whose record was lost is refused at download' );

		$check( $base_item + [ 'sha256' => $digest_tail_changed ] );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body ) ), 'N9 digest differing only in its second half is rejected (M5)' );

		$check( $base_item + [ 'sha256' => '', 'checksum_sha256' => $sha256 ] );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), 'N10 empty sha256 does not mask a valid checksum_sha256 (review N2)' );

		[ $result, $raised ] = $no_php_warning( static fn() => $check( $base_item + [ 'signature' => [ 'y' ] ] ) );
		$assert( ! $raised && $offered( $result ), 'N11 a non-string signature is treated as absent without PHP warnings' );
		$assert( $sha_reject( $base_item + [ 'sha256' => [ $sha256 ] ] ), "N2' none: array sha256 is malformed => not offered, no PHP warning" );
		$assert( $sha_reject( $base_item + [ 'sha256' => 12345 ] ), "N2' none: numeric sha256 is malformed => not offered" );
		$assert( $sha_reject( $base_item + [ 'sha256' => (object) [ 'a' => 1 ] ] ), "N2' none: object sha256 is malformed => not offered" );
		$assert( $sha_reject( $base_item + [ 'sha256' => $sha256, 'checksum_sha256' => [ 'x' ] ] ), "N2' none: a malformed checksum_sha256 next to a valid sha256 is still rejected" );
		$assert( $sha_reject( $base_item + [ 'sha256' => $sha256, 'checksum_sha256' => hash( 'sha256', 'other' ) ] ), "N2' none: two well-formed but different digests are rejected" );
		$h0 = count( Logger::$history );
		$sha_reject( $base_item + [ 'sha256' => $sha256, 'checksum_sha256' => hash( 'sha256', 'other' ) ] );
		$assert( 1 === $errors_since( $h0, '不一致' ) && 0 === $errors_since( $h0, '格式无效' ), 'MISMATCH two different digests log their own message (not the malformed-format one)' );
		$h0 = count( Logger::$history );
		$sha_reject( $base_item + [ 'sha256' => [ $sha256 ] ] );
		$sha_reject( $base_item + [ 'sha256' => 'abc' ] );
		$assert( 2 === $errors_since( $h0, '格式无效' ) && 0 === $errors_since( $h0, '不一致' ), 'MISMATCH malformed digests keep the format-invalid message' );
		$h0 = count( Logger::$history );
		$sha_reject( $base_item + [ 'sha256' => 'abc', 'checksum_sha256' => hash( 'sha256', 'other' ) ] );
		$assert( 1 === $errors_since( $h0, '格式无效' ), 'MISMATCH one malformed plus one valid digest reports the format error' );
		$check( $sha_only );
		$GLOBALS['wpb_download_body'] = $evil;
		$h0                           = count( Logger::$history );
		[ $by ]                       = $run_chain( $package );
		$assert( 'error' === $by && 1 === $errors_since( $h0 ), 'LOG none-mode checksum mismatch at download logs one error' );
		$GLOBALS['wpb_download_body'] = $body;
		$check( $base_item + [ 'sha256' => $sha256, 'checksum_sha256' => strtoupper( $sha256 ) ] );
		$assert( 'file' === $code( $download( $package, $body ) ), "N2' none: the same digest in both fields (any case) is accepted" );
		$check( $base_item + [ 'sha256' => null, 'checksum_sha256' => $sha256 ] );
		$assert( 'wpbridge_checksum_mismatch' === $code( $download( $package, $body . 'x' ) ), "N2' none: null sha256 counts as absent and checksum_sha256 is enforced" );

		// T4 (all modes keep the checksum): rewritten URL still checks the advertised digest.
		$check( $sha_only );
		$GLOBALS['wpb_download_body'] = static fn( $u ) => $evil;
		[ $by, $c ] = $run_chain( $mirror( $package ) );
		$assert( 'error' === $by && 'wpbridge_checksum_mismatch' === $c, 'T4 none: rewritten URL still enforces the advertised sha256' );
		$GLOBALS['wpb_download_body'] = $body;

		$before = $warnings_total();
		$check( $base_item );
		$check( $base_item, false );
		$check( $base_item, false );
		$assert( 1 === $warnings_total() - $before, 'N12 the unsigned-offer warning is logged once per plugin+version+event per day (review N3)' );

		$status = $health_status();
		$assert( $status['registered'] && 'good' === ( $status['status'] ?? '' ) && false !== strpos( (string) ( $status['description'] ?? '' ), 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' ), 'N8 Site Health is good (not recommended) and explains WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' );
		$assert( 0 === SafeHttpClient::$calls, 'N8 Site Health signature check makes no network calls' );
	}

	exit( $failures > 0 ? 1 : 0 );
}
