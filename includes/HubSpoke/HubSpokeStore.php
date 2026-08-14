<?php
/**
 * Local Hub-Spoke state store.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

use WPBridge\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores only hashed Hub credentials and AEAD-encrypted Spoke credentials. */
final class HubSpokeStore {
	private const INVITATIONS = 'wpbridge_hub_invitations_v1';
	private const LINKS       = 'wpbridge_hub_links_v1';
	private const SPOKE_LINKS = 'wpbridge_spoke_links_v1';
	private const AUDIT       = 'wpbridge_hub_audit_v1';

	/** Frozen scopes. */
	public const SCOPES = [ 'packages:read', 'sources:read', 'updates:read' ];

	/** @return array<string,mixed>|\WP_Error */
	public function create_invitation( array $scopes, array $slugs, int $now ) {
		$normalized = self::normalize_policy( $scopes, $slugs );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$id    = strtolower( wp_generate_uuid4() );
		$token = 'WPBI1-' . InstallationIdentity::base64url( random_bytes( 32 ) );
		$rows  = $this->invitations();
		$rows[ $id ] = [
			'invitation_id'         => $id,
			'token_sha256'          => hash( 'sha256', $token ),
			'hub_installation_uuid' => InstallationIdentity::uuid(),
			'scopes'                => $normalized['scopes'],
			'slug_allowlist'        => $normalized['slug_allowlist'],
			'status'                => 'pending',
			'expires_at'            => $now + 600,
			'created_at'            => $now,
			'nonces'                => [],
		];
		$this->save( self::INVITATIONS, $rows );
		$this->audit( 'invitation.created', $id, $now );
		return [
			'invitation_id'         => $id,
			'invitation_token'      => $token,
			'hub_installation_uuid' => InstallationIdentity::uuid(),
			'scopes'                => $normalized['scopes'],
			'slug_allowlist'        => $normalized['slug_allowlist'],
			'expires_at'            => gmdate( 'c', $now + 600 ),
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function challenge( string $id, string $token, int $now ) {
		$rows = $this->invitations();
		$row  = $rows[ $id ] ?? null;
		if ( ! is_array( $row ) || ! self::valid_invitation_token( $token ) || ! hash_equals( (string) $row['token_sha256'], hash( 'sha256', $token ) ) || 'pending' !== $row['status'] || $now >= (int) $row['expires_at'] ) {
			return new \WP_Error( 'wpbridge_invitation_invalid', __( '邀请无效或已过期。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		return [
			'invitation_id'         => $id,
			'hub_installation_uuid' => (string) $row['hub_installation_uuid'],
			'scopes'                => $row['scopes'],
			'slug_allowlist'        => $row['slug_allowlist'],
			'expires_at'            => gmdate( 'c', (int) $row['expires_at'] ),
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function accept( string $id, array $request, int $now ) {
		$rows = $this->invitations();
		$row  = $rows[ $id ] ?? null;
		$token = (string) ( $request['invitation_token'] ?? '' );
		if ( ! is_array( $row ) || ! self::valid_invitation_token( $token ) || ! hash_equals( (string) $row['token_sha256'], hash( 'sha256', $token ) ) || 'pending' !== $row['status'] || $now >= (int) $row['expires_at'] ) {
			return new \WP_Error( 'wpbridge_invitation_invalid', __( '邀请无效或已过期。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		$nonce = (string) ( $request['nonce'] ?? '' );
		if ( isset( $row['nonces'][ hash( 'sha256', $nonce ) ] ) ) {
			return new \WP_Error( 'wpbridge_acceptance_replay', __( '接受证明已使用。', 'wpbridge' ), [ 'status' => 409 ] );
		}
		$canonical = self::acceptance_canonical( $id, $row, $request, $now );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		$public_key = InstallationIdentity::base64url_decode( (string) $request['spoke_public_key'] );
		$signature  = InstallationIdentity::base64url_decode( (string) $request['signature'] );
		if ( ! is_string( $public_key ) || ! is_string( $signature ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! sodium_crypto_sign_verify_detached( $signature, $canonical, $public_key ) ) {
			return new \WP_Error( 'wpbridge_acceptance_proof_invalid', __( 'Spoke 持钥证明无效。', 'wpbridge' ), [ 'status' => 401 ] );
		}

		$link_id    = strtolower( wp_generate_uuid4() );
		$credential = 'WPBL1-' . InstallationIdentity::base64url( random_bytes( 32 ) );
		$links      = $this->links();
		$links[ $link_id ] = [
			'link_id'                    => $link_id,
			'hub_installation_uuid'      => (string) $row['hub_installation_uuid'],
			'spoke_installation_uuid'    => strtolower( (string) $request['spoke_installation_uuid'] ),
			'spoke_public_key_sha256'    => hash( 'sha256', $public_key ),
			'credential_sha256'          => hash( 'sha256', $credential ),
			'previous_credential_sha256' => '',
			'previous_valid_until'       => 0,
			'scopes'                     => $row['scopes'],
			'slug_allowlist'             => $row['slug_allowlist'],
			'status'                     => 'active',
			'created_at'                 => $now,
			'rotated_at'                 => null,
			'revoked_at'                 => null,
		];
		$row['status']                             = 'active';
		$row['nonces'][ hash( 'sha256', $nonce ) ] = $now;
		$row['consumed_at']                        = $now;
		$rows[ $id ]                               = $row;
		$this->save( self::LINKS, $links );
		$this->save( self::INVITATIONS, $rows );
		$this->audit( 'link.accepted', $link_id, $now );
		return [
			'link_id'         => $link_id,
			'link_credential' => $credential,
			'scopes'          => $row['scopes'],
			'slug_allowlist'  => $row['slug_allowlist'],
			'expires_at'      => null,
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function public_links(): array {
		$output = [];
		foreach ( $this->links() as $row ) {
			$output[] = [
				'link_id'                 => $row['link_id'],
				'spoke_installation_uuid' => $row['spoke_installation_uuid'],
				'spoke_public_key_sha256' => $row['spoke_public_key_sha256'],
				'scopes'                  => $row['scopes'],
				'slug_allowlist'          => $row['slug_allowlist'],
				'status'                  => $row['status'],
				'created_at'              => gmdate( 'c', (int) $row['created_at'] ),
				'rotated_at'              => null === $row['rotated_at'] ? null : gmdate( 'c', (int) $row['rotated_at'] ),
				'revoked_at'              => null === $row['revoked_at'] ? null : gmdate( 'c', (int) $row['revoked_at'] ),
			];
		}
		return array_values( $output );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rotate( string $id, int $now ) {
		$links = $this->links();
		$row   = $links[ $id ] ?? null;
		if ( ! is_array( $row ) || 'active' !== $row['status'] ) {
			return new \WP_Error( 'wpbridge_link_not_found', __( 'Hub link 不存在。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		$credential = 'WPBL1-' . InstallationIdentity::base64url( random_bytes( 32 ) );
		$row['previous_credential_sha256'] = $row['credential_sha256'];
		$row['previous_valid_until']       = $now + 300;
		$row['credential_sha256']          = hash( 'sha256', $credential );
		$row['rotated_at']                 = $now;
		$links[ $id ]                      = $row;
		$this->save( self::LINKS, $links );
		$this->audit( 'link.rotated', $id, $now );
		return [
			'link_id'             => $id,
			'link_credential'     => $credential,
			'previous_valid_until' => gmdate( 'c', $now + 300 ),
			'scopes'              => $row['scopes'],
			'slug_allowlist'      => $row['slug_allowlist'],
		];
	}

	public function revoke( string $id, int $now ): bool {
		$links = $this->links();
		if ( ! isset( $links[ $id ] ) || 'active' !== $links[ $id ]['status'] ) {
			return false;
		}
		$links[ $id ]['status']                     = 'revoked';
		$links[ $id ]['credential_sha256']          = '';
		$links[ $id ]['previous_credential_sha256'] = '';
		$links[ $id ]['previous_valid_until']       = 0;
		$links[ $id ]['revoked_at']                 = $now;
		$this->save( self::LINKS, $links );
		$this->audit( 'link.revoked', $id, $now );
		return true;
	}

	/** @return array<string,mixed>|null */
	public function authorize( string $credential, int $now ): ?array {
		if ( ! self::valid_link_credential( $credential ) ) {
			return null;
		}
		$hash = hash( 'sha256', $credential );
		foreach ( $this->links() as $row ) {
			if ( 'active' !== $row['status'] ) {
				continue;
			}
			if ( hash_equals( (string) $row['credential_sha256'], $hash ) || ( $now < (int) $row['previous_valid_until'] && '' !== $row['previous_credential_sha256'] && hash_equals( (string) $row['previous_credential_sha256'], $hash ) ) ) {
				return $row;
			}
		}
		return null;
	}

	/** Persist the one reusable credential only on the Spoke, under AEAD. */
	public function save_spoke_link( string $hub_origin, array $response, int $now ): bool {
		$credential = (string) ( $response['link_credential'] ?? '' );
		if ( ! self::valid_link_credential( $credential ) ) {
			return false;
		}
		$ciphertext = Encryption::encrypt( $credential );
		if ( '' === $ciphertext ) {
			return false;
		}
		$rows = (array) get_option( self::SPOKE_LINKS, [] );
		$rows[ (string) $response['link_id'] ] = [
			'link_id'               => (string) $response['link_id'],
			'hub_origin'            => $hub_origin,
			'credential_ciphertext' => $ciphertext,
			'key_version'           => defined( 'WPBRIDGE_ENCRYPTION_KEY_VERSION' ) ? (string) WPBRIDGE_ENCRYPTION_KEY_VERSION : 'default',
			'scopes'                => $response['scopes'],
			'slug_allowlist'        => $response['slug_allowlist'],
			'status'                => 'active',
			'created_at'            => $now,
		];
		return $this->save( self::SPOKE_LINKS, $rows );
	}

	/** Whether this installation is already an active Spoke. */
	public function has_active_spoke_link(): bool {
		foreach ( (array) get_option( self::SPOKE_LINKS, [] ) as $row ) {
			if ( is_array( $row ) && 'active' === ( $row['status'] ?? '' ) && ! empty( $row['credential_ciphertext'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,array<string,mixed>> */
	private function invitations(): array {
		return (array) get_option( self::INVITATIONS, [] );
	}

	/** @return array<string,array<string,mixed>> */
	private function links(): array {
		return (array) get_option( self::LINKS, [] );
	}

	private function save( string $option, array $value ): bool {
		return update_option( $option, $value, false ) || $value === get_option( $option, [] );
	}

	private function audit( string $action, string $resource, int $now ): void {
		$rows   = (array) get_option( self::AUDIT, [] );
		$rows[] = [ 'action' => $action, 'resource_sha256' => hash( 'sha256', $resource ), 'occurred_at' => $now, 'user_id' => get_current_user_id() ];
		update_option( self::AUDIT, array_slice( $rows, -500 ), false );
	}

	/** @return array<string,array<int,string>>|\WP_Error */
	public static function normalize_policy( array $scopes, array $slugs ) {
		if ( [] === $scopes || [] === $slugs || count( $scopes ) > 3 || count( $slugs ) > 200 ) {
			return new \WP_Error( 'wpbridge_link_policy_invalid', __( 'Hub link 权限策略无效。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || ! in_array( $scope, self::SCOPES, true ) ) {
				return new \WP_Error( 'wpbridge_link_policy_invalid', __( 'Hub link scope 无效。', 'wpbridge' ), [ 'status' => 400 ] );
			}
		}
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $slug ) ) {
				return new \WP_Error( 'wpbridge_link_policy_invalid', __( 'Hub link slug 无效。', 'wpbridge' ), [ 'status' => 400 ] );
			}
		}
		$scopes = array_values( array_unique( $scopes ) );
		$slugs  = array_values( array_unique( $slugs ) );
		sort( $scopes, SORT_STRING );
		sort( $slugs, SORT_STRING );
		return [ 'scopes' => $scopes, 'slug_allowlist' => $slugs ];
	}

	/** @return string|\WP_Error */
	private static function acceptance_canonical( string $id, array $row, array $request, int $now ) {
		$expected = [ 'invitation_token', 'spoke_installation_uuid', 'spoke_public_key', 'nonce', 'timestamp', 'signature' ];
		$actual   = array_keys( $request );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected || ! is_string( $request['timestamp'] ) || 1 !== preg_match( '/^[0-9]{10}$/', $request['timestamp'] ) || abs( $now - (int) $request['timestamp'] ) > 120 || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower( (string) $request['spoke_installation_uuid'] ) ) ) {
			return new \WP_Error( 'wpbridge_acceptance_invalid', __( '接受请求无效。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		$nonce = InstallationIdentity::base64url_decode( (string) $request['nonce'] );
		$key   = InstallationIdentity::base64url_decode( (string) $request['spoke_public_key'] );
		if ( ! is_string( $nonce ) || 32 !== strlen( $nonce ) || ! is_string( $key ) || ! defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return new \WP_Error( 'wpbridge_acceptance_invalid', __( '接受请求无效。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		return "WPBRIDGE-HUB-LINK-ACCEPT-V1\n"
			. 'invitation_id:' . $id . "\n"
			. 'invitation_token_sha256:' . hash( 'sha256', (string) $request['invitation_token'] ) . "\n"
			. 'hub_installation_uuid:' . $row['hub_installation_uuid'] . "\n"
			. 'spoke_installation_uuid:' . strtolower( (string) $request['spoke_installation_uuid'] ) . "\n"
			. 'spoke_public_key_sha256:' . hash( 'sha256', $key ) . "\n"
			. 'nonce:' . $request['nonce'] . "\n"
			. 'timestamp:' . $request['timestamp'] . "\n";
	}

	public static function valid_invitation_token( string $value ): bool {
		return 1 === preg_match( '/^WPBI1-[A-Za-z0-9_-]{43}$/', $value );
	}

	public static function valid_link_credential( string $value ): bool {
		return 1 === preg_match( '/^WPBL1-[A-Za-z0-9_-]{43}$/', $value );
	}
}
