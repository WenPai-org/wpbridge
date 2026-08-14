<?php
/**
 * Local Hub-Spoke state store.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores only hashed Hub credentials and AEAD-encrypted Spoke credentials. */
final class HubSpokeStore {
	private const INVITATIONS = 'wpbridge_hub_invitations_v1';
	private const LINKS       = 'wpbridge_hub_links_v1';
	private const SPOKE_LINKS = 'wpbridge_spoke_links_v1';
	private const AUDIT       = 'wpbridge_hub_audit_v1';
	private const RATE        = 'wpbridge_hub_rate_v1';
	private const RECONCILE   = 'wpbridge_spoke_reconcile_v1';
	private const ROLE        = 'wpbridge_hub_spoke_role_v1';
	private const ORIGIN      = 'wpbridge_hub_network_origin_v1';
	private const UNCERTAIN   = 'wpbridge_spoke_uncertain_accept_v1';

	/** Frozen scopes. */
	public const SCOPES = [ 'packages:read', 'sources:read', 'updates:read' ];

	/** @return array<string,mixed>|\WP_Error */
	public function create_invitation( array $scopes, array $slugs, int $now, string $hub_origin = '' ) {
		if ( ! self::identity_ready() ) {
			return self::identity_error();
		}
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
		$this->cleanup_expired_role_locked( $now );
		$role = $this->state_option( self::ROLE );
		if ( [] !== $this->state_option( self::RECONCILE ) || [] !== $this->state_option( self::UNCERTAIN ) || [] !== $role || $this->has_active_spoke_link() ) {
			return new \WP_Error( 'wpbridge_spoke_cannot_be_hub', __( 'Active Spoke 不能创建 Hub link。', 'wpbridge' ), [ 'status' => 409 ] );
		}
		$frozen_origin = (string) ( $this->state_option( self::ORIGIN )['origin'] ?? '' );
		if ( '' === $hub_origin || ( '' !== $frozen_origin && ! hash_equals( $frozen_origin, $hub_origin ) ) ) {
			return new \WP_Error( 'wpbridge_hub_origin_mismatch', __( 'Hub network origin 与冻结值不一致。', 'wpbridge' ), [ 'status' => 409 ] );
		}
		if ( '' === $frozen_origin && ! $this->save( self::ORIGIN, [ 'origin' => $hub_origin, 'created_at' => $now ] ) ) {
			return new \WP_Error( 'wpbridge_hub_store_failed', __( 'Hub network origin 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$this->expire_invitations( $now );
		$normalized = self::normalize_policy( $scopes, $slugs );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$id    = strtolower( wp_generate_uuid4() );
		$token = 'WPBI1-' . InstallationIdentity::base64url( random_bytes( 32 ) );
		$rows  = $this->invitations();
		$previous_rows = $rows;
		$rows[ $id ] = [
			'invitation_id'         => $id,
			'token_sha256'          => hash( 'sha256', $token ),
			'hub_installation_uuid' => InstallationIdentity::uuid(),
			'hub_origin'            => $hub_origin,
			'scopes'                => $normalized['scopes'],
			'slug_allowlist'        => $normalized['slug_allowlist'],
			'status'                => 'pending',
			'expires_at'            => $now + 600,
			'created_at'            => $now,
			'nonces'                => [],
		];
		if ( ! $this->save( self::INVITATIONS, $rows ) || ! $this->audit( 'invitation.created', $id, $now ) ) {
			$rolled_back = $this->save( self::INVITATIONS, $previous_rows );
			if ( ! $rolled_back ) {
				$rows[ $id ]['status'] = 'expired';
				$rows[ $id ]['error'] = 'storage_inconsistent';
				$rows[ $id ]['token_sha256'] = '';
				$this->save( self::INVITATIONS, $rows );
			}
			return new \WP_Error( $rolled_back ? 'wpbridge_hub_store_failed' : 'wpbridge_hub_store_inconsistent', __( 'Hub invitation 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return [
			'invitation_id'         => $id,
			'invitation_token'      => $token,
			'hub_installation_uuid' => InstallationIdentity::uuid(),
			'hub_origin'            => $hub_origin,
			'scopes'                => $normalized['scopes'],
			'slug_allowlist'        => $normalized['slug_allowlist'],
			'expires_at'            => gmdate( 'c', $now + 600 ),
		];
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public function challenge( string $id, string $token, int $now ) {
		if ( ! self::identity_ready() ) {
			return self::identity_error();
		}
		$rows = $this->invitations();
		$row  = $rows[ $id ] ?? null;
		if ( ! is_array( $row ) || ! hash_equals( $this->network_origin(), (string) ( $row['hub_origin'] ?? '' ) ) || ! self::valid_invitation_token( $token ) || ! hash_equals( (string) $row['token_sha256'], hash( 'sha256', $token ) ) || 'pending' !== $row['status'] || $now >= (int) $row['expires_at'] ) {
			return new \WP_Error( 'wpbridge_invitation_invalid', __( '邀请无效或已过期。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		return [
			'invitation_id'         => $id,
			'hub_installation_uuid' => (string) $row['hub_installation_uuid'],
			'hub_public_key_fingerprint' => hash( 'sha256', (string) InstallationIdentity::base64url_decode( InstallationIdentity::public_key() ) ),
			'scopes'                => $row['scopes'],
			'slug_allowlist'        => $row['slug_allowlist'],
			'expires_at'            => gmdate( 'c', (int) $row['expires_at'] ),
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function accept( string $id, array $request, int $now ) {
		if ( ! self::identity_ready() ) {
			return self::identity_error();
		}
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
		if ( [] !== $this->state_option( self::RECONCILE ) || [] !== $this->state_option( self::UNCERTAIN ) || [] !== $this->state_option( self::ROLE ) || $this->has_active_spoke_link() ) {
			return new \WP_Error( 'wpbridge_hub_role_conflict', __( '当前 installation lifecycle 尚未完成清理。', 'wpbridge' ), [ 'status' => 409 ] );
		}
		$rows = $this->invitations();
		$row  = $rows[ $id ] ?? null;
		$token = (string) ( $request['invitation_token'] ?? '' );
		if ( ! is_array( $row ) || ! hash_equals( $this->network_origin(), (string) ( $row['hub_origin'] ?? '' ) ) || ! self::valid_invitation_token( $token ) || ! hash_equals( (string) $row['token_sha256'], hash( 'sha256', $token ) ) || 'pending' !== $row['status'] || $now >= (int) $row['expires_at'] ) {
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
		$previous_links = $links;
		$previous_invitations = $rows;
		$links[ $link_id ] = [
			'link_id'                    => $link_id,
			'invitation_id'              => $id,
			'hub_installation_uuid'      => (string) $row['hub_installation_uuid'],
			'hub_origin'                 => (string) $row['hub_origin'],
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
		if ( ! $this->save( self::LINKS, $links ) ) {
			return new \WP_Error( 'wpbridge_hub_store_failed', __( 'Hub link 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		if ( ! $this->save( self::INVITATIONS, $rows ) || ! $this->audit( 'link.accepted', $link_id, $now ) ) {
			$links_rolled_back = $this->save( self::LINKS, $previous_links );
			$invitations_rolled_back = $this->save( self::INVITATIONS, $previous_invitations );
			$rolled_back = $links_rolled_back && $invitations_rolled_back;
			return new \WP_Error( $rolled_back ? 'wpbridge_hub_store_failed' : 'wpbridge_hub_store_inconsistent', __( 'Hub link 持久化失败，未签发凭据。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return [
			'link_id'         => $link_id,
			'link_credential' => $credential,
			'scopes'          => $row['scopes'],
			'slug_allowlist'  => $row['slug_allowlist'],
			'expires_at'      => null,
		];
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public function public_links(): array {
		$output = [];
		foreach ( $this->links() as $row ) {
			$output[] = [
				'link_id'                 => $row['link_id'],
				'invitation_id'           => $row['invitation_id'] ?? '',
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

	/** Cancel a pending invitation without disclosing its token. */
	public function cancel_invitation( string $id, int $now, string $reason = 'admin_cancel' ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$rows = $this->invitations();
			if ( ! isset( $rows[ $id ] ) || 'pending' !== ( $rows[ $id ]['status'] ?? '' ) ) {
				return false;
			}
			$previous = $rows;
			$rows[ $id ]['status'] = 'expired';
			$rows[ $id ]['error'] = 'cancelled_by_admin';
			$rows[ $id ]['token_sha256'] = '';
			$rows[ $id ]['cancelled_at'] = $now;
			if ( ! $this->save( self::INVITATIONS, $rows ) || ! $this->audit( 'invitation.cancelled', $id, $now, $reason, 'admin' ) ) {
				if ( ! $this->save( self::INVITATIONS, $previous ) ) {
					$rows[ $id ]['status'] = 'expired';
					$rows[ $id ]['error'] = 'storage_inconsistent';
					$this->save( self::INVITATIONS, $rows );
				}
				return false;
			}
			return true;
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rotate( string $id, int $now, string $reason = 'admin_rotation' ) {
		if ( ! self::identity_ready() ) {
			return self::identity_error();
		}
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
		$links = $this->links();
		$previous_links = $links;
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
		if ( ! $this->save( self::LINKS, $links ) ) {
			return new \WP_Error( 'wpbridge_hub_store_failed', __( 'Hub link rotation 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		if ( ! $this->audit( 'link.rotated', $id, $now, $reason, 'admin' ) ) {
			$rolled_back = $this->save( self::LINKS, $previous_links );
			if ( ! $rolled_back ) {
				$links[ $id ]['status'] = 'revoked';
				$links[ $id ]['error'] = 'storage_inconsistent';
				$links[ $id ]['credential_sha256'] = '';
				$links[ $id ]['previous_credential_sha256'] = '';
				$this->save( self::LINKS, $links );
			}
			return new \WP_Error( $rolled_back ? 'wpbridge_hub_store_failed' : 'wpbridge_hub_store_inconsistent', __( 'Hub link rotation 审计失败，未签发凭据。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return [
			'link_id'             => $id,
			'link_credential'     => $credential,
			'previous_valid_until' => gmdate( 'c', $now + 300 ),
			'scopes'              => $row['scopes'],
			'slug_allowlist'      => $row['slug_allowlist'],
		];
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	public function revoke( string $id, int $now, string $reason = 'admin_revoke', string $actor = 'admin', string $presented_credential = '' ): bool {
		if ( ! self::identity_ready() ) {
			return false;
		}
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
		$links = $this->links();
		$previous_links = $links;
		if ( ! isset( $links[ $id ] ) || 'active' !== $links[ $id ]['status'] ) {
			return false;
		}
		$links[ $id ]['status']                     = 'revoked';
		$links[ $id ]['credential_sha256']          = '';
		$receipt_hash = self::valid_link_credential( $presented_credential ) ? hash( 'sha256', $presented_credential ) : (string) $previous_links[ $id ]['credential_sha256'];
		$links[ $id ]['revoked_credential_receipts'] = [ [ 'sha256' => $receipt_hash ] ];
		$links[ $id ]['previous_credential_sha256'] = '';
		$links[ $id ]['previous_valid_until']       = 0;
		$links[ $id ]['revoked_at']                 = $now;
		if ( ! $this->save( self::LINKS, $links ) ) {
			return false;
		}
		if ( ! $this->audit( 'link.revoked', $id, $now, $reason, $actor ) ) {
			if ( ! $this->save( self::LINKS, $previous_links ) ) {
				$links[ $id ]['status'] = 'revoked';
				$links[ $id ]['error'] = 'storage_inconsistent';
				$links[ $id ]['credential_sha256'] = '';
				$links[ $id ]['previous_credential_sha256'] = '';
				$this->save( self::LINKS, $links );
			}
			return false;
		}
		return true;
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
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

	/** Idempotent compensation receipt: revoked credential may only confirm same-link cleanup. */
	public function compensation_receipt( string $credential, string $link_id ): bool {
		if ( ! self::valid_link_credential( $credential ) ) {
			return false;
		}
		$row = $this->links()[ $link_id ] ?? null;
		if ( ! is_array( $row ) || 'revoked' !== ( $row['status'] ?? '' ) ) {
			return false;
		}
		foreach ( (array) ( $row['revoked_credential_receipts'] ?? [] ) as $receipt ) {
			if ( is_array( $receipt ) && hash_equals( (string) ( $receipt['sha256'] ?? '' ), hash( 'sha256', $credential ) ) ) {
				return true;
			}
		}
		return false;
	}

	public function network_origin(): string {
		return (string) ( $this->state_option( self::ORIGIN )['origin'] ?? '' );
	}

	/** Persistent, lock-serialized per-link minute bucket. */
	public function consume_rate( string $link_id, int $minute, int $limit ): bool {
		$lock = $this->acquire_lock( 'rate-global' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$rows = (array) $this->state_option( self::RATE );
			foreach ( $rows as $key => $row ) {
				if ( ! is_array( $row ) || (int) ( $row['minute'] ?? 0 ) < $minute - 2 ) {
					unset( $rows[ $key ] );
				}
			}
			$key = hash( 'sha256', $link_id . ':' . $minute );
			$count = (int) ( $rows[ $key ]['count'] ?? 0 );
			if ( $count >= $limit ) {
				return false;
			}
			$rows[ $key ] = [ 'minute' => $minute, 'count' => $count + 1 ];
			return $this->save( self::RATE, $rows );
		} finally {
			$this->release_lock( 'rate-global' );
		}
	}

	/** Persist the one reusable credential only on the Spoke, under AEAD. */
	public function save_spoke_link( string $hub_origin, array $response, int $now ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
		if ( $this->has_active_hub_state() ) {
			return false;
		}
		$credential = (string) ( $response['link_credential'] ?? '' );
		if ( ! self::valid_link_credential( $credential ) ) {
			return false;
		}
		$ciphertext = CredentialEnvelope::encrypt( $credential, (string) $response['link_id'], $hub_origin );
		if ( '' === $ciphertext ) {
			return false;
		}
		$rows = (array) $this->state_option( self::SPOKE_LINKS );
		$previous_rows = $rows;
		$rows[ (string) $response['link_id'] ] = [
			'link_id'               => (string) $response['link_id'],
			'hub_origin'            => $hub_origin,
			'credential_ciphertext' => $ciphertext,
			'key_version'           => CredentialEnvelope::version( $ciphertext ),
			'hub_public_key_fingerprint' => (string) ( $response['hub_public_key_fingerprint'] ?? '' ),
			'spoke_public_key_fingerprint' => hash( 'sha256', (string) InstallationIdentity::base64url_decode( InstallationIdentity::public_key() ) ),
			'scopes'                => $response['scopes'],
			'slug_allowlist'        => $response['slug_allowlist'],
			'runtime_blog_id'       => is_multisite() ? get_current_blog_id() : 0,
			'status'                => 'active',
			'created_at'            => $now,
		];
		if ( ! $this->save( self::SPOKE_LINKS, $rows ) ) {
			return false;
		}
		if ( ! $this->provision_spoke_sources( (string) $response['link_id'], $hub_origin, (array) $response['slug_allowlist'] ) ) {
			if ( ! $this->save( self::SPOKE_LINKS, $previous_rows ) ) {
				$this->mark_spoke_inconsistent( (string) $response['link_id'], $now );
			}
			return false;
		}
		if ( ! $this->save( self::ROLE, [ 'role' => 'spoke-active', 'link_id' => (string) $response['link_id'], 'updated_at' => $now ] ) ) {
			$this->save( self::SPOKE_LINKS, $previous_rows );
			$this->disable_spoke_sources( (string) $response['link_id'] );
			return false;
		}
		if ( ! $this->audit( 'spoke.link.saved', (string) $response['link_id'], $now ) ) {
			$this->unlink_spoke_state_locked( (string) $response['link_id'], $previous_rows, $now );
			return false;
		}
		return true;
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	/** Atomically reserve the installation role before contacting a Hub. */
	public function reserve_spoke_role( int $now ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$this->cleanup_expired_role_locked( $now );
			if ( [] !== $this->state_option( self::RECONCILE ) || [] !== $this->state_option( self::UNCERTAIN ) || [] !== $this->state_option( self::ROLE ) || $this->has_active_hub_state( $now ) || $this->has_active_spoke_link() ) {
				return false;
			}
			$role = $this->state_option( self::ROLE );
			if ( 'spoke-reserved' === ( $role['role'] ?? '' ) && $now < (int) ( $role['expires_at'] ?? 0 ) ) {
				return false;
			}
			return $this->save( self::ROLE, [ 'role' => 'spoke-reserved', 'expires_at' => $now + 300, 'updated_at' => $now ] );
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	public function release_spoke_reservation(): void {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) { return; }
		try {
			$role = $this->state_option( self::ROLE );
			if ( 'spoke-reserved' === ( $role['role'] ?? '' ) ) { $this->save( self::ROLE, [] ); }
		} finally { $this->release_lock( 'hub-lifecycle' ); }
	}

	/** Persist a compensation retry without storing a plaintext link credential. */
	public function save_reconcile( string $hub_origin, string $link_id, string $credential, int $now, string $action = 'remote_revoke', string $admin_reason = '' ): bool {
		$lock = $this->acquire_lock( 'reconcile' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
		$rows = (array) $this->state_option( self::RECONCILE );
		if ( isset( $rows[ $link_id ] ) && is_array( $rows[ $link_id ] ) && 'processing' === ( $rows[ $link_id ]['reconcile_state'] ?? '' ) ) {
			return true;
		}
		if ( 'local_cleanup' === $action && 1 === preg_match( '/^[0-9a-f-]{36}$/', $link_id ) ) {
			$rows[ $link_id ] = [ 'link_id' => $link_id, 'hub_origin' => $hub_origin, 'credential_ciphertext' => '', 'reconcile_state' => 'pending', 'action' => 'local_cleanup', 'reason' => 'spoke_unlink', 'admin_reason_sha256' => '' === $admin_reason ? '' : hash( 'sha256', $admin_reason ), 'created_at' => $now ];
			return $this->save( self::RECONCILE, $rows );
		}
		if ( ! self::valid_link_credential( $credential ) || 1 !== preg_match( '/^[0-9a-f-]{36}$/', $link_id ) ) {
			return false;
		}
		$ciphertext = CredentialEnvelope::encrypt( $credential, $link_id, $hub_origin );
		if ( '' === $ciphertext ) {
			return false;
		}
		$reason = 'unlink_local' === $action ? 'spoke_unlink' : 'spoke_storage_failed';
		$rows[ $link_id ] = [ 'link_id' => $link_id, 'hub_origin' => $hub_origin, 'credential_ciphertext' => $ciphertext, 'reconcile_state' => 'pending', 'action' => in_array( $action, [ 'remote_revoke', 'unlink_local', 'local_cleanup' ], true ) ? $action : 'remote_revoke', 'reason' => $reason, 'admin_reason_sha256' => '' === $admin_reason ? '' : hash( 'sha256', $admin_reason ), 'created_at' => $now ];
		return $this->save( self::RECONCILE, $rows );
		} finally {
			$this->release_lock( 'reconcile' );
		}
	}

	/** Retry all durable remote-revoke compensations. */
	public function process_reconciles( ?callable $transport = null, ?int $now = null ): array {
		$transport = $transport ?? [ \WPBridge\Security\SafeHttpClient::class, 'request' ];
		$now       = $now ?? time();
		$claims    = $this->claim_reconciles( $now );
		if ( is_wp_error( $claims ) ) {
			return 'wpbridge_reconcile_claim_failed' === $claims->get_error_code()
				? [ 'processed' => 0, 'resolved' => 0, 'failed' => 0, 'storage_error' => 1 ]
				: [ 'processed' => 0, 'resolved' => 0, 'failed' => 0, 'busy' => 1 ];
		}
		$result = [ 'processed' => 0, 'resolved' => 0, 'failed' => 0 ];
		foreach ( $claims as $id => $row ) {
			++$result['processed'];
			if ( 'local_cleanup' === ( $row['action'] ?? '' ) ) {
				$success = $this->cleanup_revoked_spoke( (string) $row['link_id'], $now, '', (string) ( $row['admin_reason_sha256'] ?? '' ) );
				$stored = $this->finish_reconcile_claim( (string) $id, (string) $row['claim_id'], $now, $success ? 'resolved' : 'local_cleanup_failed' );
				if ( $stored && $success ) {
					++$result['resolved'];
				} else {
					if ( ! $stored ) { $result['storage_error'] = 1; }
					++$result['failed'];
				}
				continue;
			}
			$credential = CredentialEnvelope::decrypt( (string) $row['credential_ciphertext'], (string) $row['link_id'], (string) $row['hub_origin'] );
			$response = '' === $credential ? new \WP_Error( 'wpbridge_reconcile_key_unavailable' ) : call_user_func( $transport, (string) $row['hub_origin'] . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( (string) $row['link_id'] ) . '/acceptance-compensations', [ 'method' => 'POST', 'timeout' => 15, 'redirection' => 0, 'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'WPBridge-Link ' . $credential ], 'body' => wp_json_encode( [ 'reason' => (string) ( $row['reason'] ?? 'spoke_storage_failed' ) ] ) ] );
			if ( ! is_wp_error( $response ) && 204 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$local_failed = 'unlink_local' === ( $row['action'] ?? '' ) && ! $this->finalize_remote_revoke( (string) $row['link_id'], $now, '', (string) ( $row['admin_reason_sha256'] ?? '' ) );
				$outcome      = $local_failed ? 'local_cleanup_failed' : 'resolved';
				if ( ! $this->finish_reconcile_claim( (string) $id, (string) $row['claim_id'], $now, $outcome ) ) {
					$result['storage_error'] = 1;
					++$result['failed'];
					continue;
				}
				if ( $local_failed ) {
					++$result['failed'];
				} else {
					++$result['resolved'];
				}
				continue;
			}
			if ( ! $this->finish_reconcile_claim( (string) $id, (string) $row['claim_id'], $now, 'remote_revoke_failed' ) ) {
				$result['storage_error'] = 1;
			}
			++$result['failed'];
		}
		if ( $result['resolved'] > 0 && ! $this->audit( 'spoke.reconcile.resolved', 'batch', $now ) ) {
			$result['audit_error'] = 1;
		}
		if ( [] === $this->state_option( self::RECONCILE ) ) {
			$this->release_spoke_reservation();
		}
		return $result;
	}

	/** Claim due rows under the network lock, then release it before outbound I/O. @return array<string,array<string,mixed>>|\WP_Error */
	private function claim_reconciles( int $now ) {
		$lock = $this->acquire_lock( 'reconcile' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$rows   = (array) $this->state_option( self::RECONCILE );
			$claims = [];
			foreach ( $rows as $id => &$row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( 'processing' === ( $row['reconcile_state'] ?? '' ) && $now >= (int) ( $row['claim_until'] ?? 0 ) ) {
					$row['reconcile_state'] = 'pending';
					unset( $row['claim_id'], $row['claim_until'] );
				}
				if ( 'pending' !== ( $row['reconcile_state'] ?? '' ) || $now < (int) ( $row['next_attempt_at'] ?? 0 ) ) {
					continue;
				}
				$row['reconcile_state'] = 'processing';
				$row['claim_id']        = InstallationIdentity::base64url( random_bytes( 18 ) );
				$row['claim_until']     = $now + 300;
				$claims[ (string) $id ] = $row;
			}
			unset( $row );
			if ( [] !== $claims && ! $this->save( self::RECONCILE, $rows ) ) {
				return new \WP_Error( 'wpbridge_reconcile_claim_failed' );
			}
			return $claims;
		} finally {
			$this->release_lock( 'reconcile' );
		}
	}

	/** Persist one claimed row outcome without overwriting rows enqueued by another worker. */
	private function finish_reconcile_claim( string $id, string $claim_id, int $now, string $outcome ): bool {
		$lock = $this->acquire_lock( 'reconcile' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$rows = (array) $this->state_option( self::RECONCILE );
			if ( ! isset( $rows[ $id ] ) || ! is_array( $rows[ $id ] ) || ! hash_equals( $claim_id, (string) ( $rows[ $id ]['claim_id'] ?? '' ) ) ) {
				return false;
			}
			if ( 'resolved' === $outcome ) {
				unset( $rows[ $id ] );
				return $this->save( self::RECONCILE, $rows );
			}
			$row = $rows[ $id ];
			$row['reconcile_state'] = 'pending';
			$row['attempts']        = (int) ( $row['attempts'] ?? 0 ) + 1;
			$row['last_attempt_at'] = $now;
			$row['error']           = $outcome;
			if ( 'local_cleanup_failed' === $outcome ) {
				$row['action']                = 'local_cleanup';
				$row['credential_ciphertext'] = '';
				$row['next_attempt_at']       = $now + 120;
			} else {
				$row['next_attempt_at'] = $now + min( 3600, 60 * ( 2 ** min( 6, $row['attempts'] ) ) );
			}
			unset( $row['claim_id'], $row['claim_until'] );
			$rows[ $id ] = $row;
			return $this->save( self::RECONCILE, $rows );
		} finally {
			$this->release_lock( 'reconcile' );
		}
	}

	public function reconcile_statuses(): array {
		$output = [];
		foreach ( $this->state_option( self::RECONCILE ) as $row ) {
			if ( is_array( $row ) ) {
				unset( $row['credential_ciphertext'] );
				unset( $row['admin_reason_sha256'] );
				unset( $row['claim_id'], $row['claim_until'] );
				if ( 'processing' === ( $row['reconcile_state'] ?? '' ) ) {
					$row['reconcile_state'] = 'pending';
				}
				$output[] = $row;
			}
		}
		foreach ( $this->state_option( self::UNCERTAIN ) as $row ) {
			if ( is_array( $row ) ) {
				unset( $row['invitation_token_sha256'] );
				$row['kind'] = 'uncertain_accept';
				$output[] = $row;
			}
		}
		return $output;
	}

	public function save_uncertain_accept( string $origin, string $invitation_id, string $token, int $now ): bool {
		$rows = $this->state_option( self::UNCERTAIN );
		$rows[ $invitation_id ] = [ 'invitation_id' => $invitation_id, 'hub_origin' => $origin, 'invitation_token_sha256' => hash( 'sha256', $token ), 'spoke_installation_uuid' => InstallationIdentity::uuid(), 'reconcile_state' => 'manual_recovery', 'created_at' => $now ];
		return $this->save( self::UNCERTAIN, $rows );
	}

	public function clear_uncertain_accept( string $invitation_id ): bool {
		$rows = $this->state_option( self::UNCERTAIN );
		unset( $rows[ $invitation_id ] );
		return $this->save( self::UNCERTAIN, $rows );
	}

	/** Clear only after an administrator has confirmed that the Hub orphan is revoked. */
	public function resolve_uncertain_accept( string $invitation_id, int $now, string $reason, string $resolution = 'hub_link_revoked' ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$rows = $this->state_option( self::UNCERTAIN );
			if ( ! isset( $rows[ $invitation_id ] ) ) {
				return false;
			}
			if ( ! in_array( $resolution, [ 'hub_link_revoked', 'local_link_active' ], true ) || ( 'local_link_active' === $resolution && ! $this->has_active_spoke_link() ) ) {
				return false;
			}
			$previous = $rows;
			$previous_role = $this->state_option( self::ROLE );
			unset( $rows[ $invitation_id ] );
			if ( ! $this->save( self::UNCERTAIN, $rows ) ) {
				return false;
			}
			if ( 'spoke-reserved' === ( $previous_role['role'] ?? '' ) && ! $this->save( self::ROLE, [] ) ) {
				$this->save( self::UNCERTAIN, $previous );
				return false;
			}
			if ( ! $this->audit( 'spoke.uncertain_accept.resolved', $invitation_id, $now, $reason, 'admin' ) ) {
				$this->save( self::UNCERTAIN, $previous );
				$this->save( self::ROLE, $previous_role );
				return false;
			}
			return true;
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	public function sweep_expired_invitations( ?int $now = null ): void {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return;
		}
		try {
			$this->expire_invitations( $now ?? time() );
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	/** Whether this installation is already an active Spoke. */
	public function has_active_spoke_link(): bool {
		foreach ( (array) $this->state_option( self::SPOKE_LINKS ) as $row ) {
			if ( is_array( $row ) && 'active' === ( $row['status'] ?? '' ) && ! empty( $row['credential_ciphertext'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether this network already owns pending or active Hub lifecycle state. */
	public function has_active_hub_state( ?int $now = null ): bool {
		$now = $now ?? time();
		foreach ( $this->invitations() as $row ) {
			if ( is_array( $row ) && 'pending' === ( $row['status'] ?? '' ) && $now < (int) ( $row['expires_at'] ?? 0 ) ) {
				return true;
			}
		}
		foreach ( $this->links() as $row ) {
			if ( is_array( $row ) && 'active' === ( $row['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,mixed>|null */
	public function active_spoke_link( string $link_id = '' ): ?array {
		foreach ( (array) $this->state_option( self::SPOKE_LINKS ) as $id => $row ) {
			if ( is_array( $row ) && 'active' === ( $row['status'] ?? '' ) && ( '' === $link_id || hash_equals( $link_id, (string) $id ) ) ) {
				$row['credential'] = CredentialEnvelope::decrypt( (string) $row['credential_ciphertext'], (string) $row['link_id'], (string) $row['hub_origin'] );
				return '' === $row['credential'] ? null : $row;
			}
		}
		return null;
	}

	/** Apply a one-time rotated credential on the Spoke. */
	public function apply_spoke_rotation( string $link_id, string $credential, int $now ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) || ! self::valid_link_credential( $credential ) ) {
			return false;
		}
		try {
			$rows = (array) $this->state_option( self::SPOKE_LINKS );
			$previous = $rows;
			$row  = $rows[ $link_id ] ?? null;
			if ( ! is_array( $row ) || 'active' !== $row['status'] ) {
				return false;
			}
			$ciphertext = CredentialEnvelope::encrypt( $credential, $link_id, (string) $row['hub_origin'] );
			if ( '' === $ciphertext ) {
				return false;
			}
			$row['credential_ciphertext'] = $ciphertext;
			$row['key_version'] = CredentialEnvelope::version( $ciphertext );
			$row['rotated_at'] = $now;
			$rows[ $link_id ] = $row;
			if ( ! $this->save( self::SPOKE_LINKS, $rows ) || ! $this->audit( 'spoke.link.rotated', $link_id, $now ) ) {
				$this->save( self::SPOKE_LINKS, $previous );
				return false;
			}
			return true;
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	public function unlink_spoke( string $link_id, int $now ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$rows = (array) $this->state_option( self::SPOKE_LINKS );
			$previous_rows = $rows;
			$previous_sources = $this->runtime_option( 'wpbridge_source_registry' );
			$previous_role = $this->state_option( self::ROLE );
			if ( ! isset( $rows[ $link_id ] ) ) {
				return false;
			}
			$rows[ $link_id ]['credential_ciphertext'] = '';
			$rows[ $link_id ]['status'] = 'revoked';
			$rows[ $link_id ]['revoked_at'] = $now;
			if ( ! $this->save( self::SPOKE_LINKS, $rows ) ) {
				return false;
			}
			if ( ! $this->disable_spoke_sources( $link_id ) ) {
				$links_rolled_back = $this->save( self::SPOKE_LINKS, $previous_rows );
				$sources_rolled_back = $this->save( 'wpbridge_source_registry', $previous_sources );
				$rolled_back = $links_rolled_back && $sources_rolled_back;
				if ( ! $rolled_back ) {
					$this->mark_spoke_inconsistent( $link_id, $now );
				}
				return false;
			}
			if ( ! $this->save( self::ROLE, [] ) ) {
				$this->save( self::SPOKE_LINKS, $previous_rows );
				$this->save( 'wpbridge_source_registry', $previous_sources );
				return false;
			}
			if ( ! $this->audit( 'spoke.link.unlinked', $link_id, $now ) ) {
				$this->save( self::SPOKE_LINKS, $previous_rows );
				$this->save( 'wpbridge_source_registry', $previous_sources );
				$this->save( self::ROLE, $previous_role );
				return false;
			}
			return true;
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	private function mark_spoke_remote_revoked( string $link_id, int $now ): bool {
		$rows = $this->state_option( self::SPOKE_LINKS );
		if ( ! isset( $rows[ $link_id ] ) ) { return false; }
		$rows[ $link_id ]['credential_ciphertext'] = '';
		$rows[ $link_id ]['status'] = 'revoked';
		$rows[ $link_id ]['revoked_at'] = $now;
		return $this->save( self::SPOKE_LINKS, $rows );
	}

	/** Remote authority is gone: wipe first, then cleanup without restoring credential. */
	public function finalize_remote_revoke( string $link_id, int $now, string $admin_reason = '', string $admin_reason_hash = '' ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) { return false; }
		try {
			if ( ! $this->mark_spoke_remote_revoked( $link_id, $now ) ) { return false; }
			return $this->cleanup_revoked_spoke_locked( $link_id, $now, $admin_reason, $admin_reason_hash );
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	private function cleanup_revoked_spoke( string $link_id, int $now, string $reason = '', string $reason_hash = '' ): bool {
		$lock = $this->acquire_lock( 'hub-lifecycle' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			return $this->cleanup_revoked_spoke_locked( $link_id, $now, $reason, $reason_hash );
		} finally {
			$this->release_lock( 'hub-lifecycle' );
		}
	}

	private function cleanup_revoked_spoke_locked( string $link_id, int $now, string $reason = '', string $reason_hash = '' ): bool {
		return $this->disable_spoke_sources( $link_id )
			&& $this->save( self::ROLE, [] )
			&& $this->audit( 'spoke.link.unlinked', $link_id, $now, $reason, 'admin', $reason_hash );
	}

	/** @return array<int,array<string,mixed>> */
	public function spoke_statuses(): array {
		$output = [];
		foreach ( (array) $this->state_option( self::SPOKE_LINKS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			unset( $row['credential_ciphertext'] );
			$output[] = $row;
		}
		return $output;
	}

	/** @return array<string,array<string,mixed>> */
	private function invitations(): array {
		return (array) $this->state_option( self::INVITATIONS );
	}

	/** @return array<string,array<string,mixed>> */
	private function links(): array {
		return (array) $this->state_option( self::LINKS );
	}

	private function save( string $option, array $value ): bool {
		if ( in_array( $option, [ self::INVITATIONS, self::LINKS, self::SPOKE_LINKS, self::AUDIT, self::RATE, self::RECONCILE, self::ROLE, self::ORIGIN, self::UNCERTAIN ], true ) ) {
			return is_multisite() ? update_site_option( $option, $value ) || $value === get_site_option( $option, [] ) : update_option( $option, $value, false ) || $value === get_option( $option, [] );
		}
		if ( in_array( $option, [ 'wpbridge_source_registry', 'wpbridge_defaults' ], true ) ) {
			return $this->save_runtime_option( $option, $value );
		}
		return update_option( $option, $value, false ) || $value === get_option( $option, [] );
	}

	/** Network-scoped installation state; single-site keeps the ordinary option table. */
	private function state_option( string $option ): array {
		return (array) ( is_multisite() ? get_site_option( $option, [] ) : get_option( $option, [] ) );
	}

	private function provision_spoke_sources( string $link_id, string $origin, array $slugs ): bool {
		$sources = $this->runtime_option( 'wpbridge_source_registry' );
		$previous_sources = $sources;
		$previous_defaults = $this->runtime_option( 'wpbridge_defaults' );
		$source_keys = [];
		foreach ( $slugs as $slug ) {
			$id = 'hub-spoke-' . substr( str_replace( '-', '', $link_id ), 0, 12 ) . '-' . $slug;
			$source_keys[] = $id;
			$found = false;
			foreach ( $sources as &$source ) {
				if ( is_array( $source ) && ( $source['source_key'] ?? '' ) === $id ) {
					$source['enabled'] = true;
					$found = true;
					break;
				}
			}
			unset( $source );
			if ( ! $found ) {
				$sources[] = [ 'source_key' => $id, 'name' => 'WPBridge Hub: ' . $slug, 'type' => 'hub_spoke', 'api_url' => $origin, 'slug' => $slug, 'enabled' => true, 'default_priority' => 5, 'auth_type' => 'none', 'auth_secret_ref' => '', 'signature_required' => true, 'artifact_public_keys' => [], 'metadata' => [ 'spoke_link_id' => $link_id ] ];
			}
		}
		$saved = $this->save_runtime_option( 'wpbridge_source_registry', $sources );
		$defaults = $previous_defaults;
		$plugin = is_array( $defaults['plugin'] ?? null ) ? $defaults['plugin'] : [];
		$order = array_values( array_unique( array_merge( $source_keys, (array) ( $plugin['source_order'] ?? [ 'wporg' ] ) ) ) );
		$plugin['source_order'] = $order;
		$plugin['fallback_to_wporg'] = true;
		$defaults['plugin'] = $plugin;
		if ( ! $saved ) {
			return false;
		}
		if ( ! $this->save( 'wpbridge_defaults', $defaults ) ) {
			$this->save( 'wpbridge_source_registry', $previous_sources );
			$this->save( 'wpbridge_defaults', $previous_defaults );
			return false;
		}
		return true;
	}

	private function disable_spoke_sources( string $link_id ): bool {
		$rows = (array) $this->state_option( self::SPOKE_LINKS );
		$runtime_blog_id = (int) ( $rows[ $link_id ]['runtime_blog_id'] ?? 0 );
		$switched = is_multisite() && $runtime_blog_id > 0 && get_current_blog_id() !== $runtime_blog_id;
		if ( $switched ) {
			switch_to_blog( $runtime_blog_id );
		}
		try {
		$sources = $this->runtime_option( 'wpbridge_source_registry' );
		foreach ( $sources as &$source ) {
			if ( is_array( $source ) && 'hub_spoke' === ( $source['type'] ?? '' ) && $link_id === ( $source['metadata']['spoke_link_id'] ?? '' ) ) {
				$source['enabled'] = false;
			}
		}
		unset( $source );
		return $this->save_runtime_option( 'wpbridge_source_registry', $sources );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	private function unlink_spoke_state_locked( string $link_id, array $previous_rows, int $now ): void {
		$this->save( self::SPOKE_LINKS, $previous_rows );
		$this->disable_spoke_sources( $link_id );
		$this->save( self::ROLE, [ 'role' => 'spoke-reserved', 'expires_at' => $now + 300, 'updated_at' => $now ] );
	}

	private function runtime_option( string $name ): array {
		return (array) get_option( $name, [] );
	}

	private function save_runtime_option( string $name, array $value ): bool {
		return update_option( $name, $value, false ) || $value === get_option( $name, [] );
	}

	private function cleanup_expired_role_locked( int $now ): void {
		$role = $this->state_option( self::ROLE );
		if ( 'spoke-reserved' === ( $role['role'] ?? '' ) && $now >= (int) ( $role['expires_at'] ?? 0 ) ) { $this->save( self::ROLE, [] ); }
	}

	/** @return true|\WP_Error */
	private function acquire_lock( string $domain ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new \WP_Error( 'wpbridge_hub_store_lock_unavailable', __( 'Hub link 持久锁不可用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$name = 'wpbridge:' . substr( hash( 'sha256', $domain . ':' . InstallationIdentity::uuid() ), 0, 48 );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare is required and performed immediately above.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		return '1' === (string) $result ? true : new \WP_Error( 'wpbridge_hub_store_busy', __( 'Hub link 状态正在更新。', 'wpbridge' ), [ 'status' => 503 ] );
	}

	private function release_lock( string $domain ): void {
		global $wpdb;
		$name = 'wpbridge:' . substr( hash( 'sha256', $domain . ':' . InstallationIdentity::uuid() ), 0, 48 );
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared advisory lock release.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	private function audit( string $action, string $resource, int $now, string $reason = '', string $actor = 'system', string $reason_hash = '' ): bool {
		$reason_code = in_array( $reason, [ 'spoke_storage_failed', 'spoke_unlink', 'admin_rotation', 'admin_revoke' ], true ) ? $reason : ( '' === $reason ? 'unspecified' : 'admin_supplied' );
		return $this->append_audits( [ [ 'action' => $action, 'resource_sha256' => hash( 'sha256', $resource ), 'reason_code' => $reason_code, 'reason_sha256' => '' !== $reason_hash ? $reason_hash : ( '' === $reason ? '' : hash( 'sha256', $reason ) ), 'actor' => in_array( $actor, [ 'admin', 'link', 'system' ], true ) ? $actor : 'system', 'occurred_at' => $now, 'user_id' => 'admin' === $actor ? get_current_user_id() : 0 ] ] );
	}

	/** Append one lifecycle batch without permitting another writer's audit rows to be overwritten. */
	private function append_audits( array $events ): bool {
		$lock = $this->acquire_lock( 'audit' );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$rows = (array) $this->state_option( self::AUDIT );
			foreach ( $events as $event ) {
				if ( is_array( $event ) ) {
					$rows[] = $event;
				}
			}
			return $this->save( self::AUDIT, array_slice( $rows, -500 ) );
		} finally {
			$this->release_lock( 'audit' );
		}
	}

	private function mark_spoke_inconsistent( string $link_id, int $now ): void {
		$rows = (array) $this->state_option( self::SPOKE_LINKS );
		if ( isset( $rows[ $link_id ] ) ) {
			$rows[ $link_id ]['status'] = 'revoked';
			$rows[ $link_id ]['error'] = 'storage_inconsistent';
			$rows[ $link_id ]['credential_ciphertext'] = '';
			$rows[ $link_id ]['failed_at'] = $now;
			$this->save( self::SPOKE_LINKS, $rows );
		}
	}

	private function expire_invitations( int $now ): void {
		$rows = $this->invitations();
		$previous = $rows;
		$changed = false;
		$expired_ids = [];
		foreach ( $rows as $expired_id => &$row ) {
			if ( is_array( $row ) && 'pending' === ( $row['status'] ?? '' ) && $now >= (int) ( $row['expires_at'] ?? 0 ) ) {
				$row['status'] = 'expired';
				$row['token_sha256'] = '';
				$row['expired_at'] = $now;
				$changed = true;
				$expired_ids[] = (string) $expired_id;
			}
		}
		unset( $row );
		$state_ok = ! $changed || $this->save( self::INVITATIONS, $rows );
		$audit_events = [];
		foreach ( $expired_ids as $expired_id ) {
			$audit_events[] = [ 'action' => 'invitation.expired', 'resource_sha256' => hash( 'sha256', $expired_id ), 'reason_code' => 'unspecified', 'reason_sha256' => '', 'actor' => 'system', 'occurred_at' => $now, 'user_id' => 0 ];
		}
		$audit_ok = ! $state_ok || [] === $audit_events || $this->append_audits( $audit_events );
		if ( $changed && ( ! $state_ok || ! $audit_ok ) ) {
			if ( ! $this->save( self::INVITATIONS, $previous ) ) {
				foreach ( $rows as &$failed_row ) {
					if ( is_array( $failed_row ) && 'expired' === ( $failed_row['status'] ?? '' ) ) {
						$failed_row['error'] = 'storage_inconsistent';
					}
				}
				unset( $failed_row );
				$this->save( self::INVITATIONS, $rows );
			}
		}
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

	private static function identity_ready(): bool {
		if ( ! InstallationIdentity::ensure() || '' === InstallationIdentity::uuid() ) {
			return false;
		}
		$key = InstallationIdentity::base64url_decode( InstallationIdentity::public_key() );
		return is_string( $key ) && defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $key );
	}

	private static function identity_error(): \WP_Error {
		return new \WP_Error( 'wpbridge_hub_identity_unavailable', __( 'Hub installation identity 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
	}
}
