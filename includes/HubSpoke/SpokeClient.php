<?php
/**
 * Safe Spoke-to-Hub invitation acceptance client.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

use WPBridge\Security\SafeHttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Performs proof-of-possession acceptance without persisting the invitation token. */
final class SpokeClient {
	/** @var callable */
	private $transport;

	/** @var callable */
	private $clock;

	public function __construct( ?callable $transport = null, ?callable $clock = null ) {
		$this->transport = $transport ?? [ SafeHttpClient::class, 'request' ];
		$this->clock     = $clock ?? 'time';
	}

	/** @return array<string,mixed>|\WP_Error */
	public function accept( string $hub_url, string $invitation_id, string $invitation_token ) {
		$store = new HubSpokeStore();
		if ( $store->has_active_hub_state( (int) call_user_func( $this->clock ) ) ) {
			return new \WP_Error( 'wpbridge_hub_cannot_be_spoke', __( '存在 pending invitation 或 active Hub link 时不能接受 Spoke link。', 'wpbridge' ) );
		}
		if ( self::has_local_upstream_credentials() ) {
			return new \WP_Error( 'wpbridge_spoke_credentials_present', __( 'Spoke 仍保存上游或设备凭据，清除后才能接受 Hub link。', 'wpbridge' ) );
		}
		$origin = self::allowed_origin( $hub_url );
		if ( is_wp_error( $origin ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $invitation_id ) || ! HubSpokeStore::valid_invitation_token( $invitation_token ) ) {
			return is_wp_error( $origin ) ? $origin : new \WP_Error( 'wpbridge_spoke_accept_invalid', __( 'Hub 邀请参数无效。', 'wpbridge' ) );
		}
		if ( ! $store->reserve_spoke_role( (int) call_user_func( $this->clock ) ) ) {
			return new \WP_Error( 'wpbridge_spoke_role_busy', __( 'Hub-Spoke installation role 正在变更。', 'wpbridge' ) );
		}
		$challenge = $this->json_request(
			$origin . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( $invitation_id ) . '/challenge',
			[ 'invitation_token' => $invitation_token ]
		);
		if ( is_wp_error( $challenge ) || ! self::exact_keys( $challenge, [ 'invitation_id', 'hub_installation_uuid', 'hub_public_key_fingerprint', 'scopes', 'slug_allowlist', 'expires_at' ] ) || $challenge['invitation_id'] !== $invitation_id || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $challenge['hub_public_key_fingerprint'] ) ) {
			$store->release_spoke_reservation();
			return is_wp_error( $challenge ) ? $challenge : new \WP_Error( 'wpbridge_hub_challenge_invalid', __( 'Hub 邀请响应无效。', 'wpbridge' ) );
		}
		if ( ! InstallationIdentity::ensure() ) {
			$store->release_spoke_reservation();
			return new \WP_Error( 'wpbridge_link_key_unavailable', __( '安装身份密钥不可用。', 'wpbridge' ) );
		}
		$nonce     = InstallationIdentity::base64url( random_bytes( 32 ) );
		$timestamp = (string) call_user_func( $this->clock );
		$key       = InstallationIdentity::base64url_decode( InstallationIdentity::public_key() );
		if ( ! is_string( $key ) ) {
			$store->release_spoke_reservation();
			return new \WP_Error( 'wpbridge_link_key_unavailable', __( '安装身份密钥不可用。', 'wpbridge' ) );
		}
		$canonical = "WPBRIDGE-HUB-LINK-ACCEPT-V1\n"
			. 'invitation_id:' . $invitation_id . "\n"
			. 'invitation_token_sha256:' . hash( 'sha256', $invitation_token ) . "\n"
			. 'hub_installation_uuid:' . $challenge['hub_installation_uuid'] . "\n"
			. 'spoke_installation_uuid:' . InstallationIdentity::uuid() . "\n"
			. 'spoke_public_key_sha256:' . hash( 'sha256', $key ) . "\n"
			. 'nonce:' . $nonce . "\n"
			. 'timestamp:' . $timestamp . "\n";
		$signature = InstallationIdentity::sign( $canonical );
		if ( is_wp_error( $signature ) ) {
			$store->release_spoke_reservation();
			return $signature;
		}
		$request = [
			'invitation_token'       => $invitation_token,
			'spoke_installation_uuid' => InstallationIdentity::uuid(),
			'spoke_public_key'       => InstallationIdentity::public_key(),
			'nonce'                  => $nonce,
			'timestamp'              => $timestamp,
			'signature'              => $signature,
		];
		if ( ! $store->save_uncertain_accept( $origin, $invitation_id, $invitation_token, (int) call_user_func( $this->clock ) ) ) {
			$store->release_spoke_reservation();
			return new \WP_Error( 'wpbridge_spoke_reconcile_storage_failed', __( '无法持久记录 acceptance recovery 状态。', 'wpbridge' ) );
		}
		$response = $this->json_request(
			$origin . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( $invitation_id ) . '/acceptances',
			$request
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$compensable = isset( $response['link_id'], $response['link_credential'] ) && is_string( $response['link_id'] ) && is_string( $response['link_credential'] ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f-]{27}$/', $response['link_id'] ) && HubSpokeStore::valid_link_credential( $response['link_credential'] );
		if ( ! self::exact_keys( $response, [ 'link_id', 'link_credential', 'scopes', 'slug_allowlist', 'expires_at' ] ) || ! is_null( $response['expires_at'] ) ) {
			$compensated = $compensable && $this->compensate_acceptance( $origin, $response['link_id'], $response['link_credential'] );
			if ( $compensable && ! $compensated ) {
				if ( ! $store->save_reconcile( $origin, $response['link_id'], $response['link_credential'], (int) call_user_func( $this->clock ) ) ) {
					return new \WP_Error( 'wpbridge_spoke_reconcile_storage_failed', __( '无法持久记录 acceptance compensation。', 'wpbridge' ), [ 'status' => 503 ] );
				}
			}
			if ( $compensated ) {
				$store->clear_uncertain_accept( $invitation_id );
				$store->release_spoke_reservation();
			}
			return new \WP_Error( 'wpbridge_hub_acceptance_invalid', __( 'Hub link 响应无效。', 'wpbridge' ) );
		}
		$policy = HubSpokeStore::normalize_policy( (array) $response['scopes'], (array) $response['slug_allowlist'] );
		if ( is_wp_error( $policy ) || $policy['scopes'] !== $challenge['scopes'] || $policy['slug_allowlist'] !== $challenge['slug_allowlist'] ) {
			if ( ! $this->compensate_acceptance( $origin, (string) $response['link_id'], (string) $response['link_credential'] ) ) {
				if ( ! $store->save_reconcile( $origin, (string) $response['link_id'], (string) $response['link_credential'], (int) call_user_func( $this->clock ) ) ) {
					return new \WP_Error( 'wpbridge_spoke_reconcile_storage_failed', __( '无法持久记录 acceptance compensation。', 'wpbridge' ), [ 'status' => 503 ] );
				}
			} else {
				$store->clear_uncertain_accept( $invitation_id );
				$store->release_spoke_reservation();
			}
			return new \WP_Error( 'wpbridge_hub_policy_mismatch', __( 'Hub link 权限与邀请不一致。', 'wpbridge' ) );
		}
		$response['hub_public_key_fingerprint'] = $challenge['hub_public_key_fingerprint'];
		if ( ! $store->save_spoke_link( $origin, $response, (int) call_user_func( $this->clock ) ) ) {
			$compensated = $this->compensate_acceptance( $origin, (string) $response['link_id'], (string) $response['link_credential'] );
			if ( ! $compensated ) {
				if ( ! $store->save_reconcile( $origin, (string) $response['link_id'], (string) $response['link_credential'], (int) call_user_func( $this->clock ) ) ) {
					return new \WP_Error( 'wpbridge_spoke_reconcile_storage_failed', __( '无法持久记录 acceptance compensation。', 'wpbridge' ), [ 'status' => 503 ] );
				}
			} else {
				$store->clear_uncertain_accept( $invitation_id );
				$store->release_spoke_reservation();
			}
			return new \WP_Error( $compensated ? 'wpbridge_spoke_storage_failed' : 'wpbridge_spoke_reconcile_required', __( 'Spoke credential 无法安全保存，Hub link 已撤销或需要管理员核对。', 'wpbridge' ) );
		}
		if ( ! $store->clear_uncertain_accept( $invitation_id ) ) {
			return new \WP_Error( 'wpbridge_spoke_recovery_clear_failed', __( 'Spoke link 已保存，但 acceptance recovery 状态无法清理。', 'wpbridge' ) );
		}
		unset( $response['link_credential'] );
		return $response;
	}

	private function compensate_acceptance( string $origin, string $link_id, string $credential ): bool {
		$json = wp_json_encode( [ 'reason' => 'spoke_storage_failed' ] );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$response = call_user_func(
			$this->transport,
			$origin . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( $link_id ) . '/acceptance-compensations',
			[
				'method' => 'POST', 'timeout' => 15, 'redirection' => 0,
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'WPBridge-Link ' . $credential ],
				'body' => $json,
			]
		);
		return ! is_wp_error( $response ) && 204 === (int) wp_remote_retrieve_response_code( $response );
	}

	/** Revoke the remote Hub link before wiping the only local credential copy. */
	public function unlink( string $link_id, string $admin_reason = '' ) {
		$store = new HubSpokeStore();
		$link = $store->active_spoke_link( $link_id );
		if ( null === $link ) {
			return new \WP_Error( 'wpbridge_spoke_link_not_found', __( 'Spoke link 不存在。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		$json = wp_json_encode( [ 'reason' => 'spoke_unlink' ] );
		$response = call_user_func( $this->transport, (string) $link['hub_origin'] . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( $link_id ) . '/acceptance-compensations', [ 'method' => 'POST', 'timeout' => 15, 'redirection' => 0, 'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'WPBridge-Link ' . $link['credential'] ], 'body' => $json ] );
		if ( is_wp_error( $response ) || 204 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( ! $store->save_reconcile( (string) $link['hub_origin'], $link_id, (string) $link['credential'], (int) call_user_func( $this->clock ), 'unlink_local', $admin_reason ) ) {
				return new \WP_Error( 'wpbridge_spoke_reconcile_storage_failed', __( '无法持久记录 Hub revoke 重试。', 'wpbridge' ), [ 'status' => 503 ] );
			}
			return new \WP_Error( 'wpbridge_spoke_remote_revoke_pending', __( 'Hub revoke 尚未确认，本地凭据保持并等待重试。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$now = (int) call_user_func( $this->clock );
		if ( ! $store->finalize_remote_revoke( $link_id, $now, $admin_reason ) ) {
			if ( ! $store->save_reconcile( (string) $link['hub_origin'], $link_id, (string) $link['credential'], $now, 'local_cleanup', $admin_reason ) ) {
				return new \WP_Error( 'wpbridge_spoke_reconcile_storage_failed', __( 'Hub 已撤销，但无法持久记录本地清理重试。', 'wpbridge' ), [ 'status' => 503 ] );
			}
			return new \WP_Error( 'wpbridge_spoke_local_cleanup_pending', __( 'Hub 已撤销，Spoke 本地清理等待重试。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function json_request( string $url, array $body ) {
		$json = wp_json_encode( $body );
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'wpbridge_hub_request_invalid', __( 'Hub 请求无法编码。', 'wpbridge' ) );
		}
		$response = call_user_func(
			$this->transport,
			$url,
			[
				'method'      => 'POST',
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'body'        => $json,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'wpbridge_hub_request_rejected', __( 'Hub 拒绝了请求。', 'wpbridge' ), [ 'status' => $status ] );
		}
		return $data;
	}

	/** @return string|\WP_Error */
	public static function allowed_origin( string $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( ! empty( $parts['path'] ) && '/' !== $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || 443 !== (int) ( $parts['port'] ?? 443 ) ) {
			return new \WP_Error( 'wpbridge_hub_origin_invalid', __( 'Hub origin 必须是 HTTPS 443 exact origin。', 'wpbridge' ) );
		}
		$origin  = 'https://' . strtolower( rtrim( (string) $parts['host'], '.' ) );
		$allowed = defined( 'WPBRIDGE_HUB_ORIGIN_ALLOWLIST' ) && is_array( WPBRIDGE_HUB_ORIGIN_ALLOWLIST ) ? WPBRIDGE_HUB_ORIGIN_ALLOWLIST : [];
		if ( ! in_array( $origin, $allowed, true ) ) {
			return new \WP_Error( 'wpbridge_hub_origin_forbidden', __( 'Hub origin 不在部署 allowlist。', 'wpbridge' ) );
		}
		return $origin;
	}

	private static function exact_keys( array $value, array $expected ): bool {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}

	/** A Spoke must not retain source, marketplace, pairing or device credentials. */
	private static function has_local_upstream_credentials(): bool {
		return CredentialBoundary::has_upstream_credentials();
	}
}
