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
		if ( self::has_local_upstream_credentials() ) {
			return new \WP_Error( 'wpbridge_spoke_credentials_present', __( 'Spoke 仍保存上游或设备凭据，清除后才能接受 Hub link。', 'wpbridge' ) );
		}
		$origin = self::allowed_origin( $hub_url );
		if ( is_wp_error( $origin ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $invitation_id ) || ! HubSpokeStore::valid_invitation_token( $invitation_token ) ) {
			return is_wp_error( $origin ) ? $origin : new \WP_Error( 'wpbridge_spoke_accept_invalid', __( 'Hub 邀请参数无效。', 'wpbridge' ) );
		}
		$challenge = $this->json_request(
			$origin . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( $invitation_id ) . '/challenge',
			[ 'invitation_token' => $invitation_token ]
		);
		if ( is_wp_error( $challenge ) || ! self::exact_keys( $challenge, [ 'invitation_id', 'hub_installation_uuid', 'scopes', 'slug_allowlist', 'expires_at' ] ) || $challenge['invitation_id'] !== $invitation_id ) {
			return is_wp_error( $challenge ) ? $challenge : new \WP_Error( 'wpbridge_hub_challenge_invalid', __( 'Hub 邀请响应无效。', 'wpbridge' ) );
		}
		if ( ! InstallationIdentity::ensure() ) {
			return new \WP_Error( 'wpbridge_link_key_unavailable', __( '安装身份密钥不可用。', 'wpbridge' ) );
		}
		$nonce     = InstallationIdentity::base64url( random_bytes( 32 ) );
		$timestamp = (string) call_user_func( $this->clock );
		$key       = InstallationIdentity::base64url_decode( InstallationIdentity::public_key() );
		if ( ! is_string( $key ) ) {
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
		$response = $this->json_request(
			$origin . '/wp-json/wpbridge/v2/hub-links/' . rawurlencode( $invitation_id ) . '/acceptances',
			$request
		);
		if ( is_wp_error( $response ) || ! self::exact_keys( $response, [ 'link_id', 'link_credential', 'scopes', 'slug_allowlist', 'expires_at' ] ) || ! is_null( $response['expires_at'] ) ) {
			return is_wp_error( $response ) ? $response : new \WP_Error( 'wpbridge_hub_acceptance_invalid', __( 'Hub link 响应无效。', 'wpbridge' ) );
		}
		$policy = HubSpokeStore::normalize_policy( (array) $response['scopes'], (array) $response['slug_allowlist'] );
		if ( is_wp_error( $policy ) || $policy['scopes'] !== $challenge['scopes'] || $policy['slug_allowlist'] !== $challenge['slug_allowlist'] ) {
			return new \WP_Error( 'wpbridge_hub_policy_mismatch', __( 'Hub link 权限与邀请不一致。', 'wpbridge' ) );
		}
		$store = new HubSpokeStore();
		if ( ! $store->save_spoke_link( $origin, $response, (int) call_user_func( $this->clock ) ) ) {
			return new \WP_Error( 'wpbridge_spoke_storage_failed', __( 'Spoke credential 无法安全保存。', 'wpbridge' ) );
		}
		unset( $response['link_credential'] );
		return $response;
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
		foreach ( (array) get_option( 'wpbridge_sources', [] ) as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			if ( ! empty( $source['auth_token'] ) ) {
				return true;
			}
			$metadata = is_array( $source['metadata'] ?? null ) ? $source['metadata'] : [];
			foreach ( [ 'update_private_key', 'update_device_id', 'license_key', 'api_key', 'access_token' ] as $key ) {
				if ( ! empty( $metadata[ $key ] ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
