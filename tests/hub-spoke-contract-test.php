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

$GLOBALS['wpbridge_test_options'] = [];
$GLOBALS['wpbridge_test_uuid']    = 0;

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}
function is_multisite(): bool {
	return false;
}
function get_option( string $key, $default = false ) {
	return $GLOBALS['wpbridge_test_options'][ $key ] ?? $default;
}
function update_option( string $key, $value, bool $autoload = true ): bool {
	unset( $autoload );
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
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

require_once dirname( __DIR__ ) . '/includes/Security/Encryption.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/InstallationIdentity.php';
require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeStore.php';

use WPBridge\HubSpoke\HubSpokeStore;
use WPBridge\HubSpoke\InstallationIdentity;
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
$invitation = $store->create_invitation( [ 'updates:read', 'sources:read' ], [ 'wpbridge', 'wenpai-client' ], $now );
$assert( is_array( $invitation ) && preg_match( '/^WPBI1-[A-Za-z0-9_-]{43}$/', $invitation['invitation_token'] ) === 1, 'Invitation returns a one-time opaque token' );
$raw_store = serialize( get_option( 'wpbridge_hub_invitations_v1', [] ) );
$assert( false === strpos( $raw_store, $invitation['invitation_token'] ) && false !== strpos( $raw_store, hash( 'sha256', $invitation['invitation_token'] ) ), 'Hub persists only the invitation token hash' );

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
$assert( null !== $store->authorize( $accepted['link_credential'], $now ), 'Current link credential authorizes an active link' );

$replay = $store->accept( $invitation['invitation_id'], $accept_body, $now );
$assert( is_wp_error( $replay ), 'Invitation and acceptance proof cannot be replayed' );

$rotated = $store->rotate( $accepted['link_id'], $now + 1 );
$assert( is_array( $rotated ) && null !== $store->authorize( $accepted['link_credential'], $now + 299 ), 'Rotation keeps the prior credential for at most five minutes' );
$assert( null === $store->authorize( $accepted['link_credential'], $now + 302 ), 'Rotated credential is rejected after the overlap window' );
$assert( $store->revoke( $accepted['link_id'], $now + 303 ) && null === $store->authorize( $rotated['link_credential'], $now + 303 ), 'Revocation immediately rejects the active credential' );

$spoke_saved = $store->save_spoke_link( 'https://hub.example', $rotated, $now + 2 );
$spoke_rows  = get_option( 'wpbridge_spoke_links_v1', [] );
$spoke_row   = $spoke_rows[ $accepted['link_id'] ] ?? [];
$assert( $spoke_saved && Encryption::is_encrypted( (string) ( $spoke_row['credential_ciphertext'] ?? '' ) ), 'Spoke persists the reusable link credential under AEAD' );
$assert( false === strpos( serialize( $spoke_rows ), $rotated['link_credential'] ), 'Spoke option does not contain the plaintext credential' );

$controller = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeController.php' );
$authorizer = (string) file_get_contents( dirname( __DIR__ ) . '/includes/HubSpoke/LinkAuthorizer.php' );
$assert( false !== strpos( $authorizer, 'Authorization' ) && false !== strpos( $authorizer, 'WPBridge-Link' ) && false !== strpos( $authorizer, "query['api_key']" ), 'Proxy authentication is header-only and rejects query api_key' );
$assert( false !== strpos( $controller, "'sources:read'" ) && false !== strpos( $controller, "'updates:read'" ) && false !== strpos( $controller, "'packages:read'" ), 'Each proxy route maps to one frozen scope' );
$assert( false !== strpos( $controller, 'safe_metadata' ) && false === strpos( substr( $controller, strpos( $controller, 'private static function safe_metadata' ) ), "'grant'" ), 'Spoke metadata allowlist never contains an upstream grant field' );

exit( $failures > 0 ? 1 : 0 );
