<?php
/**
 * CT-13 with the Hub-Spoke feature flag off: self-authenticating routes and
 * link-credential routes answer 503, authority-reducing cleanup stays reachable.
 */

declare(strict_types=1);

define( 'WPBRIDGE_HUB_SPOKE_ENABLED', false );
require __DIR__ . '/rest-contract-bootstrap.php';

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
};

$hub      = ct_hub_controller();
$link_id  = '00000000-0000-4000-8000-00000000abcd';
$disabled = [ 'wpbridge_hub_spoke_disabled', 503 ];
$GLOBALS['ct_options']['wpbridge_hub_network_origin_v1'] = [ 'origin' => 'https://hub.example', 'created_at' => 1 ];

foreach ( [ 'challenge' => [ 'invitation_token' => 'x' ], 'acceptance' => [ 'invitation_token' => 'x' ] ] as $method => $body ) {
	$req = new WP_REST_Request( [ 'Host' => 'bad,host' ], [ 'id' => $link_id ], $body );
	$assert( $disabled === ct_error( $hub->$method( $req ) ), "CT-13 flag off: {$method} returns wpbridge_hub_spoke_disabled 503 before any Host/body check" );
}
foreach ( [ 'sources_permission', 'source_permission', 'updates_permission', 'packages_permission' ] as $method ) {
	$req = new WP_REST_Request( [ 'Authorization' => 'WPBridge-Link WPBL1-' . str_repeat( 'A', 43 ), 'Host' => 'hub.example' ], [ 'slug' => 'demo' ], null, [ 'api_key' => 'x' ] );
	$assert( $disabled === ct_error( $hub->$method( $req ) ), "flag off: {$method} returns 503 before credential parsing" );
}

$password = 'correct horse battery staple';
$GLOBALS['ct_user'] = [ 'logged_in' => true, 'caps' => [ 'manage_options' ], 'super' => false, 'id' => 7, 'pass' => password_hash( $password, PASSWORD_DEFAULT ) ];
$good   = [ 'X-WP-Nonce' => 'valid-nonce', 'Origin' => 'https://site.example' ];
$issued = $hub->issue_step_up( new WP_REST_Request( $good, [], [ 'password' => $password ] ) );
$proof  = $issued instanceof WP_REST_Response ? (string) ( $issued->get_data()['step_up_proof'] ?? '' ) : '';
$record = $GLOBALS['ct_transients'][ 'wpbridge_step_up_' . hash( 'sha256', $proof ) ] ?? [];
$assert( '' !== $proof && true === ( $record['cleanup_only'] ?? null ), 'CT-13 flag off: /step-up still issues a proof, marked cleanup_only' );
$with = new WP_REST_Request( array_merge( $good, [ 'X-WPBridge-Step-Up' => $proof ] ) );
$assert( $disabled === ct_error( $hub->admin_permission( $with ) ), 'flag off: admin_permission returns 503 even with a valid proof' );
$assert( true === $hub->cleanup_permission( $with ), 'flag off: cleanup_permission accepts the cleanup-only proof' );
$assert( [ 'wpbridge_cleanup_proof_scope_invalid', 403 ] === ct_error( ( new \WPBridge\HubSpoke\StepUpVerifier() )->verify( $with ) ), 'flag off: a cleanup-only proof is rejected for non-cleanup verify with 403' );
$GLOBALS['ct_user'] = [ 'logged_in' => false, 'caps' => [], 'super' => false, 'id' => 0, 'pass' => '' ];
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $hub->cleanup_permission( $with ) ), 'flag off: cleanup_permission still requires an administrator (403, not 503)' );
$assert( [ 'wpbridge_admin_forbidden', 403 ] === ct_error( $hub->issue_step_up( new WP_REST_Request( $good, [], [ 'password' => $password ] ) ) ), 'CT-13 flag off: /step-up still requires an administrator' );
$req = new WP_REST_Request( [ 'Host' => 'hub.example' ], [ 'id' => $link_id ], null, [ 'link_credential' => 'x' ] );
$assert( [ 'wpbridge_query_credential_forbidden', 400 ] === ct_error( $hub->acceptance_compensation_permission( $req ) ), 'flag off: acceptance-compensation (authority-reducing) still validates credentials instead of 503' );

if ( $failures > 0 ) {
	echo "{$failures} flag-off REST contract assertion(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'Flag-off REST contract tests passed.' . PHP_EOL;
