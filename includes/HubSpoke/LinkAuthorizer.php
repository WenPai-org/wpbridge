<?php
/**
 * Per-route Hub link credential authorization.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rejects query credentials and enforces link, scope, slug and rate boundaries. */
final class LinkAuthorizer {
	private HubSpokeStore $store;

	/** @var array<string,mixed>|null */
	private ?array $authorized_link = null;

	public function __construct( HubSpokeStore $store ) {
		$this->store = $store;
	}

	/** @return true|\WP_Error */
	public function authorize( \WP_REST_Request $request, string $scope, bool $slug_required = true ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$query = $request->get_query_params();
		if ( isset( $query['api_key'] ) || isset( $query['link_credential'] ) || isset( $query['authorization'] ) ) {
			return new \WP_Error( 'wpbridge_query_credential_forbidden', __( '凭据不得通过 query 传递。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		$authorization = (string) $request->get_header( 'Authorization' );
		if ( 1 !== preg_match( '/^WPBridge-Link (WPBL1-[A-Za-z0-9_-]{43})$/', $authorization, $match ) ) {
			return new \WP_Error( 'wpbridge_link_credential_missing', __( '缺少 Hub link credential。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		$link = $this->store->authorize( $match[1], time() );
		if ( null === $link ) {
			return new \WP_Error( 'wpbridge_link_credential_invalid', __( 'Hub link credential 无效。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		if ( ! in_array( $scope, (array) $link['scopes'], true ) ) {
			return new \WP_Error( 'wpbridge_link_scope_forbidden', __( 'Hub link scope 不允许此路由。', 'wpbridge' ), [ 'status' => 403 ] );
		}
		$slug = (string) $request->get_param( 'slug' );
		if ( $slug_required && ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $slug ) || ! in_array( $slug, (array) $link['slug_allowlist'], true ) ) ) {
			return new \WP_Error( 'wpbridge_link_resource_not_found', __( '资源不存在。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		if ( ! $this->within_rate_limit( (string) $link['link_id'] ) ) {
			return new \WP_Error( 'wpbridge_link_rate_limited', __( 'Hub link 请求过于频繁。', 'wpbridge' ), [ 'status' => 429 ] );
		}
		$this->authorized_link = $link;
		return true;
	}

	/** @return array<string,mixed>|null */
	public function current_link(): ?array {
		return $this->authorized_link;
	}

	public static function enabled(): bool {
		return defined( 'WPBRIDGE_HUB_SPOKE_ENABLED' ) && true === WPBRIDGE_HUB_SPOKE_ENABLED;
	}

	private function within_rate_limit( string $link_id ): bool {
		$limit  = defined( 'WPBRIDGE_HUB_SPOKE_RATE_LIMIT' ) ? max( 1, min( 600, (int) WPBRIDGE_HUB_SPOKE_RATE_LIMIT ) ) : 60;
		return $this->store->consume_rate( $link_id, (int) floor( time() / 60 ), $limit );
	}
}
