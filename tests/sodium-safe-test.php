<?php
/**
 * SodiumSafe contract: verification never throws, failures are logged without
 * secrets, memzero never throws.  Runs with ext-sodium or sodium_compat.
 *
 * @package WPBridge
 */

namespace WPBridge\Core {
	class Settings {}
	class Logger {
		/** @var array<int, array{0:string,1:array}> */
		public static array $errors = [];
		public static function error( string $message, array $context = [] ): void {
			self::$errors[] = [ $message, $context ];
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data );
		}
	}
	require_once dirname( __DIR__ ) . '/includes/Security/SodiumSafe.php';

	use WPBridge\Core\Logger;
	use WPBridge\Security\SodiumSafe;

	$failures = 0;
	$assert   = static function ( bool $ok, string $label ) use ( &$failures ): void {
		echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . "\n";
		if ( ! $ok ) {
			++$failures;
		}
	};
	echo 'ext-sodium loaded: ' . ( extension_loaded( 'sodium' ) ? 'yes' : 'no (sodium_compat)' ) . "\n";

	$keypair = sodium_crypto_sign_keypair();
	$public  = sodium_crypto_sign_publickey( $keypair );
	$secret  = sodium_crypto_sign_secretkey( $keypair );
	$message = "WENPAI-RELEASE-SIGNATURE-V1\nslug:wpbridge";
	$sig     = sodium_crypto_sign_detached( $message, $secret );

	$assert( true === SodiumSafe::verify_detached( $sig, $message, $public ) && [] === Logger::$errors, 'Valid signature verifies and logs nothing' );

	$cases = [
		'tampered signature'    => [ substr_replace( $sig, chr( ord( $sig[10] ) ^ 0x01 ), 10, 1 ), $message, $public ],
		'tampered message'      => [ $sig, $message . 'x', $public ],
		'small-order signature' => [ str_repeat( "\0", 64 ), $message, $public ],
		'truncated signature'   => [ substr( $sig, 0, 63 ), $message, $public ],
		'truncated public key'  => [ $sig, $message, substr( $public, 0, 31 ) ],
		'non-string signature'  => [ null, $message, $public ],
	];
	foreach ( $cases as $label => $args ) {
		Logger::$errors = [];
		$threw  = false;
		$result = null;
		try {
			$result = SodiumSafe::verify_detached( ...$args );
		} catch ( \Throwable $e ) {
			$threw = true;
		}
		$logged = 1 === count( Logger::$errors );
		$dump   = $logged ? json_encode( Logger::$errors[0][1] ) . Logger::$errors[0][0] : '';
		$leak   = false !== strpos( $dump, bin2hex( $public ) ) || false !== strpos( $dump, base64_encode( $public ) ) || ( is_string( $args[0] ) && '' !== $args[0] && false !== strpos( $dump, bin2hex( $args[0] ) ) );
		$assert( ! $threw && false === $result && $logged && ! $leak, "{$label}: returns false, does not throw, logs one error without key/signature" );
	}

	$wipe  = $secret;
	$threw = false;
	try {
		SodiumSafe::memzero( $wipe );
	} catch ( \Throwable $e ) {
		$threw = true;
	}
	$assert( ! $threw && ( '' === $wipe || ( is_string( $wipe ) && str_repeat( "\0", strlen( $wipe ) ) === $wipe ) ), 'memzero does not throw and leaves an empty or all-zero string' );
	$not_string = 123;
	SodiumSafe::memzero( $not_string );
	$assert( '' === $not_string, 'memzero on a non-string leaves an empty string' );

	if ( $failures > 0 ) {
		echo "{$failures} SodiumSafe assertion(s) failed.\n";
		exit( 1 );
	}
	echo "SodiumSafe tests passed.\n";
}
