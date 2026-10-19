<?php
/**
 * REST route, self-authentication, health-shape and error-code contracts.
 *
 * Covers CT-13 (Hub-Spoke __return_true routes authenticate inside the callback)
 * and the bridge/v1 + wpbridge/v2 route inventory. WordPress-independent.
 */

declare(strict_types=1);

define( 'WPBRIDGE_HUB_SPOKE_ENABLED', true );
require __DIR__ . '/rest-contract-bootstrap.php';

use WPBridge\API\RestController;
use WPBridge\HubSpoke\HubSpokeController;

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
};
$root = dirname( __DIR__ );

// ---------------------------------------------------------------------------
// 1. wpbridge/v2 route table: method, callback and permission_callback per route.
// ---------------------------------------------------------------------------
$hub = ct_hub_controller();
$hub->register_routes();
$uuid  = '(?P<id>[0-9a-f-]{36})';
$slug  = '(?P<slug>[a-z0-9][a-z0-9-]{1,99})';
$expected_hub = [
	'POST /step-up'                                     => 'issue_step_up|__return_true',
	'POST /hub-links'                                   => 'create_link|admin_permission',
	'GET /hub-links'                                    => 'list_links|admin_permission',
	"POST /hub-links/{$uuid}/challenge"                 => 'challenge|__return_true',
	"DELETE /hub-invitations/{$uuid}"                   => 'cancel_invitation|cleanup_permission',
	"POST /hub-links/{$uuid}/acceptances"               => 'acceptance|__return_true',
	"POST /hub-links/{$uuid}/acceptance-compensations"  => 'acceptance_compensation|acceptance_compensation_permission',
	"POST /hub-links/{$uuid}/rotations"                 => 'rotate_link|admin_permission',
	"DELETE /hub-links/{$uuid}"                         => 'revoke_link|cleanup_permission',
	'POST /spoke-links/accept'                          => 'accept_local|admin_permission',
	'GET /spoke-links'                                  => 'spoke_status|admin_permission',
	"POST /spoke-links/{$uuid}/rotations"               => 'apply_spoke_rotation|admin_permission',
	"DELETE /spoke-links/{$uuid}"                       => 'unlink_spoke|cleanup_permission',
	"DELETE /spoke-acceptances/{$uuid}/recovery"        => 'resolve_uncertain_accept|cleanup_permission',
	'GET /hub-proxy/sources'                            => 'proxy_sources|sources_permission',
	"GET /hub-proxy/sources/{$slug}"                    => 'proxy_source|source_permission',
	"GET /hub-proxy/plugins/{$slug}/metadata"           => 'proxy_metadata|updates_permission',
	"GET /hub-proxy/plugins/{$slug}/package"            => 'proxy_package|packages_permission',
];
ksort( $expected_hub );
$actual_hub = ct_route_table( HubSpokeController::NAMESPACE );
$assert( 'wpbridge/v2' === HubSpokeController::NAMESPACE, 'Hub-Spoke namespace is wpbridge/v2' );
$assert( $expected_hub === $actual_hub, 'wpbridge/v2 exposes exactly 18 method+route pairs with the inventoried callback and permission_callback' );
if ( $expected_hub !== $actual_hub ) {
	echo '  diff: ' . json_encode( [ 'missing' => array_diff_assoc( $expected_hub, $actual_hub ), 'unexpected' => array_diff_assoc( $actual_hub, $expected_hub ) ], JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}
$public_hub = array_keys( array_filter( $actual_hub, static fn( string $v ): bool => '|__return_true' === substr( $v, -14 ) ) );
$expected_public = [ "POST /hub-links/{$uuid}/acceptances", "POST /hub-links/{$uuid}/challenge", 'POST /step-up' ];
sort( $expected_public );
sort( $public_hub );
$assert( $expected_public === $public_hub, 'Only step-up, challenge and acceptances use __return_true on wpbridge/v2' );
$assert( [] === array_filter( $actual_hub, static fn( string $v ): bool => false !== strpos( $v, 'MISSING' ) ), 'Every wpbridge/v2 endpoint declares a permission_callback' );

// ---------------------------------------------------------------------------
// 2. CT-13: /step-up authenticates inside StepUpVerifier::issue.
//    Mechanism: logged-in manage_options (multisite: manage_network_options +
//    super admin) + X-WP-Nonce(wp_rest) + same-origin Origin + password re-auth.
// ---------------------------------------------------------------------------
$password = 'correct horse battery staple';
$admin    = [ 'logged_in' => true, 'caps' => [ 'manage_options' ], 'super' => false, 'id' => 7, 'pass' => password_hash( $password, PASSWORD_DEFAULT ) ];
$good     = [ 'X-WP-Nonce' => 'valid-nonce', 'Origin' => 'https://site.example' ];
$step_up  = static function ( array $headers, $body ) use ( $hub ) {
	return $hub->issue_step_up( new WP_REST_Request( $headers, [], $body ) );
};

$GLOBALS['ct_user'] = [ 'logged_in' => false, 'caps' => [], 'super' => false, 'id' => 0, 'pass' => '' ];
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $step_up( $good, [ 'password' => $password ] ) ), 'CT-13 /step-up: anonymous caller gets wpbridge_admin_forbidden 403' );
$GLOBALS['ct_user'] = array_merge( $admin, [ 'caps' => [ 'edit_posts' ] ] );
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $step_up( $good, [ 'password' => $password ] ) ), 'CT-13 /step-up: logged-in user without manage_options gets 403' );
$GLOBALS['ct_multisite'] = true;
$GLOBALS['ct_user']      = array_merge( $admin, [ 'caps' => [ 'manage_network_options' ], 'super' => false ] );
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $step_up( $good, [ 'password' => $password ] ) ), 'CT-13 /step-up: multisite requires super admin as well as manage_network_options' );
$GLOBALS['ct_user']      = array_merge( $admin, [ 'caps' => [ 'manage_options' ], 'super' => true ] );
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $step_up( $good, [ 'password' => $password ] ) ), 'CT-13 /step-up: multisite site-level manage_options is not enough' );
$GLOBALS['ct_multisite'] = false;
$GLOBALS['ct_user']      = $admin;
$assert( [ 'wpbridge_nonce_invalid', 403 ] === ct_error( $step_up( [ 'Origin' => 'https://site.example' ], [ 'password' => $password ] ) ), 'CT-13 /step-up: missing X-WP-Nonce gets wpbridge_nonce_invalid 403' );
$assert( [ 'wpbridge_nonce_invalid', 403 ] === ct_error( $step_up( [ 'X-WP-Nonce' => 'forged', 'Origin' => 'https://site.example' ], [ 'password' => $password ] ) ), 'CT-13 /step-up: wrong nonce gets wpbridge_nonce_invalid 403' );
$assert( [ 'wpbridge_origin_invalid', 403 ] === ct_error( $step_up( [ 'X-WP-Nonce' => 'valid-nonce', 'Origin' => 'https://evil.example' ], [ 'password' => $password ] ) ), 'CT-13 /step-up: cross-origin request gets wpbridge_origin_invalid 403' );
$assert( [ 'wpbridge_origin_invalid', 403 ] === ct_error( $step_up( [ 'X-WP-Nonce' => 'valid-nonce', 'Origin' => 'http://site.example' ], [ 'password' => $password ] ) ), 'CT-13 /step-up: scheme downgrade in Origin is rejected' );
$assert( [ 'wpbridge_step_up_invalid', 400 ] === ct_error( $step_up( $good, [] ) ), 'CT-13 /step-up: body without password gets wpbridge_step_up_invalid 400' );
$assert( [ 'wpbridge_step_up_invalid', 400 ] === ct_error( $step_up( $good, [ 'password' => $password, 'user' => 'x' ] ) ), 'CT-13 /step-up: unexpected body keys get wpbridge_step_up_invalid 400' );
$assert( [ 'wpbridge_step_up_failed', 401 ] === ct_error( $step_up( $good, [ 'password' => 'wrong' ] ) ), 'CT-13 /step-up: wrong password gets wpbridge_step_up_failed 401' );
$assert( [] === $GLOBALS['ct_transients'], 'CT-13 /step-up: no proof is persisted for any rejected request' );

$issued = $step_up( $good, [ 'password' => $password ] );
$data   = $issued instanceof WP_REST_Response ? $issued->get_data() : [];
$assert( $issued instanceof WP_REST_Response && 201 === $issued->get_status(), 'CT-13 /step-up: correct password returns 201' );
$assert( [ 'expires_in', 'step_up_proof' ] === ( static function ( array $d ): array { $k = array_keys( $d ); sort( $k ); return $k; } )( (array) $data ) && 300 === ( $data['expires_in'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', (string) ( $data['step_up_proof'] ?? '' ) ), 'CT-13 /step-up: response is exactly {step_up_proof(43 b64url), expires_in:300}' );
$assert( $issued instanceof WP_REST_Response && 'no-store' === ( $issued->get_headers()['Cache-Control'] ?? '' ), 'CT-13 /step-up: proof response is Cache-Control: no-store' );
$record = $GLOBALS['ct_transients'][ 'wpbridge_step_up_' . hash( 'sha256', (string) ( $data['step_up_proof'] ?? '' ) ) ] ?? null;
$assert( is_array( $record ) && 7 === $record['user_id'] && hash( 'sha256', 'session-token-a' ) === $record['session_sha256'] && false === $record['cleanup_only'], 'CT-13 /step-up: proof is stored hashed and bound to user + session (not cleanup-only while enabled)' );

// admin_permission consumes the proof (round trip of the self-auth mechanism).
$proof_headers = array_merge( $good, [ 'X-WPBridge-Step-Up' => (string) ( $data['step_up_proof'] ?? '' ) ] );
$assert( true === $hub->admin_permission( new WP_REST_Request( $proof_headers ) ), 'admin_permission accepts a fresh proof from the same user and session' );
$assert( [ 'wpbridge_step_up_required', 401 ] === ct_error( $hub->admin_permission( new WP_REST_Request( $good ) ) ), 'admin_permission without X-WPBridge-Step-Up gets wpbridge_step_up_required 401' );
$GLOBALS['ct_user'] = array_merge( $admin, [ 'id' => 8 ] );
$assert( [ 'wpbridge_step_up_required', 401 ] === ct_error( $hub->admin_permission( new WP_REST_Request( $proof_headers ) ) ), 'admin_permission rejects a proof replayed by another administrator' );
$GLOBALS['ct_user'] = $admin;
$assert( true === $hub->cleanup_permission( new WP_REST_Request( $proof_headers ) ), 'cleanup_permission accepts the same proof (verify with cleanup=true)' );
$GLOBALS['ct_user'] = [ 'logged_in' => false, 'caps' => [], 'super' => false, 'id' => 0, 'pass' => '' ];
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $hub->cleanup_permission( new WP_REST_Request( $proof_headers ) ) ), 'cleanup_permission still requires an administrator session' );
$GLOBALS['ct_user'] = $admin;

// ---------------------------------------------------------------------------
// 3. CT-13: /challenge and /acceptances authenticate inside the callback.
//    Mechanism: feature flag -> Hub identity -> Host must equal frozen network
//    origin (hash_equals) -> exact body keys -> invitation token (and, for
//    acceptances, the spoke possession proof) verified by HubSpokeStore.
// ---------------------------------------------------------------------------
$link_id = '00000000-0000-4000-8000-00000000abcd';
$hub_req = static function ( array $headers, $body ) use ( $link_id ): WP_REST_Request {
	return new WP_REST_Request( $headers, [ 'id' => $link_id ], $body );
};
$accept_body = [ 'invitation_token' => 'x', 'spoke_installation_uuid' => 'x', 'spoke_public_key' => 'x', 'nonce' => 'x', 'timestamp' => 1, 'signature' => 'x' ];
foreach ( [ 'challenge' => [ 'invitation_token' => 'x' ], 'acceptance' => $accept_body ] as $method => $body ) {
	$assert( [ 'wpbridge_hub_origin_invalid', 400 ] === ct_error( $hub->$method( $hub_req( [ 'Host' => 'hub.example, evil.example' ], $body ) ) ), "CT-13 {$method}: malformed Host gets wpbridge_hub_origin_invalid 400" );
	$assert( [ 'wpbridge_hub_origin_mismatch', 401 ] === ct_error( $hub->$method( $hub_req( [ 'Host' => 'hub.example' ], $body ) ) ), "CT-13 {$method}: Host without a frozen network origin gets wpbridge_hub_origin_mismatch 401" );
}
$GLOBALS['ct_options']['wpbridge_hub_network_origin_v1'] = [ 'origin' => 'https://hub.example', 'created_at' => 1 ];
foreach ( [ 'challenge' => [ 'invitation_token' => 'x' ], 'acceptance' => $accept_body ] as $method => $body ) {
	$assert( [ 'wpbridge_hub_origin_mismatch', 401 ] === ct_error( $hub->$method( $hub_req( [ 'Host' => 'other.example' ], $body ) ) ), "CT-13 {$method}: Host outside the network origin gets wpbridge_hub_origin_mismatch 401" );
	$assert( [ 'wpbridge_request_invalid', 400 ] === ct_error( $hub->$method( $hub_req( [ 'Host' => 'hub.example' ], array_merge( $body, [ 'extra' => 1 ] ) ) ) ), "CT-13 {$method}: unexpected body keys get wpbridge_request_invalid 400" );
	$missing = $body;
	unset( $missing['invitation_token'] );
	$assert( [ 'wpbridge_request_invalid', 400 ] === ct_error( $hub->$method( $hub_req( [ 'Host' => 'hub.example' ], $missing ) ) ), "CT-13 {$method}: body without invitation_token gets wpbridge_request_invalid 400" );
	$result = $hub->$method( $hub_req( [ 'Host' => 'hub.example' ], $body ) );
	[ $code, $status ] = ct_error( $result );
	$assert( is_string( $code ) && 'wpbridge_request_invalid' !== $code && is_int( $status ) && $status >= 400 && $status < 500, "CT-13 {$method}: exact well-formed body reaches the store, which still rejects an unknown invitation with a 4xx ({$code} {$status})" );
}
$assert( [ 'wpbridge_request_invalid', 400 ] === ct_error( $hub->challenge( $hub_req( [ 'Host' => 'hub.example' ], [ 'invitation_token' => 123 ] ) ) ), 'CT-13 challenge: non-string invitation_token gets wpbridge_request_invalid 400' );

// Static gate order: the feature flag is checked first and the store is reached last.
$hub_src = (string) file_get_contents( $root . '/includes/HubSpoke/HubSpokeController.php' );
foreach ( [ 'challenge' => 'store->challenge(', 'acceptance' => 'store->accept(' ] as $method => $sink ) {
	preg_match( '/public function ' . $method . '\( \\\\WP_REST_Request \$request \) \{(.*?)\n\t\}/s', $hub_src, $m );
	$body  = $m[1] ?? '';
	$order = array_map( static fn( string $needle ): int => false === strpos( $body, $needle ) ? -1 : (int) strpos( $body, $needle ), [ 'LinkAuthorizer::enabled()', 'self::ensure_hub_identity()', 'self::request_origin( $request )', 'hash_equals( $this->store->network_origin(), $origin )', 'self::exact_keys(', $sink ] );
	$sorted = $order;
	sort( $sorted );
	$assert( ! in_array( -1, $order, true ) && $order === $sorted, "CT-13 {$method}: gates run in order enabled -> identity -> origin -> hash_equals -> exact_keys -> {$sink}" );
	$assert( 1 === preg_match( "/wpbridge_hub_spoke_disabled'[^;]*'status' => 503/", $body ), "CT-13 {$method}: disabled feature returns wpbridge_hub_spoke_disabled 503" );
}
preg_match( "/public function challenge\\(.*?self::exact_keys\\( \\\$body, \\[ ([^\\]]*) \\] \\)/s", $hub_src, $ck );
preg_match( "/public function acceptance\\(.*?self::exact_keys\\( \\\$body, \\[ ([^\\]]*) \\] \\)/s", $hub_src, $ak );
$assert( "'invitation_token'" === ( $ck[1] ?? '' ), 'CT-13 challenge: body key set is exactly {invitation_token}' );
$assert( "'invitation_token', 'spoke_installation_uuid', 'spoke_public_key', 'nonce', 'timestamp', 'signature'" === ( $ak[1] ?? '' ), 'CT-13 acceptances: body key set is exactly {invitation_token, spoke_installation_uuid, spoke_public_key, nonce, timestamp, signature}' );

// ---------------------------------------------------------------------------
// 4. Link-credential permissions on wpbridge/v2 (non __return_true but public-facing).
// ---------------------------------------------------------------------------
$valid_shape = 'WPBridge-Link WPBL1-' . str_repeat( 'A', 43 );
foreach ( [ 'sources_permission', 'source_permission', 'updates_permission', 'packages_permission', 'acceptance_compensation_permission' ] as $method ) {
	foreach ( [ 'api_key', 'link_credential', 'authorization' ] as $param ) {
		$req = new WP_REST_Request( [ 'Authorization' => $valid_shape, 'Host' => 'hub.example' ], [ 'id' => $link_id, 'slug' => 'demo' ], null, [ $param => 'secret' ] );
		$assert( [ 'wpbridge_query_credential_forbidden', 400 ] === ct_error( $hub->$method( $req ) ), "{$method}: ?{$param}= gets wpbridge_query_credential_forbidden 400" );
	}
	foreach ( [ '', 'Bearer WPBL1-' . str_repeat( 'A', 43 ), 'WPBridge-Link WPBL1-short', 'WPBridge-Link ' . str_repeat( 'A', 49 ) ] as $header ) {
		$req = new WP_REST_Request( [ 'Authorization' => $header, 'Host' => 'hub.example' ], [ 'id' => $link_id, 'slug' => 'demo' ] );
		$assert( [ 'wpbridge_link_credential_missing', 401 ] === ct_error( $hub->$method( $req ) ), "{$method}: Authorization '" . substr( $header, 0, 20 ) . "' gets wpbridge_link_credential_missing 401" );
	}
	$req = new WP_REST_Request( [ 'Authorization' => $valid_shape, 'Host' => 'hub.example' ], [ 'id' => $link_id, 'slug' => 'demo' ] );
	$assert( [ 'wpbridge_link_credential_invalid', 401 ] === ct_error( $hub->$method( $req ) ), "{$method}: unknown well-formed credential gets wpbridge_link_credential_invalid 401" );
}
$scopes = [];
foreach ( [ 'sources_permission', 'source_permission', 'updates_permission', 'packages_permission' ] as $method ) {
	preg_match( '/function ' . $method . '\(.*?authorize\( \$request, \'([a-z:]+)\', (true|false) \)/s', $hub_src, $m );
	$scopes[ $method ] = ( $m[1] ?? '' ) . '/' . ( $m[2] ?? '' );
}
$assert( [ 'sources_permission' => 'sources:read/false', 'source_permission' => 'sources:read/true', 'updates_permission' => 'updates:read/true', 'packages_permission' => 'packages:read/true' ] === $scopes, 'Proxy permissions map to scopes sources:read (no slug), sources:read, updates:read, packages:read (slug required)' );

// ---------------------------------------------------------------------------
// 5. bridge/v1 route table and args validation.
// ---------------------------------------------------------------------------
$GLOBALS['ct_routes'] = [];
$rest = ct_rest_controller( [ 'enabled' => true ] );
$rest->register_routes();
$expected_rest = [
	'GET /sources'                                                       => 'get_sources|check_api_permission',
	'GET /sources/(?P<id>[a-zA-Z0-9_-]+)'                                => 'get_source|check_api_permission',
	'GET /check/(?P<source_id>[a-zA-Z0-9_-]+)'                           => 'check_source|check_api_permission',
	'GET /plugins/(?P<slug>[a-z0-9-]+)/info'                             => 'get_plugin_info|check_api_permission',
	'GET /themes/(?P<slug>[a-z0-9-]+)/info'                              => 'get_theme_info|check_api_permission',
	'GET /wenpai-git/(?P<repo>[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+)/releases' => 'get_wenpai_git_releases|check_api_permission',
	'GET /status'                                                        => 'get_status|__return_true',
];
ksort( $expected_rest );
$actual_rest = ct_route_table( RestController::NAMESPACE );
$assert( 'bridge/v1' === RestController::NAMESPACE, 'Plugin REST namespace is bridge/v1' );
$assert( $expected_rest === $actual_rest, 'bridge/v1 exposes 6 GET data routes behind check_api_permission plus public GET /status' );
if ( $expected_rest !== $actual_rest ) {
	echo '  diff: ' . json_encode( [ 'missing' => array_diff_assoc( $expected_rest, $actual_rest ), 'unexpected' => array_diff_assoc( $actual_rest, $expected_rest ) ], JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}
$args = [];
foreach ( $GLOBALS['ct_routes'] as [ , $route, $route_args ] ) {
	foreach ( (array) ( $route_args['args'] ?? [] ) as $arg => $spec ) {
		$args[ $route ][ $arg ] = [ $spec['required'] ?? false, $spec['sanitize_callback'] ?? null ];
		if ( isset( $spec['validate_callback'] ) ) {
			$args[ $route ]['__validate'] = $spec['validate_callback'];
		}
	}
}
$assert( [ true, 'sanitize_text_field' ] === ( $args['/sources/(?P<id>[a-zA-Z0-9_-]+)']['id'] ?? null ), 'GET /sources/{id}: id is required and sanitize_text_field' );
$assert( [ true, 'sanitize_text_field' ] === ( $args['/check/(?P<source_id>[a-zA-Z0-9_-]+)']['source_id'] ?? null ), 'GET /check/{source_id}: source_id is required and sanitize_text_field' );
$assert( [ true, 'sanitize_title' ] === ( $args['/plugins/(?P<slug>[a-z0-9-]+)/info']['slug'] ?? null ), 'GET /plugins/{slug}/info: slug is required and sanitize_title' );
$assert( [ true, 'sanitize_title' ] === ( $args['/themes/(?P<slug>[a-z0-9-]+)/info']['slug'] ?? null ), 'GET /themes/{slug}/info: slug is required and sanitize_title' );
$repo_route = '/wenpai-git/(?P<repo>[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+)/releases';
$assert( [ true, 'sanitize_text_field' ] === ( $args[ $repo_route ]['repo'] ?? null ), 'GET /wenpai-git/{repo}/releases: repo is required and sanitize_text_field' );
$validate = $args[ $repo_route ]['__validate'] ?? null;
$assert( is_callable( $validate ), 'GET /wenpai-git/{repo}/releases: repo has a validate_callback' );
if ( is_callable( $validate ) ) {
	foreach ( [ 'WenPai-org/wpbridge', 'org_1/repo.name-2' ] as $ok ) {
		$assert( 1 === $validate( $ok ), "repo validate_callback accepts {$ok}" );
	}
	foreach ( [ 'wpbridge', 'a/b/c', 'org/repo?x=1', 'org /repo', '../repo', 'org/repo#x' ] as $bad ) {
		$assert( 1 !== $validate( $bad ), "repo validate_callback rejects {$bad}" );
	}
}
$assert( [] === array_filter( $actual_rest, static fn( string $v ): bool => false !== strpos( $v, 'MISSING' ) ), 'Every bridge/v1 endpoint declares a permission_callback' );

// ---------------------------------------------------------------------------
// 6. bridge/v1 check_api_permission: WP_Error code + HTTP status matrix.
// ---------------------------------------------------------------------------
$key     = 'wpbridge-test-key-0001';
$keys    = [ [ 'id' => 'k1', 'key_hash' => password_hash( $key, PASSWORD_DEFAULT ) ] ];
$perm    = static function ( array $settings, array $headers = [], array $query = [] ) {
	return ct_rest_controller( $settings )->check_api_permission( new WP_REST_Request( $headers, [], null, $query ) );
};
$assert( [ 'api_disabled', 403 ] === ct_error( $perm( [] ) ), 'bridge/v1: API disabled by default gets api_disabled 403' );
$assert( [ 'api_disabled', 403 ] === ct_error( $perm( [ 'enabled' => false, 'require_auth' => false ] ) ), 'bridge/v1: disabled API stays 403 even with require_auth off' );
$assert( [ 'missing_api_key', 401 ] === ct_error( $perm( [ 'enabled' => true, 'keys' => $keys ] ) ), 'bridge/v1: require_auth defaults on; missing key gets missing_api_key 401' );
$assert( [ 'invalid_api_key', 401 ] === ct_error( $perm( [ 'enabled' => true, 'keys' => $keys ], [ 'X-WPBridge-API-Key' => 'wrong' ] ) ), 'bridge/v1: unknown key gets invalid_api_key 401' );
$assert( [ 'missing_api_key', 401 ] === ct_error( $perm( [ 'enabled' => true, 'keys' => $keys ], [ 'Authorization' => 'Basic ' . $key ] ) ), 'bridge/v1: non-Bearer Authorization is not read as an API key' );
$assert( true === $perm( [ 'enabled' => true, 'keys' => $keys ], [ 'X-WPBridge-API-Key' => $key ] ), 'bridge/v1: valid X-WPBridge-API-Key header passes' );
$assert( true === $perm( [ 'enabled' => true, 'keys' => $keys ], [ 'Authorization' => 'Bearer ' . $key ] ), 'bridge/v1: valid Authorization: Bearer passes' );
\WPBridge\Core\Logger::$records = [];
$assert( true === $perm( [ 'enabled' => true, 'keys' => $keys ], [], [ 'api_key' => $key ] ) && 'warning' === ( \WPBridge\Core\Logger::$records[0][0] ?? '' ), 'bridge/v1: ?api_key= still passes but logs a warning (deprecated transport)' );
$assert( [ 'invalid_api_key', 401 ] === ct_error( $perm( [ 'enabled' => true, 'keys' => [ [ 'id' => 'k2', 'key_hash' => password_hash( $key, PASSWORD_DEFAULT ), 'expires_at' => '2001-01-01 00:00:00' ] ] ], [ 'X-WPBridge-API-Key' => $key ] ) ), 'bridge/v1: expired key gets invalid_api_key 401' );
$assert( [ 'invalid_api_key', 401 ] === ct_error( $perm( [ 'enabled' => true, 'keys' => [ [ 'id' => 'k3', 'key' => $key ] ] ], [ 'X-WPBridge-API-Key' => $key ] ) ), 'bridge/v1: plaintext key records without key_hash are never accepted' );
$assert( true === $perm( [ 'enabled' => true, 'require_auth' => false ] ), 'bridge/v1: explicit require_auth=false allows anonymous reads' );
$limited = ct_rest_controller( [ 'enabled' => true, 'keys' => $keys, 'rate_limit' => 2 ] );
$lreq    = new WP_REST_Request( [ 'X-WPBridge-API-Key' => $key ] );
$results = [ $limited->check_api_permission( $lreq ), $limited->check_api_permission( $lreq ), $limited->check_api_permission( $lreq ) ];
$third   = $results[2];
$assert( true === $results[0] && true === $results[1] && [ 'rate_limit_exceeded', 429 ] === ct_error( $third ), 'bridge/v1: request over rate_limit gets rate_limit_exceeded 429' );
$assert( $third instanceof WP_Error && 60 === ( $third->get_error_data()['retry_after'] ?? null ) && 2 === ( $third->get_error_data()['x-ratelimit-limit'] ?? null ), 'bridge/v1: 429 carries retry_after=60 and x-ratelimit-limit' );

// ---------------------------------------------------------------------------
// 7. Health/status response structure.
// ---------------------------------------------------------------------------
$status = $rest->get_status( new WP_REST_Request() );
$sdata  = $status->get_data();
$assert( 200 === $status->get_status() && true === ( $sdata['success'] ?? null ), 'GET /status returns 200 {success:true}' );
$assert( [ 'api_version', 'endpoints', 'timestamp', 'version' ] === ( static function ( array $d ): array { $k = array_keys( $d ); sort( $k ); return $k; } )( (array) ( $sdata['data'] ?? [] ) ), 'GET /status data has exactly version, api_version, endpoints, timestamp' );
$assert( 'v1' === ( $sdata['data']['api_version'] ?? '' ) && WPBRIDGE_VERSION === ( $sdata['data']['version'] ?? '' ), 'GET /status reports api_version v1 and the plugin version' );
$endpoints = (array) ( $sdata['data']['endpoints'] ?? [] );
$assert( [ 'sources', 'check', 'plugins', 'themes', 'wenpai_git' ] === array_keys( $endpoints ), 'GET /status lists endpoints sources, check, plugins, themes, wenpai_git' );
$assert( [] === array_filter( $endpoints, static fn( string $u ): bool => 0 !== strpos( $u, 'https://site.example/wp-json/bridge/v1/' ) ), 'GET /status endpoint URLs all live under bridge/v1' );
$assert( false === strpos( (string) json_encode( $sdata ), 'key' ), 'GET /status (public) leaks no key material or key names' );

$source = (object) [ 'id' => 's1', 'name' => 'S1', 'type' => 'json', 'api_url' => 'https://u.example', 'item_type' => 'plugin', 'enabled' => true, 'priority' => 1, 'slug' => '' ];
$withsrc = ct_rest_controller( [ 'enabled' => true ], [ 's1' => $source ] );
$assert( [ 'source_not_found', 404 ] === ct_error( $withsrc->check_source( new WP_REST_Request( [], [ 'source_id' => 'nope' ] ) ) ), 'GET /check/{id}: unknown source gets source_not_found 404' );
$assert( [ 'source_not_found', 404 ] === ct_error( $withsrc->get_source( new WP_REST_Request( [], [ 'id' => 'nope' ] ) ) ), 'GET /sources/{id}: unknown source gets source_not_found 404' );
$check = $withsrc->check_source( new WP_REST_Request( [], [ 'source_id' => 's1' ] ) );
$cdata = $check instanceof WP_REST_Response ? $check->get_data() : [];
$assert( true === ( $cdata['success'] ?? null ) && [ 'source_id', 'status', 'response_time', 'error', 'checked_at' ] === array_keys( (array) ( $cdata['data'] ?? [] ) ), 'GET /check/{id}: health response is {success, data:{source_id,status,response_time,error,checked_at}}' );
$assert( 's1' === ( $cdata['data']['source_id'] ?? '' ) && 'healthy' === ( $cdata['data']['status'] ?? '' ) && 12 === ( $cdata['data']['response_time'] ?? null ), 'GET /check/{id}: health fields are copied from HealthChecker' );

// ---------------------------------------------------------------------------
// 8. Error-code -> HTTP status snapshot for server-side REST code.
//    A code changing status, or a new code without review, turns this red.
// ---------------------------------------------------------------------------
$server_files = [ 'includes/API/RestController.php', 'includes/HubSpoke/HubSpokeController.php', 'includes/HubSpoke/HubSpokeStore.php', 'includes/HubSpoke/LinkAuthorizer.php', 'includes/HubSpoke/StepUpVerifier.php', 'includes/HubSpoke/HostCanonicalizer.php' ];
$snapshot = [];
foreach ( $server_files as $file ) {
	preg_match_all( "/new \\\\?WP_Error\\(\\s*'([a-z0-9_]+)'([^;]*?)\\);/s", (string) file_get_contents( $root . '/' . $file ), $found, PREG_SET_ORDER );
	foreach ( $found as $hit ) {
		$st = preg_match( "/'status'\\s*=>\\s*(\\d+)/", $hit[2], $s ) ? (int) $s[1] : 0;
		$snapshot[ $hit[1] ][ $st ] = $st;
	}
}
ksort( $snapshot );
foreach ( $snapshot as $code => $list ) {
	sort( $list );
	$snapshot[ $code ] = array_values( $list );
}
$expected_snapshot = [
	'api_disabled'                         => [ 403 ],
	'api_error'                            => [ 0, 502 ], // 0 = upstream status passed through.
	'invalid_api_key'                      => [ 401 ],
	'json_error'                           => [ 500 ],
	'missing_api_key'                      => [ 401 ],
	'plugin_not_found'                     => [ 404 ],
	'rate_limit_exceeded'                  => [ 429 ],
	'source_not_found'                     => [ 404 ],
	'theme_not_found'                      => [ 404 ],
	'wpbridge_acceptance_invalid'          => [ 400 ],
	'wpbridge_acceptance_proof_invalid'    => [ 401 ],
	'wpbridge_acceptance_replay'           => [ 409 ],
	'wpbridge_admin_forbidden'             => [ 403 ],
	'wpbridge_cleanup_proof_scope_invalid' => [ 403 ],
	'wpbridge_hub_identity_unavailable'    => [ 503 ],
	'wpbridge_hub_origin_invalid'          => [ 400, 503 ], // 400 request Host, 503 local site not https:443.
	'wpbridge_hub_origin_mismatch'         => [ 401, 409 ], // 401 request Host, 409 frozen origin conflict.
	'wpbridge_hub_role_conflict'           => [ 409 ],
	'wpbridge_hub_spoke_disabled'          => [ 503 ],
	'wpbridge_hub_store_busy'              => [ 503 ],
	'wpbridge_hub_store_failed'            => [ 503 ],
	'wpbridge_hub_store_lock_unavailable'  => [ 503 ],
	'wpbridge_invitation_invalid'          => [ 401 ],
	'wpbridge_invitation_not_found'        => [ 404 ],
	'wpbridge_link_compensation_failed'    => [ 503 ],
	'wpbridge_link_credential_invalid'     => [ 401 ],
	'wpbridge_link_credential_missing'     => [ 401 ],
	'wpbridge_link_not_found'              => [ 404 ],
	'wpbridge_link_policy_invalid'         => [ 400 ],
	'wpbridge_link_rate_limited'           => [ 429 ],
	'wpbridge_link_resource_not_found'     => [ 404 ],
	'wpbridge_link_scope_forbidden'        => [ 403 ],
	'wpbridge_nonce_invalid'               => [ 403 ],
	'wpbridge_origin_invalid'              => [ 403 ],
	'wpbridge_query_credential_forbidden'  => [ 400 ],
	'wpbridge_reconcile_claim_failed'      => [ 0 ], // Internal sentinel, mapped before returning.
	'wpbridge_reconcile_key_unavailable'   => [ 0 ], // Internal sentinel inside the reconcile sweep.
	'wpbridge_request_invalid'             => [ 400 ],
	'wpbridge_spoke_cannot_be_hub'         => [ 409 ],
	'wpbridge_spoke_link_not_found'        => [ 404 ],
	'wpbridge_spoke_reconcile_storage_failed' => [ 503 ],
	'wpbridge_step_up_failed'              => [ 401 ],
	'wpbridge_step_up_invalid'             => [ 400 ],
	'wpbridge_step_up_required'            => [ 401 ],
	'wpbridge_uncertain_accept_not_found'  => [ 404 ],
];
ksort( $expected_snapshot );
$assert( $expected_snapshot === $snapshot, 'Server-side WP_Error code -> HTTP status snapshot is unchanged (' . count( $expected_snapshot ) . ' codes)' );
if ( $expected_snapshot !== $snapshot ) {
	foreach ( array_unique( array_merge( array_keys( $expected_snapshot ), array_keys( $snapshot ) ) ) as $code ) {
		if ( ( $expected_snapshot[ $code ] ?? null ) !== ( $snapshot[ $code ] ?? null ) ) {
			echo "  {$code}: expected " . json_encode( $expected_snapshot[ $code ] ?? null ) . ' got ' . json_encode( $snapshot[ $code ] ?? null ) . PHP_EOL;
		}
	}
}
$auth_codes = [ 'missing_api_key' => 401, 'invalid_api_key' => 401, 'wpbridge_link_credential_missing' => 401, 'wpbridge_link_credential_invalid' => 401, 'wpbridge_step_up_required' => 401, 'wpbridge_admin_forbidden' => 403, 'wpbridge_nonce_invalid' => 403 ];
$assert( [] === array_filter( $auth_codes, static fn( int $st, string $code ): bool => [ $st ] !== ( $snapshot[ $code ] ?? null ), ARRAY_FILTER_USE_BOTH ), 'Authentication codes are 401 (who are you) and authorization codes are 403 (not allowed)' );

if ( $failures > 0 ) {
	echo "{$failures} REST contract assertion(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'REST contract tests passed.' . PHP_EOL;
