<?php
/** Dynamic one-shot package stream guard regression. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function wp_delete_file( string $file ): bool { return unlink( $file ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
class WP_Error {}
class WP_REST_Request {
	private string $route;
	public function __construct( string $route ) { $this->route = $route; }
	public function get_route(): string { return $this->route; }
}
class WP_REST_Response {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
}
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeStore.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HostCanonicalizer.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/LinkAuthorizer.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeController.php';
use WPBridge\HubSpoke\HubSpokeController;
use WPBridge\HubSpoke\HubSpokeStore;
use WPBridge\HubSpoke\LinkAuthorizer;
$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void { echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n"; if ( ! $condition ) { ++$failures; } };
$store_ref = new ReflectionClass( HubSpokeStore::class );
$store = $store_ref->newInstanceWithoutConstructor();
$authorizer = new LinkAuthorizer( $store );
$authorized = new ReflectionProperty( LinkAuthorizer::class, 'authorized_link' );
$authorized->setAccessible( true );
$authorized->setValue( $authorizer, [ 'link_id' => 'test' ] );
$controller_ref = new ReflectionClass( HubSpokeController::class );
$controller = $controller_ref->newInstanceWithoutConstructor();
$authorizer_property = new ReflectionProperty( HubSpokeController::class, 'authorizer' );
$authorizer_property->setAccessible( true );
$authorizer_property->setValue( $controller, $authorizer );
$streams = new ReflectionProperty( HubSpokeController::class, 'package_streams' );
$streams->setAccessible( true );
$file = tempnam( sys_get_temp_dir(), 'wpb-stream-' );
file_put_contents( $file, 'verified-stream-bytes' );
$streams->setValue( $controller, [ 'one-shot' => $file ] );
ob_start();
$served = $controller->serve_package( false, new WP_REST_Response( [ '_wpbridge_package_stream' => 'one-shot' ] ), new WP_REST_Request( '/wpbridge/v2/hub-proxy/plugins/wpbridge/package' ), null );
$bytes = (string) ob_get_clean();
$assert( $served && 'verified-stream-bytes' === $bytes && ! is_file( $file ), 'Exact authorized package route consumes and deletes its one-shot stream file' );
$assert( ! $controller->serve_package( false, new WP_REST_Response( [ '_wpbridge_package_stream' => 'one-shot' ] ), new WP_REST_Request( '/wpbridge/v2/hub-proxy/plugins/wpbridge/package' ), null ), 'Consumed stream token cannot be replayed' );
$malicious = tempnam( sys_get_temp_dir(), 'wpb-malicious-' );
file_put_contents( $malicious, 'must-survive' );
$served = $controller->serve_package( false, new WP_REST_Response( [ '_wpbridge_package_file' => $malicious ] ), new WP_REST_Request( '/wpbridge/v2/anything' ), null );
$assert( ! $served && is_file( $malicious ) && 'must-survive' === file_get_contents( $malicious ), 'Arbitrary REST response data cannot trigger file read or deletion' );
unlink( $malicious );
exit( $failures > 0 ? 1 : 0 );
