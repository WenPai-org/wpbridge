<?php
/** Site-bound update authorization contract checks. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'wpbridge-update-auth-test-key' );
$GLOBALS['wpbridge_test_options'] = [];
function __( string $text, string $domain = '' ): string { return $text; }
function get_option( string $name, $default = false ) { return $GLOBALS['wpbridge_test_options'][ $name ] ?? $default; }
function is_admin(): bool { return false; }
function add_action( ...$args ): bool { return true; }
function do_action( ...$args ): void {}
function apply_filters( string $name, $value, ...$args ) {
	if ( 'wpbridge_pre_resolve_host' === $name ) { return [ '203.0.113.10' ]; }
	return $value;
}
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( array $response ): string { return (string) $response['body']; }
class WP_Error {
	private string $code;
	private string $message;
	public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; $this->message = $message; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}
require_once dirname( __DIR__ ) . '/includes/Security/Validator.php';
require_once dirname( __DIR__ ) . '/includes/Security/Encryption.php';
require_once dirname( __DIR__ ) . '/includes/Commercial/UpdateAuthorizationClient.php';
use WPBridge\Commercial\UpdateAuthorizationClient;
$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
	if ( ! $condition ) { ++$failures; }
};
$seed = str_repeat( "\x11", SODIUM_CRYPTO_SIGN_SEEDBYTES );
$keypair = sodium_crypto_sign_seed_keypair( $seed );
$public = sodium_crypto_sign_publickey( $keypair );
$pairingCode = 'WPB1-' . rtrim( strtr( base64_encode( str_repeat( "\x22", 32 ) ), '+/', '-_' ), '=' );
$capturedPair = [];
$pairTransport = static function ( string $url, array $args ) use ( &$capturedPair ): array {
	$capturedPair = compact( 'url', 'args' );
	return [ 'response' => [ 'code' => 201 ], 'body' => json_encode( [
		'device_id' => 'dev_abcdefghijklmnop', 'entitlement_id' => 'ent_7',
		'site_url' => 'https://customer.example.test', 'product_slug' => 'commercial-plugin',
		'paired_at' => '2026-08-13T12:00:00Z',
	], JSON_UNESCAPED_SLASHES ) ];
};
$paired = UpdateAuthorizationClient::pair(
	'https://license.wenpai.net', $pairingCode, 'https://customer.example.test/',
	$pairTransport, static fn (): string => $keypair
);
$assert( is_array( $paired ), 'One-time pairing returns source metadata' );
$assert( isset( $paired['update_private_key'] ) && 0 === strpos( $paired['update_private_key'], '$wpb$2$' ), 'Device private key is encrypted before source storage' );
$assert( false === strpos( json_encode( $paired ), rtrim( strtr( base64_encode( sodium_crypto_sign_secretkey( $keypair ) ), '+/', '-_' ), '=' ) ), 'Plain device private key is absent from stored metadata' );
$pairBody = json_decode( $capturedPair['args']['body'], true );
$assert( $capturedPair['url'] === 'https://license.wenpai.net/api/v1/updates/pair' && $pairBody['pairing_code'] === $pairingCode, 'Pairing code is sent only in the POST body' );
$capturedGrant = [];
$grantTransport = static function ( string $url, array $args ) use ( &$capturedGrant, $public ): array {
	$capturedGrant = compact( 'url', 'args' );
	$timestamp = $args['headers']['X-WenPai-Timestamp'];
	$canonical = "POST\n/api/v1/updates/grants\n{$timestamp}\n" . hash( 'sha256', $args['body'] );
	$signature = base64_decode( strtr( $args['headers']['X-WenPai-Signature'], '-_', '+/' ) . '==', true );
	if ( ! is_string( $signature ) || ! sodium_crypto_sign_verify_detached( $signature, $canonical, $public ) ) {
		return [ 'response' => [ 'code' => 401 ], 'body' => '{}' ];
	}
	return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
		'grant' => 'wpg1.header.payload.signature', 'scope' => 'updates:read',
		'product_slug' => 'commercial-plugin', 'expires_at' => '2026-08-13T12:05:00Z',
	], JSON_UNESCAPED_SLASHES ) ];
};
$client = new UpdateAuthorizationClient( $paired, $grantTransport, static fn (): int => 1786603200 );
$grant = $client->issue_grant( 'commercial-plugin', 'metadata' );
$assert( is_string( $grant ) && 0 === strpos( $grant, 'wpg1.' ), 'A signed metadata grant is accepted only for the paired product' );
$assert( false === strpos( $capturedGrant['url'], 'wpg1.' ) && false === strpos( $capturedGrant['url'], $pairingCode ), 'Grant and pairing code never enter a request URL' );
$assert( $capturedGrant['args']['headers']['X-WenPai-Device'] === 'dev_abcdefghijklmnop', 'Grant request is bound to the paired device identifier' );
$assert( $capturedGrant['args']['headers']['X-WenPai-Signature'] === 'YNmnb1UIB0UhirIjjxNvFxgLeF09Uagm56s6abyOnsrjlHHeYTUJV8-Ajc1PCnkpWJ0p7l6BnU9kC1SeK5BVBg', 'PHP device signature matches the frozen cross-language vector' );
$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Commercial/UpdateAuthorizationClient.php' );
$assert( false === strpos( $source, 'str_starts_with(' ) && false === strpos( $source, '__construct( private ' ), 'Update authorization client remains compatible with PHP 7.4' );
$handlerSource = (string) file_get_contents( dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/BridgeServerHandler.php' );
$assert( false !== strpos( $handlerSource, "metadata['update_bridge_url']" ) && false !== strpos( $handlerSource, 'hash_equals( $paired_bridge, rtrim( $server_url' ), 'Paired credentials are bound to the exact Bridge Server URL' );
$adminSource = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Admin/AdminPage.php' );
$assert( false !== strpos( $adminSource, "'update_private_key', 'update_site_url', 'update_product_slug', 'update_bridge_url'" ), 'Changing source type clears stored update authorization metadata' );
exit( $failures > 0 ? 1 : 0 );
