<?php
/** Controller integrity record through the real protected Bridge downloader. */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function sanitize_title( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( $value ) ) ); }
	function wp_tempnam( string $name ): string { unset( $name ); return (string) tempnam( sys_get_temp_dir(), 'wpb-hub-e2e-' ); }
	function wp_delete_file( string $file ): bool { return ! is_file( $file ) || unlink( $file ); }
	function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
	function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	class WP_Error {
		private string $code;
		public function __construct( string $code = '', string $message = '', $data = null ) { unset( $message, $data ); $this->code = $code; }
		public function get_error_code(): string { return $this->code; }
	}
}

namespace WPBridge\Core {
	final class Logger { public static function error( string $message, array $context = [] ): void { unset( $message, $context ); } }
}

namespace WPBridge\Security {
	final class Validator { public static function is_valid_url( string $url ): bool { return 0 === strpos( $url, 'https://' ); } }
	final class SafeHttpClient { public static function request( string $url, array $args ) { unset( $url, $args ); return new \WP_Error( 'offline' ); } }
}

namespace WPBridge\UpdateSource\Handlers {
	abstract class AbstractHandler {}
	interface ProtectedPackageHandlerInterface {}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/Security/PackageIntegrityVerifier.php';
	require_once dirname( __DIR__ ) . '/includes/Commercial/BridgeClient.php';
	require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/BridgeServerHandler.php';
	require_once dirname( __DIR__ ) . '/includes/HubSpoke/SpokeProxyClient.php';

	use WPBridge\Commercial\BridgeClient;
	use WPBridge\UpdateSource\Handlers\BridgeServerHandler;
	use WPBridge\HubSpoke\SpokeProxyClient;

	$failures = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
		if ( ! $condition ) { ++$failures; }
	};
	$b64 = static function ( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); };
	$body = 'hub-protected-package-fixture';
	$slug = 'commercial-plugin';
	$version = '2.0.0';
	$file = $slug . '-' . $version . '.zip';
	$signed_at = '2026-08-14T00:00:00Z';
	$sha = hash( 'sha256', $body );
	$canonical = "WENPAI-RELEASE-SIGNATURE-V1\nslug:{$slug}\nversion:{$version}\nfile:{$file}\nsize:" . strlen( $body ) . "\nsha256:{$sha}\nsigned_at:{$signed_at}\n";
	$keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x55", SODIUM_CRYPTO_SIGN_SEEDBYTES ) );
	$keyring = [ 'hub-e2e' => [ 'public_key' => $b64( sodium_crypto_sign_publickey( $keypair ) ), 'status' => 'active', 'not_before' => '2026-01-01T00:00:00Z' ] ];
	$info = [
		'version' => $version, 'sha256' => $sha, 'artifact_file' => $file,
		'artifact_size' => strlen( $body ), 'artifact_signed_at' => $signed_at,
		'signature_scheme' => 'ed25519', 'signature_kid' => 'hub-e2e',
		'signature' => $b64( sodium_crypto_sign_detached( $canonical, sodium_crypto_sign_secretkey( $keypair ) ) ),
	];
	$record = BridgeServerHandler::protected_integrity_record( $slug, $info, $keyring );
	$download_body = $body;
	$transport = static function ( string $url, array $args ) use ( &$download_body ): array {
		file_put_contents( (string) $args['filename'], $download_body );
		return [ 'response' => [ 'code' => 200 ] ];
	};
	$client = new BridgeClient( 'https://bridge.example', '', 30, $transport );
	$result = $client->download_package( $slug, str_repeat( 'a', 64 ), $record );
	$assert( is_string( $result ) && is_file( $result ), 'Controller integrity record succeeds through the real protected downloader and verifier' );
	if ( is_string( $result ) ) { wp_delete_file( $result ); }
	$download_body = $body . '-tampered';
	$result = $client->download_package( $slug, str_repeat( 'b', 64 ), $record );
	$assert( is_wp_error( $result ) && 'wpbridge_checksum_mismatch' === $result->get_error_code(), 'Real protected downloader fails closed for tampered Hub package bytes' );
	$download_body = $body;
	$bad_size = $record;
	$bad_size['artifact_size'] = strlen( $body ) + 1;
	$result = $client->download_package( $slug, str_repeat( 'c', 64 ), $bad_size );
	$assert( is_wp_error( $result ) && 'wpbridge_artifact_size_mismatch' === $result->get_error_code(), 'Real protected downloader fails closed for mismatched artifact size' );
	$bad_kid = $record;
	$bad_kid['signature_kid'] = 'unknown-kid';
	$result = $client->download_package( $slug, str_repeat( 'd', 64 ), $bad_kid );
	$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Real protected downloader fails closed for an unapproved signing kid' );
	$spoke_transport = static function ( string $url, array $args ) use ( $body ): array {
		unset( $url );
		file_put_contents( (string) $args['filename'], $body );
		return [ 'response' => [ 'code' => 200 ] ];
	};
	$reflection = new ReflectionClass( SpokeProxyClient::class );
	$constructor = $reflection->getConstructor();
	$constructor->setAccessible( true );
	$spoke = $reflection->newInstanceWithoutConstructor();
	$constructor->invoke( $spoke, [ 'hub_origin' => 'https://hub.example', 'slug_allowlist' => [ $slug ], 'credential' => 'WPBL1-' . $b64( str_repeat( 'L', 32 ) ) ], $spoke_transport );
	$result = $spoke->download( $slug, $record );
	$assert( is_string( $result ) && is_file( $result ), 'REST-streamed Hub bytes pass the real Spoke-side signature verifier' );
	if ( is_string( $result ) ) { wp_delete_file( $result ); }
	$controller = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeController.php' );
	$assert( false !== strpos( $controller, '$handler->protected_integrity( $slug, $info )' ), 'Hub controller passes the handler-built verifier record to the protected downloader' );
	exit( $failures > 0 ? 1 : 0 );
}
