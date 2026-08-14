<?php
/**
 * Stage 3A Hub-Spoke contract and security regression tests.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'wpbridge-stage3a-test-key' );
}
define( 'WPBRIDGE_HUB_LINK_ACTIVE_KEY_VERSION', 'test-v1' );
define( 'WPBRIDGE_HUB_LINK_MASTER_KEYS', [ 'test-v1' => rtrim( strtr( base64_encode( str_repeat( 'K', 32 ) ), '+/', '-_' ), '=' ) ] );
define( 'WPBRIDGE_HUB_ORIGIN_ALLOWLIST', [ 'https://hub.example' ] );
define( 'WPBRIDGE_HUB_SPOKE_ENABLED', true );

$GLOBALS['wpbridge_test_options'] = [];
$GLOBALS['wpbridge_test_uuid']    = 0;
$GLOBALS['wpbridge_fail_update']  = [];
$GLOBALS['wpbridge_test_multisite'] = false;
$GLOBALS['wpbridge_test_site_options'] = [];
$GLOBALS['wpbridge_lock_sql'] = [];
$GLOBALS['wpdb'] = new class() {
	public function prepare( string $sql, ...$args ): string {
		return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $sql ), $args );
	}
	public function get_var( string $sql ): string {
		$GLOBALS['wpbridge_lock_sql'][] = $sql;
		return '1';
	}
};

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}
function is_multisite(): bool {
	return $GLOBALS['wpbridge_test_multisite'];
}
function get_site_option( string $key, $default = false ) { return $GLOBALS['wpbridge_test_site_options'][ $key ] ?? $default; }
function update_site_option( string $key, $value ): bool {
	if ( ! empty( $GLOBALS['wpbridge_fail_update'][ $key ] ) ) { --$GLOBALS['wpbridge_fail_update'][ $key ]; return false; }
	$changed = ! array_key_exists( $key, $GLOBALS['wpbridge_test_site_options'] ) || $GLOBALS['wpbridge_test_site_options'][ $key ] !== $value;
	$GLOBALS['wpbridge_test_site_options'][ $key ] = $value;
	return $changed;
}
function get_option( string $key, $default = false ) {
	return $GLOBALS['wpbridge_test_options'][ $key ] ?? $default;
}
function update_option( string $key, $value, bool $autoload = true ): bool {
	unset( $autoload );
	if ( ! empty( $GLOBALS['wpbridge_fail_update'][ $key] ) ) {
		--$GLOBALS['wpbridge_fail_update'][ $key];
		return false;
	}
	$changed = ! array_key_exists( $key, $GLOBALS['wpbridge_test_options'] ) || $GLOBALS['wpbridge_test_options'][ $key ] !== $value;
	$GLOBALS['wpbridge_test_options'][ $key ] = $value;
	return $changed;
}
function wp_generate_uuid4(): string {
	++$GLOBALS['wpbridge_test_uuid'];
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['wpbridge_test_uuid'] );
}
function get_current_user_id(): int {
	return 7;
}
function is_admin(): bool {
	return false;
}
function add_action(): bool {
	return true;
}
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}
function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}
function wp_parse_url( string $url ) { return parse_url( $url ); }

class WP_Error {
	private string $code;
	private string $message;
	private array $data;
	public function __construct( string $code = '', string $message = '', array $data = [] ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code(): string {
		return $this->code;
	}
}
class WP_REST_Request {
	private array $headers;
	private array $params;
	public function __construct( array $headers = [], array $params = [] ) { $this->headers = $headers; $this->params = $params; }
	public function get_query_params(): array { return []; }
	public function get_header( string $name ): string { return (string) ( $this->headers[ $name ] ?? '' ); }
	public function get_param( string $name ) { return $this->params[ $name ] ?? null; }
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

require_once dirname( __DIR__ ) . '/includes/Security/Encryption.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/InstallationIdentity.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/CredentialEnvelope.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeStore.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/SpokeProxyClient.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/SpokeClient.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/LinkAuthorizer.php';

use WPBridge\HubSpoke\HubSpokeStore;
use WPBridge\HubSpoke\InstallationIdentity;
use WPBridge\HubSpoke\CredentialEnvelope;
use WPBridge\HubSpoke\SpokeProxyClient;
use WPBridge\HubSpoke\SpokeClient;
use WPBridge\HubSpoke\LinkAuthorizer;
use WPBridge\Security\Encryption;

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
	if ( ! $condition ) {
		++$failures;
	}
};

$assert( InstallationIdentity::ensure(), 'Installation identity and Ed25519 key are created' );
$uuid = InstallationIdentity::uuid();
$assert( '' !== $uuid && $uuid === InstallationIdentity::uuid(), 'Installation UUID is immutable across reads' );
$private = (string) get_option( 'wpbridge_link_private_key', '' );
$assert( Encryption::is_encrypted( $private ) && false === strpos( $private, InstallationIdentity::public_key() ), 'Private key is stored only as AEAD ciphertext' );

$store      = new HubSpokeStore();
$now        = 1776124800;
$invitation = $store->create_invitation( [ 'updates:read', 'sources:read' ], [ 'wpbridge', 'wenpai-client' ], $now, 'https://hub.example' );
$assert( is_array( $invitation ) && preg_match( '/^WPBI1-[A-Za-z0-9_-]{43}$/', $invitation['invitation_token'] ) === 1, 'Invitation returns a one-time opaque token' );
$raw_store = serialize( get_option( 'wpbridge_hub_invitations_v1', [] ) );
$assert( false === strpos( $raw_store, $invitation['invitation_token'] ) && false !== strpos( $raw_store, hash( 'sha256', $invitation['invitation_token'] ) ), 'Hub persists only the invitation token hash' );
$lock_names = array_values( array_filter( $GLOBALS['wpbridge_lock_sql'], static function ( string $sql ): bool { return false !== strpos( $sql, 'GET_LOCK' ); } ) );
$assert( 1 === count( array_unique( $lock_names ) ), 'Hub invitation lifecycle starts under the unified installation lifecycle lock' );

$spoke_keypair = sodium_crypto_sign_keypair();
$spoke_public  = sodium_crypto_sign_publickey( $spoke_keypair );
$spoke_secret  = sodium_crypto_sign_secretkey( $spoke_keypair );
$spoke_uuid    = '10000000-0000-4000-8000-000000000001';
$nonce         = InstallationIdentity::base64url( random_bytes( 32 ) );
$timestamp     = (string) $now;
$canonical     = "WPBRIDGE-HUB-LINK-ACCEPT-V1\n"
	. 'invitation_id:' . $invitation['invitation_id'] . "\n"
	. 'invitation_token_sha256:' . hash( 'sha256', $invitation['invitation_token'] ) . "\n"
	. 'hub_installation_uuid:' . $uuid . "\n"
	. 'spoke_installation_uuid:' . $spoke_uuid . "\n"
	. 'spoke_public_key_sha256:' . hash( 'sha256', $spoke_public ) . "\n"
	. 'nonce:' . $nonce . "\n"
	. 'timestamp:' . $timestamp . "\n";
$accept_body = [
	'invitation_token'       => $invitation['invitation_token'],
	'spoke_installation_uuid' => $spoke_uuid,
	'spoke_public_key'       => InstallationIdentity::base64url( $spoke_public ),
	'nonce'                  => $nonce,
	'timestamp'              => $timestamp,
	'signature'              => InstallationIdentity::base64url( sodium_crypto_sign_detached( $canonical, $spoke_secret ) ),
];
$accepted = $store->accept( $invitation['invitation_id'], $accept_body, $now );
$assert( is_array( $accepted ) && preg_match( '/^WPBL1-[A-Za-z0-9_-]{43}$/', $accepted['link_credential'] ) === 1, 'Token and Ed25519 possession create an active link' );
$link_store = serialize( get_option( 'wpbridge_hub_links_v1', [] ) );
$assert( false === strpos( $link_store, $accepted['link_credential'] ) && false !== strpos( $link_store, hash( 'sha256', $accepted['link_credential'] ) ), 'Hub persists only the link credential hash' );
$assert( 'https://hub.example' === ( get_option( 'wpbridge_hub_links_v1', [] )[ $accepted['link_id'] ]['hub_origin'] ?? '' ) && 'https://hub.example' === $store->network_origin(), 'Hub link and network state bind the exact frozen Hub origin' );
$origin_mismatch = $store->create_invitation( [ 'updates:read' ], [ 'wpbridge' ], $now, 'https://other.example' );
$assert( is_wp_error( $origin_mismatch ) && 'wpbridge_hub_origin_mismatch' === $origin_mismatch->get_error_code(), 'A second multisite blog cannot replace the frozen network Hub origin' );
$assert( null !== $store->authorize( $accepted['link_credential'], $now ), 'Current link credential authorizes an active link' );
$origin_authorizer = new LinkAuthorizer( $store );
$origin_ok = $origin_authorizer->authorize( new WP_REST_Request( [ 'Authorization' => 'WPBridge-Link ' . $accepted['link_credential'], 'Host' => 'hub.example' ], [ 'slug' => 'wpbridge' ] ), 'updates:read', true );
$origin_bad = ( new LinkAuthorizer( $store ) )->authorize( new WP_REST_Request( [ 'Authorization' => 'WPBridge-Link ' . $accepted['link_credential'], 'Host' => 'subsite.example' ], [ 'slug' => 'wpbridge' ] ), 'updates:read', true );
$assert( true === $origin_ok && is_wp_error( $origin_bad ) && 'wpbridge_hub_origin_mismatch' === $origin_bad->get_error_code(), 'Proxy authorization accepts only the frozen network Host and rejects cross-subsite credential replay' );
$minute = (int) floor( $now / 60 );
$assert( $store->consume_rate( $accepted['link_id'], $minute, 2 ) && $store->consume_rate( $accepted['link_id'], $minute, 2 ) && ! $store->consume_rate( $accepted['link_id'], $minute, 2 ), 'Persistent lock-serialized rate bucket enforces its exact limit' );
$other_link = '20000000-0000-4000-8000-000000000002';
$assert( $store->consume_rate( $other_link, $minute, 2 ) && $store->consume_rate( $other_link, $minute, 2 ) && ! $store->consume_rate( $other_link, $minute, 2 ) && ! $store->consume_rate( $accepted['link_id'], $minute, 2 ), 'Shared persistent rate lock preserves independent buckets for multiple links' );

$replay = $store->accept( $invitation['invitation_id'], $accept_body, $now );
$assert( is_wp_error( $replay ), 'Invitation and acceptance proof cannot be replayed' );

$fault_invitation = $store->create_invitation( [ 'updates:read' ], [ 'wpbridge' ], $now, 'https://hub.example' );
$fault_nonce = InstallationIdentity::base64url( random_bytes( 32 ) );
$fault_canonical = "WPBRIDGE-HUB-LINK-ACCEPT-V1\n"
	. 'invitation_id:' . $fault_invitation['invitation_id'] . "\n"
	. 'invitation_token_sha256:' . hash( 'sha256', $fault_invitation['invitation_token'] ) . "\n"
	. 'hub_installation_uuid:' . $uuid . "\n"
	. 'spoke_installation_uuid:' . $spoke_uuid . "\n"
	. 'spoke_public_key_sha256:' . hash( 'sha256', $spoke_public ) . "\n"
	. 'nonce:' . $fault_nonce . "\n"
	. 'timestamp:' . $timestamp . "\n";
$fault_accept_body = [
	'invitation_token' => $fault_invitation['invitation_token'],
	'spoke_installation_uuid' => $spoke_uuid,
	'spoke_public_key' => InstallationIdentity::base64url( $spoke_public ),
	'nonce' => $fault_nonce,
	'timestamp' => $timestamp,
	'signature' => InstallationIdentity::base64url( sodium_crypto_sign_detached( $fault_canonical, $spoke_secret ) ),
];
$before_fault_hub_links = get_option( 'wpbridge_hub_links_v1', [] );
$before_fault_invitations = get_option( 'wpbridge_hub_invitations_v1', [] );
$GLOBALS['wpbridge_fail_update']['wpbridge_hub_invitations_v1'] = 1;
$fault_accept = $store->accept( $fault_invitation['invitation_id'], $fault_accept_body, $now );
$assert( is_wp_error( $fault_accept ) && 'wpbridge_hub_store_failed' === $fault_accept->get_error_code(), 'Hub accept never returns a credential after invitation persistence failure' );
$assert( $before_fault_hub_links === get_option( 'wpbridge_hub_links_v1', [] ) && $before_fault_invitations === get_option( 'wpbridge_hub_invitations_v1', [] ), 'Hub accept failure reliably restores link and invitation options' );

$rotated = $store->rotate( $accepted['link_id'], $now + 1 );
$assert( is_array( $rotated ) && null !== $store->authorize( $accepted['link_credential'], $now + 299 ), 'Rotation keeps the prior credential for at most five minutes' );
$assert( null === $store->authorize( $accepted['link_credential'], $now + 302 ), 'Rotated credential is rejected after the overlap window' );
$assert( $store->revoke( $accepted['link_id'], $now + 303 ) && null === $store->authorize( $rotated['link_credential'], $now + 303 ), 'Revocation immediately rejects the active credential' );
$lifecycle_lock = substr( hash( 'sha256', 'hub-lifecycle:' . $uuid ), 0, 48 );
$lifecycle_acquires = array_values( array_filter( $GLOBALS['wpbridge_lock_sql'], static function ( string $sql ) use ( $lifecycle_lock ): bool { return false !== strpos( $sql, 'GET_LOCK' ) && false !== strpos( $sql, $lifecycle_lock ); } ) );
$assert( count( $lifecycle_acquires ) >= 7 && 1 === count( array_unique( $lifecycle_acquires ) ), 'Invitation accept, rotation and revoke share one Hub lifecycle lock without cross-workflow option races' );

$spoke_saved = $store->save_spoke_link( 'https://hub.example', $rotated, $now + 2 );
$spoke_rows  = get_option( 'wpbridge_spoke_links_v1', [] );
$spoke_row   = $spoke_rows[ $accepted['link_id'] ] ?? [];
$assert( $spoke_saved && 'test-v1' === CredentialEnvelope::version( (string) ( $spoke_row['credential_ciphertext'] ?? '' ) ), 'Spoke persists the reusable credential in the mandatory domain envelope' );
$assert( false === strpos( serialize( $spoke_rows ), $rotated['link_credential'] ), 'Spoke option does not contain the plaintext credential' );
$assert( $store->has_active_spoke_link(), 'Installation role recognizes the active Spoke link' );
$provisioned = array_values( array_filter( (array) get_option( 'wpbridge_source_registry', [] ), static function ( $source ): bool { return is_array( $source ) && 'hub_spoke' === ( $source['type'] ?? '' ); } ) );
$assert( 2 === count( $provisioned ) && '' === $provisioned[0]['auth_secret_ref'], 'Spoke acceptance provisions credential-free runtime sources for each allowed slug' );

$runtime_requests = [];
$transport = static function ( string $url, array $args ) use ( &$runtime_requests ): array {
	$runtime_requests[] = [ 'url' => $url, 'args' => $args ];
	return [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( [ 'slug' => 'wpbridge', 'version' => '2.0.0', 'sha256' => str_repeat( 'a', 64 ) ] ),
	];
};
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value );
	}
}
$runtime = SpokeProxyClient::from_link( $accepted['link_id'], $transport );
$metadata = is_wp_error( $runtime ) ? $runtime : $runtime->metadata( 'wpbridge' );
$request = $runtime_requests[0] ?? [];
$assert( is_array( $metadata ) && '2.0.0' === $metadata['version'], 'Active Spoke runtime fetches protected metadata' );
$assert( isset( $request['args']['headers']['Authorization'] ) && 0 === strpos( $request['args']['headers']['Authorization'], 'WPBridge-Link ' ), 'Runtime sends credential only in Authorization header' );
$assert( false === strpos( (string) ( $request['url'] ?? '' ), 'WPBL1-' ) && 'https://hub.example/wp-json/wpbridge/v2/hub-proxy/plugins/wpbridge/metadata' === ( $request['url'] ?? '' ), 'Runtime uses only the exact stored Hub origin and frozen metadata route' );
$replacement = 'WPBL1-' . InstallationIdentity::base64url( random_bytes( 32 ) );
$assert( $store->apply_spoke_rotation( $accepted['link_id'], $replacement, $now + 400 ) && hash_equals( $replacement, (string) $store->active_spoke_link( $accepted['link_id'] )['credential'] ), 'Spoke applies a one-time rotated credential under the active envelope key' );
$assert( $store->unlink_spoke( $accepted['link_id'], $now + 401 ) && null === $store->active_spoke_link( $accepted['link_id'] ), 'Spoke unlink wipes reusable credential access immediately' );
$disabled_sources = array_filter( (array) get_option( 'wpbridge_source_registry', [] ), static function ( $source ): bool { return is_array( $source ) && 'hub_spoke' === ( $source['type'] ?? '' ) && empty( $source['enabled'] ); } );
$assert( 2 === count( $disabled_sources ), 'Spoke unlink disables every provisioned Hub runtime source' );

$before_fault_sources = get_option( 'wpbridge_source_registry', [] );
$before_fault_defaults = get_option( 'wpbridge_defaults', [] );
$before_fault_links = get_option( 'wpbridge_spoke_links_v1', [] );
$fault_response = $rotated;
$fault_response['link_id'] = '30000000-0000-4000-8000-000000000003';
$fault_response['hub_public_key_fingerprint'] = str_repeat( 'a', 64 );
$GLOBALS['wpbridge_fail_update']['wpbridge_defaults'] = 1;
$assert( ! $store->save_spoke_link( 'https://hub.example', $fault_response, $now + 500 ), 'Spoke provisioning reports failure when a dependent option write fails' );
$assert( $before_fault_sources === get_option( 'wpbridge_source_registry', [] ) && $before_fault_defaults === get_option( 'wpbridge_defaults', [] ) && $before_fault_links === get_option( 'wpbridge_spoke_links_v1', [] ), 'Spoke provisioning failure restores links, source registry and defaults exactly' );
$unlink_response = $rotated;
$unlink_response['link_id'] = '40000000-0000-4000-8000-000000000004';
$unlink_response['hub_public_key_fingerprint'] = str_repeat( 'b', 64 );
$assert( $store->save_spoke_link( 'https://hub.example', $unlink_response, $now + 510 ), 'Fault fixture creates a second active Spoke link' );
$before_unlink_links = get_option( 'wpbridge_spoke_links_v1', [] );
$before_unlink_sources = get_option( 'wpbridge_source_registry', [] );
$GLOBALS['wpbridge_fail_update']['wpbridge_source_registry'] = 1;
$assert( ! $store->unlink_spoke( $unlink_response['link_id'], $now + 511 ), 'Spoke unlink reports dependent source registry failure' );
$assert( $before_unlink_links === get_option( 'wpbridge_spoke_links_v1', [] ) && $before_unlink_sources === get_option( 'wpbridge_source_registry', [] ), 'Spoke unlink failure restores credential state and source registry exactly' );
$assert( $store->save_reconcile( 'https://hub.example', $unlink_response['link_id'], $unlink_response['link_credential'], $now + 512, 'unlink_local' ), 'Remote-revoke reconciliation fixture persists an unlink-local action' );
$reconcile_transport_calls = 0;
$reconcile_transport = static function () use ( &$reconcile_transport_calls ): array { ++$reconcile_transport_calls; return [ 'response' => [ 'code' => 204 ] ]; };
$GLOBALS['wpbridge_fail_update']['wpbridge_source_registry'] = 1;
$remote_resolved_local_failed = $store->process_reconciles( $reconcile_transport, $now + 512 );
$pending_cleanup = $store->reconcile_statuses()[0] ?? [];
$assert( 1 === $remote_resolved_local_failed['failed'] && 'local_cleanup' === ( $pending_cleanup['action'] ?? '' ) && 1 === $reconcile_transport_calls, 'Confirmed remote revoke transitions a failed local unlink to durable local-cleanup state' );
$local_resolved = $store->process_reconciles( $reconcile_transport, $now + 633 );
$assert( 1 === $local_resolved['resolved'] && [] === $store->reconcile_statuses() && 1 === $reconcile_transport_calls, 'Second local-cleanup run resolves without calling the revoked Hub credential again' );

$saved_options = $GLOBALS['wpbridge_test_options'];
$GLOBALS['wpbridge_test_options'] = [];
$GLOBALS['wpbridge_fail_update']['wpbridge_link_private_key'] = 1;
$upgraded_failure = ( new HubSpokeStore() )->create_invitation( [ 'updates:read' ], [ 'wpbridge' ], $now, 'https://hub.example' );
$assert( is_wp_error( $upgraded_failure ) && 'wpbridge_hub_identity_unavailable' === $upgraded_failure->get_error_code(), 'Existing upgraded install fails 503-style before invitation issuance when identity persistence fails' );
$GLOBALS['wpbridge_fail_update'] = [];
$GLOBALS['wpbridge_test_options'] = $saved_options;

$GLOBALS['wpbridge_test_multisite'] = true;
$GLOBALS['wpbridge_test_site_options'] = [];
$assert( InstallationIdentity::ensure() && '' !== (string) get_site_option( 'wpbridge_link_private_key', '' ) && '' !== (string) get_site_option( 'wpbridge_link_public_key', '' ), 'Multisite UUID and Ed25519 custody use the same network option scope' );
$single_site_invitations = get_option( 'wpbridge_hub_invitations_v1', [] );
$network_invitation = ( new HubSpokeStore() )->create_invitation( [ 'updates:read' ], [ 'wpbridge' ], $now, 'https://hub.example' );
$assert( is_array( $network_invitation ) && [] !== get_site_option( 'wpbridge_hub_invitations_v1', [] ) && $single_site_invitations === get_option( 'wpbridge_hub_invitations_v1', [] ), 'Multisite link lifecycle state is stored in the same network scope as installation identity' );
$GLOBALS['wpbridge_test_multisite'] = false;
$GLOBALS['wpbridge_test_options'] = [];
$assert( InstallationIdentity::ensure(), 'Single-site identity restored for Spoke compensation fixture' );
$local_hub_invitation = ( new HubSpokeStore() )->create_invitation( [ 'updates:read' ], [ 'wpbridge' ], $now, 'https://hub.example' );
$blocked_requests = 0;
$blocked_client = new SpokeClient( static function () use ( &$blocked_requests ): array { ++$blocked_requests; return []; }, static function () use ( $now ): int { return $now; } );
$blocked_accept = $blocked_client->accept( 'https://hub.example', '50000000-0000-4000-8000-000000000005', 'WPBI1-' . InstallationIdentity::base64url( str_repeat( 'I', 32 ) ) );
$assert( is_wp_error( $blocked_accept ) && 'wpbridge_hub_cannot_be_spoke' === $blocked_accept->get_error_code() && 0 === $blocked_requests, 'Spoke acceptance rejects pending Hub invitations before any remote request' );
$GLOBALS['wpbridge_test_options']['wpbridge_hub_invitations_v1'] = [];
$compensation_requests = [];
$remote_link_id = '60000000-0000-4000-8000-000000000006';
$remote_credential = 'WPBL1-' . InstallationIdentity::base64url( str_repeat( 'L', 32 ) );
$transport = static function ( string $url, array $args ) use ( &$compensation_requests, $remote_link_id, $remote_credential ): array {
	$compensation_requests[] = [ 'url' => $url, 'args' => $args ];
	if ( false !== strpos( $url, '/challenge' ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'invitation_id' => '50000000-0000-4000-8000-000000000005', 'hub_installation_uuid' => '70000000-0000-4000-8000-000000000007', 'hub_public_key_fingerprint' => str_repeat( 'a', 64 ), 'scopes' => [ 'updates:read' ], 'slug_allowlist' => [ 'wpbridge' ], 'expires_at' => '2026-08-14T00:10:00Z' ] ) ];
	}
	if ( false !== strpos( $url, '/acceptances' ) ) {
		return [ 'response' => [ 'code' => 201 ], 'body' => wp_json_encode( [ 'link_id' => $remote_link_id, 'link_credential' => $remote_credential, 'scopes' => [ 'updates:read' ], 'slug_allowlist' => [ 'wpbridge' ], 'expires_at' => null ] ) ];
	}
	return [ 'response' => [ 'code' => 204 ], 'body' => '' ];
};
$GLOBALS['wpbridge_fail_update']['wpbridge_spoke_links_v1'] = 1;
$compensated = ( new SpokeClient( $transport, static function () use ( $now ): int { return $now; } ) )->accept( 'https://hub.example', '50000000-0000-4000-8000-000000000005', 'WPBI1-' . InstallationIdentity::base64url( str_repeat( 'I', 32 ) ) );
$compensation = $compensation_requests[2] ?? [];
$assert( is_wp_error( $compensated ) && 'wpbridge_spoke_storage_failed' === $compensated->get_error_code(), 'Spoke local persistence failure reports failure only after authenticated Hub compensation succeeds' );
$assert( false !== strpos( (string) ( $compensation['url'] ?? '' ), '/acceptance-compensations' ) && ( $compensation['args']['headers']['Authorization'] ?? '' ) === 'WPBridge-Link ' . $remote_credential && false === strpos( (string) ( $compensation['url'] ?? '' ), $remote_credential ), 'Spoke compensation revokes the exact Hub link with a header-only credential' );
$assert( ( new HubSpokeStore() )->save_reconcile( 'https://hub.example', $remote_link_id, $remote_credential, $now ) && false === strpos( serialize( get_option( 'wpbridge_spoke_reconcile_v1', [] ) ), $remote_credential ), 'Failed remote compensation can persist an encrypted durable revoke reconciliation record' );
$reconcile_store = new HubSpokeStore();
$failed_reconcile = $reconcile_store->process_reconciles( static function (): array { return [ 'response' => [ 'code' => 503 ] ]; }, $now );
$assert( 1 === $failed_reconcile['failed'] && 1 === count( $reconcile_store->reconcile_statuses() ) && 'remote_revoke_failed' === $reconcile_store->reconcile_statuses()[0]['error'], 'Reconcile processor retains failed remote revoke with retry status' );
$resolved_reconcile = $reconcile_store->process_reconciles( static function (): array { return [ 'response' => [ 'code' => 204 ] ]; }, $now + 121 );
$assert( 1 === $resolved_reconcile['resolved'] && [] === $reconcile_store->reconcile_statuses(), 'Reconcile processor removes state only after authenticated remote revoke confirmation' );
$expired_store = new HubSpokeStore();
$expired_invitation = $expired_store->create_invitation( [ 'updates:read' ], [ 'wpbridge' ], $now, 'https://hub.example' );
$assert( is_array( $expired_invitation ) && ! $expired_store->has_active_hub_state( $now + 601 ), 'Expired pending invitation no longer blocks Spoke role selection' );

$controller = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeController.php' );
$authorizer = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/LinkAuthorizer.php' );
$pairing    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Commercial/UpdateAuthorizationClient.php' );
$spoke      = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/SpokeClient.php' );
$safe_http  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Security/SafeHttpClient.php' );
$step_up    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/StepUpVerifier.php' );
$grant_code = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Commercial/UpdateAuthorizationClient.php' );
$assert( false !== strpos( $authorizer, 'Authorization' ) && false !== strpos( $authorizer, 'WPBridge-Link' ) && false !== strpos( $authorizer, "query['api_key']" ), 'Proxy authentication is header-only and rejects query api_key' );
$assert( false !== strpos( $controller, "'sources:read'" ) && false !== strpos( $controller, "'updates:read'" ) && false !== strpos( $controller, "'packages:read'" ), 'Each proxy route maps to one frozen scope' );
$assert( false !== strpos( $controller, 'safe_metadata' ) && false === strpos( substr( $controller, strpos( $controller, 'private static function safe_metadata' ) ), "'grant'" ), 'Spoke metadata allowlist never contains an upstream grant field' );
$assert( false !== strpos( $pairing, 'wpbridge_spoke_pairing_forbidden' ), 'Active Spoke cannot consume a license pairing code' );
$assert( false !== strpos( $spoke, 'has_local_upstream_credentials' ) && false !== strpos( $spoke, "'update_private_key'" ), 'Spoke acceptance fails while upstream or device credentials remain' );
$assert( false !== strpos( $safe_http, 'CURLINFO_PRIMARY_IP' ) && false !== strpos( $safe_http, 'wpbridge_safe_http_peer_mismatch' ), 'Safe HTTP rechecks the connected peer IP after DNS pinning' );
$assert( false !== strpos( $step_up, "'manage_network_options'" ) && false !== strpos( $step_up, 'is_super_admin()' ), 'Multisite Hub and Spoke management requires network capability and super-admin' );
$assert( false !== strpos( $grant_code, 'wpbridge_spoke_grant_forbidden' ), 'Active Spoke cannot issue a direct upstream package or metadata grant' );

exit( $failures > 0 ? 1 : 0 );
