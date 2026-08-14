<?php
/** Dynamic controller to Bridge handler/client and Spoke verifier E2E. */
declare(strict_types=1);
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function sanitize_title( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( $value ) ) ); }
function wp_tempnam( string $name ): string { unset( $name ); return (string) tempnam( sys_get_temp_dir(), 'wpb-controller-' ); }
function wp_delete_file( string $file ): bool { return ! is_file( $file ) || unlink( $file ); }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function rest_url( string $route ): string { return 'https://hub.example/wp-json/' . ltrim( $route, '/' ); }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( string $hook, $value, ...$args ) { return 'wpbridge_pre_resolve_host' === $hook ? [ '8.8.8.8' ] : $value; }
class WP_Error { private string $code; public function __construct( string $code = '', string $message = '', $data = null ) { unset( $message, $data ); $this->code = $code; } public function get_error_code(): string { return $this->code; } }
class WP_REST_Request implements ArrayAccess {
	private array $params; private string $route;
	public function __construct( array $params, string $route ) { $this->params = $params; $this->route = $route; }
	public function get_route(): string { return $this->route; }
	public function offsetExists( $offset ): bool { return isset( $this->params[ $offset ] ); }
	public function offsetGet( $offset ) { return $this->params[ $offset ] ?? null; }
	public function offsetSet( $offset, $value ): void { $this->params[ $offset ] = $value; }
	public function offsetUnset( $offset ): void { unset( $this->params[ $offset ] ); }
}
class WP_REST_Response { private $data; private int $status; public array $headers = []; public function __construct( $data, int $status = 200 ) { $this->data = $data; $this->status = $status; } public function get_data() { return $this->data; } public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; } }
require_once dirname( __DIR__ ) . '/includes/Core/Logger.php';
require_once dirname( __DIR__ ) . '/includes/Security/Validator.php';
require_once dirname( __DIR__ ) . '/includes/Security/PackageIntegrityVerifier.php';
require_once dirname( __DIR__ ) . '/includes/Security/Encryption.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/InstallationIdentity.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/HandlerInterface.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/ProtectedPackageHandlerInterface.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/AbstractHandler.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/UpdateInfo.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/SourceModel.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/SourceManager.php';
require_once dirname( __DIR__ ) . '/includes/Commercial/BridgeClient.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/BridgeServerHandler.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeStore.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/LinkAuthorizer.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/SpokeProxyClient.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeController.php';
use WPBridge\Commercial\BridgeClient;
use WPBridge\HubSpoke\HubSpokeController;
use WPBridge\HubSpoke\HubSpokeStore;
use WPBridge\HubSpoke\LinkAuthorizer;
use WPBridge\HubSpoke\SpokeProxyClient;
use WPBridge\UpdateSource\Handlers\BridgeServerHandler;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\UpdateSource\SourceModel;
$failures = 0;
$assert = static function ( bool $ok, string $message ) use ( &$failures ): void { echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $message . "\n"; if ( ! $ok ) { ++$failures; } };
$b64 = static function ( string $v ): string { return rtrim( strtr( base64_encode( $v ), '+/', '-_' ), '=' ); };
$body = 'controller-real-handler-package'; $slug = 'commercial-plugin'; $version = '2.0.0'; $artifact = $slug . '-' . $version . '.zip'; $signed = '2026-08-14T00:00:00Z'; $sha = hash( 'sha256', $body );
$pair = sodium_crypto_sign_seed_keypair( str_repeat( "\x66", SODIUM_CRYPTO_SIGN_SEEDBYTES ) ); $public = $b64( sodium_crypto_sign_publickey( $pair ) );
$canonical = "WENPAI-RELEASE-SIGNATURE-V1\nslug:{$slug}\nversion:{$version}\nfile:{$artifact}\nsize:" . strlen( $body ) . "\nsha256:{$sha}\nsigned_at:{$signed}\n";
$info = [ 'version' => $version, 'sha256' => $sha, 'artifact_file' => $artifact, 'artifact_size' => strlen( $body ), 'artifact_signed_at' => $signed, 'signature_scheme' => 'ed25519', 'signature_kid' => 'controller-e2e', 'signature' => $b64( sodium_crypto_sign_detached( $canonical, sodium_crypto_sign_secretkey( $pair ) ) ) ];
$keyring = [ 'controller-e2e' => [ 'public_key' => $public, 'status' => 'active', 'not_before' => '2026-01-01T00:00:00Z' ] ];
$api = static function ( string $url ) use ( $info ): array { return false !== strpos( $url, '/capabilities' ) ? [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'endpoints' => [ 'plugin_info' => '/api/v1/plugin/{slug}', 'download' => '/api/v1/download/{slug}' ] ] ) ] : [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $info ) ]; };
$package = static function ( string $url, array $args ) use ( $body ): array { unset( $url ); file_put_contents( $args['filename'], $body ); return [ 'response' => [ 'code' => 200 ] ]; };
$client = new BridgeClient( 'https://bridge.example', '', 30, $package, $api );
$source = new SourceModel(); $source->api_url = 'https://bridge.example'; $source->slug = $slug; $source->metadata = [ 'artifact_public_keys' => $keyring ];
$handler = new BridgeServerHandler( $source, $client, static function (): string { return str_repeat( 'g', 64 ); } );
$source_model = new class( $handler, $slug ) extends SourceModel { private BridgeServerHandler $handler; public function __construct( BridgeServerHandler $handler, string $slug ) { $this->handler = $handler; $this->slug = $slug; } public function get_handler(): ?WPBridge\UpdateSource\Handlers\HandlerInterface { return $this->handler; } };
$manager = ( new ReflectionClass( SourceManager::class ) )->newInstanceWithoutConstructor();
$manager_class = new class( $source_model ) extends SourceManager { private SourceModel $model; public function __construct( SourceModel $model ) { $this->model = $model; } public function get_by_slug( string $slug, string $item_type = 'plugin' ): array { unset( $item_type ); return $slug === $this->model->slug ? [ $this->model ] : []; } };
unset( $manager );
$store = ( new ReflectionClass( HubSpokeStore::class ) )->newInstanceWithoutConstructor(); $auth = new LinkAuthorizer( $store ); $auth_prop = new ReflectionProperty( LinkAuthorizer::class, 'authorized_link' ); $auth_prop->setAccessible( true ); $auth_prop->setValue( $auth, [ 'link_id' => 'e2e' ] );
$controller = ( new ReflectionClass( HubSpokeController::class ) )->newInstanceWithoutConstructor(); foreach ( [ 'sources' => $manager_class, 'authorizer' => $auth ] as $name => $value ) { $p = new ReflectionProperty( HubSpokeController::class, $name ); $p->setAccessible( true ); $p->setValue( $controller, $value ); }
$request = new WP_REST_Request( [ 'slug' => $slug ], '/wpbridge/v2/hub-proxy/plugins/' . $slug . '/package' ); $response = $controller->proxy_package( $request );
ob_start(); $served = $controller->serve_package( false, $response, $request, null ); $streamed = (string) ob_get_clean();
$assert( $served && $body === $streamed, 'Instantiated controller resolves a real BridgeServerHandler and streams BridgeClient-verified bytes' );
$spoke_ref = new ReflectionClass( SpokeProxyClient::class ); $ctor = $spoke_ref->getConstructor(); $ctor->setAccessible( true ); $spoke = $spoke_ref->newInstanceWithoutConstructor(); $ctor->invoke( $spoke, [ 'hub_origin' => 'https://hub.example', 'slug_allowlist' => [ $slug ], 'credential' => 'WPBL1-' . $b64( str_repeat( 'L', 32 ) ) ], static function ( string $url, array $args ) use ( $streamed ): array { unset( $url ); file_put_contents( $args['filename'], $streamed ); return [ 'response' => [ 'code' => 200 ] ]; } );
$record = $handler->protected_integrity( $slug, $info ); $downloaded = $spoke->download( $slug, $record ); $assert( is_string( $downloaded ) && is_file( $downloaded ), 'Streamed controller bytes pass the real Spoke signature verifier' ); if ( is_string( $downloaded ) ) { wp_delete_file( $downloaded ); }
exit( $failures > 0 ? 1 : 0 );
