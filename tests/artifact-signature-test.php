<?php
/** Detached artifact signature contract tests. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['wpbridge_signature_transients'] = [];
function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_title( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( $value ) ) ); }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function set_site_transient( string $key, $value, int $ttl ): bool { $GLOBALS['wpbridge_signature_transients'][ $key ] = $value; return true; }
function get_site_transient( string $key ) { return $GLOBALS['wpbridge_signature_transients'][ $key ] ?? false; }
function delete_site_transient( string $key ): bool { unset( $GLOBALS['wpbridge_signature_transients'][ $key ] ); return true; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
class WP_Error {
	private string $code;
	public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}

require_once dirname( __DIR__ ) . '/includes/Security/PackageIntegrityVerifier.php';
require_once dirname( __DIR__ ) . '/includes/UpdateSource/Handlers/HandlerInterface.php';
use WPBridge\Security\PackageIntegrityVerifier;
use WPBridge\UpdateSource\Handlers\UpdateInfo;

$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
	if ( ! $condition ) { ++$failures; }
};
$b64url = static function ( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); };

$body       = 'signed-wpbridge-artifact-fixture';
$file       = tempnam( sys_get_temp_dir(), 'wpb-sig-' );
file_put_contents( $file, $body );
$sha256     = hash( 'sha256', $body );
$slug       = 'commercial-plugin';
$version    = '2.0.0';
$basename   = 'commercial-plugin-2.0.0.zip';
$size       = strlen( $body );
$signed_at  = '2026-08-13T00:00:00Z';
$canonical  = "WENPAI-RELEASE-SIGNATURE-V1\nslug:{$slug}\nversion:{$version}\nfile:{$basename}\nsize:{$size}\nsha256:{$sha256}\nsigned_at:{$signed_at}\n";
$seed       = str_repeat( "\x33", SODIUM_CRYPTO_SIGN_SEEDBYTES );
$keypair    = sodium_crypto_sign_seed_keypair( $seed );
$public     = sodium_crypto_sign_publickey( $keypair );
$private    = sodium_crypto_sign_secretkey( $keypair );
$signature  = $b64url( sodium_crypto_sign_detached( $canonical, $private ) );
$public_key = $b64url( $public );
$assert( 'F8t5-ytBIPKx7GXkGY1uCLKOgT_rAeSkAIObheGAgM4' === $public_key, 'Frozen Ed25519 public key matches the cross-language vector' );
$record     = [
	'sha256' => $sha256, 'slug' => $slug, 'version' => $version,
	'artifact_file' => $basename, 'artifact_size' => $size, 'artifact_signed_at' => $signed_at,
	'signature_scheme' => 'ed25519', 'signature_kid' => 'release-2026q3',
	'signature' => $signature, 'signature_required' => true,
	'artifact_public_keys' => [
		'release-2026q3' => [
			'public_key' => $public_key, 'status' => 'active',
			'not_before' => '2026-01-01T00:00:00Z',
		],
	],
];

$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $record );
$assert( true === $result, 'Frozen detached Ed25519 vector verifies after SHA-256' );
$assert( $signature === 'HB6n5Y-xBg1VDGLp7ozLItIHHPv30X0KI5SY0-hZNaLJe5A-wzBrJN3adlJBztEVWDL7lEW4BdOs-Xc9nwSPAw', 'Frozen base64url signature matches the cross-language vector' );

$verify_only = $record;
$verify_only['artifact_public_keys']['release-2026q3'] = [
	'public_key' => $public_key, 'status' => 'verify-only',
	'not_before' => '2026-01-01T00:00:00Z', 'not_after' => '2026-12-31T23:59:59Z',
];
$assert( true === PackageIntegrityVerifier::verify_downloaded_file( $file, $verify_only ), 'Verify-only rotation key accepts an older signed release' );

$before_window = $record;
$before_window['artifact_public_keys']['release-2026q3']['not_before'] = '2026-08-13T12:00:01Z';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $before_window );
$assert( is_wp_error( $result ) && 'wpbridge_signature_key_window' === $result->get_error_code(), 'Signature before key not-before fails closed' );

$after_window = $verify_only;
$after_window['artifact_public_keys']['release-2026q3']['not_after'] = '2026-08-12T23:59:59Z';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $after_window );
$assert( is_wp_error( $result ) && 'wpbridge_signature_key_window' === $result->get_error_code(), 'Signature after key not-after fails closed' );

$inclusive_window = $verify_only;
$inclusive_window['artifact_public_keys']['release-2026q3']['not_before'] = $signed_at;
$inclusive_window['artifact_public_keys']['release-2026q3']['not_after'] = $signed_at;
$assert( true === PackageIntegrityVerifier::verify_downloaded_file( $file, $inclusive_window ), 'Key validity window includes both exact boundaries' );

$verify_only_without_end = $record;
$verify_only_without_end['artifact_public_keys']['release-2026q3']['status'] = 'verify-only';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $verify_only_without_end );
$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Verify-only key without not-after is rejected from the keyring' );

$fractional_key_window = $record;
$fractional_key_window['artifact_public_keys']['release-2026q3']['not_before'] = '2026-01-01T00:00:00.000Z';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $fractional_key_window );
$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Key windows reject timestamps outside exact UTC Z seconds format' );

$deployment_ring = [ 'release-2026q3' => [ 'public_key' => $public_key, 'status' => 'active', 'not_before' => '2026-01-01T00:00:00Z' ] ];
$same_source_ring = [ 'release-2026q3' => [ 'public_key' => $public_key, 'status' => 'verify-only' ] ];
$restricted = PackageIntegrityVerifier::restrict_to_deployment_keyring( $deployment_ring, $same_source_ring );
$assert( $public_key === ( $restricted['release-2026q3']['public_key'] ?? '' ) && '2026-01-01T00:00:00Z' === ( $restricted['release-2026q3']['not_before'] ?? '' ), 'Source may repeat a deployment-approved kid only with the same public key without overriding its window' );

$conflicting_keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x44", SODIUM_CRYPTO_SIGN_SEEDBYTES ) );
$conflicting_public  = $b64url( sodium_crypto_sign_publickey( $conflicting_keypair ) );
$conflicting_source  = [ 'release-2026q3' => [ 'public_key' => $conflicting_public, 'status' => 'active' ] ];
$restricted_conflict = PackageIntegrityVerifier::restrict_to_deployment_keyring( $deployment_ring, $conflicting_source );
$assert( [] === $restricted_conflict, 'Source cannot replace a deployment-approved kid with a different public key' );
$conflict_record = $record;
$conflict_record['artifact_public_keys'] = $restricted_conflict;
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $conflict_record );
$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Conflicting source overlay makes required verification fail closed' );

$appended_source = [ 'source-added-key' => [ 'public_key' => $conflicting_public, 'status' => 'active' ] ];
$restricted_append = PackageIntegrityVerifier::restrict_to_deployment_keyring( $deployment_ring, $appended_source );
$assert( [] === $restricted_append, 'Source cannot append a kid absent from the deployment allowlist' );
$append_record = $record;
$append_record['artifact_public_keys'] = $restricted_append;
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $append_record );
$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Unapproved source kid makes required verification fail closed' );

$unknown = $record;
$unknown['signature_kid'] = 'unknown-key';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $unknown );
$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Unknown key id fails closed' );

$wrong = $record;
$wrong['signature'] = str_repeat( 'A', 86 );
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $wrong );
$assert( is_wp_error( $result ) && 'wpbridge_signature_mismatch' === $result->get_error_code(), 'Wrong detached signature fails closed even when SHA-256 matches' );

$missing = $record;
$missing['signature'] = '';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $missing );
$assert( is_wp_error( $result ) && 'wpbridge_signature_metadata_invalid' === $result->get_error_code(), 'Required missing signature fails closed' );

$missing_file = $record;
$missing_file['artifact_file'] = '';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $missing_file );
$assert( is_wp_error( $result ) && 'wpbridge_signature_metadata_invalid' === $result->get_error_code(), 'Required missing artifact filename fails closed' );

$missing_signed_at = $record;
$missing_signed_at['artifact_signed_at'] = '';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $missing_signed_at );
$assert( is_wp_error( $result ) && 'wpbridge_signature_metadata_invalid' === $result->get_error_code(), 'Required missing artifact signing time fails closed' );

$offset_signed_at = $record;
$offset_signed_at['artifact_signed_at'] = '2026-08-13T12:00:00+00:00';
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $offset_signed_at );
$assert( is_wp_error( $result ) && 'wpbridge_signature_metadata_invalid' === $result->get_error_code(), 'Artifact signing time must use exact UTC Z seconds format' );

$bridge_key = $record;
$bridge_key['artifact_public_keys'] = [];
$bridge_key['public_key'] = $public_key;
$result = PackageIntegrityVerifier::verify_downloaded_file( $file, $bridge_key );
$assert( is_wp_error( $result ) && 'wpbridge_signature_unknown_key' === $result->get_error_code(), 'Bridge-returned public key cannot become a trust root' );

$legacy = [ 'sha256' => $sha256, 'signature_required' => false ];
$assert( true === PackageIntegrityVerifier::verify_downloaded_file( $file, $legacy ), 'Legacy non-required SHA-256-only metadata remains compatible' );

$package = 'https://packages.example.test/commercial-plugin.zip';
$assert( PackageIntegrityVerifier::remember( $package, $sha256, 600, $record ), 'Signed integrity record can be remembered' );
$remembered = PackageIntegrityVerifier::expected_integrity( $package );
$assert( 'release-2026q3' === ( $remembered['signature_kid'] ?? '' ), 'Remembered record retains detached signature metadata' );

$legacy_package = 'https://packages.example.test/legacy.zip';
$legacy_key = PackageIntegrityVerifier::TRANSIENT_PREFIX . hash( 'sha256', $legacy_package );
$GLOBALS['wpbridge_signature_transients'][ $legacy_key ] = $sha256;
$assert( $sha256 === PackageIntegrityVerifier::expected( $legacy_package ), 'Pre-upgrade string transient remains readable under the stable key prefix' );

$update_info = UpdateInfo::from_array( array_merge( $record, [ 'download_url' => $package ] ) );
$assert( 'ed25519' === $update_info->signature_scheme && 'release-2026q3' === $update_info->signature_kid && $basename === $update_info->artifact_file && $size === $update_info->artifact_size && $signed_at === $update_info->artifact_signed_at, 'Bridge artifact signature metadata is parsed without accepting a remote public key' );
$update_object = (array) $update_info->to_wp_update_object();
$assert( ! array_key_exists( 'public_key', $update_object ) && [] === $update_object['_wpbridge_artifact_keys'], 'Remote metadata cannot populate the local artifact keyring' );

unlink( $file );
exit( $failures > 0 ? 1 : 0 );
