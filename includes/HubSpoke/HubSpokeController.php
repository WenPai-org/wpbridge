<?php
/**
 * Stage 3A Hub-Spoke REST contract.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

use WPBridge\Core\Settings;
use WPBridge\UpdateSource\Handlers\BridgeServerHandler;
use WPBridge\UpdateSource\SourceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers local management, possession proof and least-privilege proxy routes. */
final class HubSpokeController {
	public const NAMESPACE = 'wpbridge/v2';
	public const RECONCILE_HOOK = 'wpbridge_hub_spoke_reconcile';

	private HubSpokeStore $store;
	private StepUpVerifier $step_up;
	private LinkAuthorizer $authorizer;
	private SourceManager $sources;
	/** @var array<string,string> */
	private array $package_streams = [];

	public function __construct( Settings $settings ) {
		$this->store      = new HubSpokeStore();
		$this->step_up    = new StepUpVerifier();
		$this->authorizer = new LinkAuthorizer( $this->store );
		$this->sources    = new SourceManager( $settings );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'rest_pre_serve_request', [ $this, 'serve_package' ], 10, 4 );
		add_action( self::RECONCILE_HOOK, [ $this, 'process_reconcile' ] );
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::RECONCILE_HOOK );
		}
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/step-up', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'issue_step_up' ], 'permission_callback' => '__return_true' ] );
		register_rest_route(
			self::NAMESPACE,
			'/hub-links',
			[
				[ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'create_link' ], 'permission_callback' => [ $this, 'admin_permission' ] ],
				[ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'list_links' ], 'permission_callback' => [ $this, 'admin_permission' ] ],
			]
		);
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})/challenge', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'challenge' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( self::NAMESPACE, '/hub-invitations/(?P<id>[0-9a-f-]{36})', [ 'methods' => \WP_REST_Server::DELETABLE, 'callback' => [ $this, 'cancel_invitation' ], 'permission_callback' => [ $this, 'cleanup_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})/acceptances', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'acceptance' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})/acceptance-compensations', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'acceptance_compensation' ], 'permission_callback' => [ $this, 'acceptance_compensation_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})/rotations', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'rotate_link' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})', [ 'methods' => \WP_REST_Server::DELETABLE, 'callback' => [ $this, 'revoke_link' ], 'permission_callback' => [ $this, 'cleanup_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/spoke-links/accept', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'accept_local' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/spoke-links', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'spoke_status' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/spoke-links/(?P<id>[0-9a-f-]{36})/rotations', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'apply_spoke_rotation' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/spoke-links/(?P<id>[0-9a-f-]{36})', [ 'methods' => \WP_REST_Server::DELETABLE, 'callback' => [ $this, 'unlink_spoke' ], 'permission_callback' => [ $this, 'cleanup_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/spoke-acceptances/(?P<id>[0-9a-f-]{36})/recovery', [ 'methods' => \WP_REST_Server::DELETABLE, 'callback' => [ $this, 'resolve_uncertain_accept' ], 'permission_callback' => [ $this, 'cleanup_permission' ] ] );

		register_rest_route( self::NAMESPACE, '/hub-proxy/sources', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'proxy_sources' ], 'permission_callback' => [ $this, 'sources_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-proxy/sources/(?P<slug>[a-z0-9][a-z0-9-]{1,99})', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'proxy_source' ], 'permission_callback' => [ $this, 'source_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-proxy/plugins/(?P<slug>[a-z0-9][a-z0-9-]{1,99})/metadata', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'proxy_metadata' ], 'permission_callback' => [ $this, 'updates_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-proxy/plugins/(?P<slug>[a-z0-9][a-z0-9-]{1,99})/package', [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [ $this, 'proxy_package' ], 'permission_callback' => [ $this, 'packages_permission' ] ] );
	}

	/** @return true|\WP_Error */
	public function admin_permission( \WP_REST_Request $request ) {
		if ( ! LinkAuthorizer::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return $this->step_up->verify( $request );
	}

	/** Authority-reducing emergency cleanup remains available while feature flag is off. */
	public function cleanup_permission( \WP_REST_Request $request ) {
		return $this->step_up->verify( $request );
	}

	/** @return true|\WP_Error */
	public function sources_permission( \WP_REST_Request $request ) {
		return $this->authorizer->authorize( $request, 'sources:read', false );
	}

	/** @return true|\WP_Error */
	public function source_permission( \WP_REST_Request $request ) {
		return $this->authorizer->authorize( $request, 'sources:read', true );
	}

	/** @return true|\WP_Error */
	public function updates_permission( \WP_REST_Request $request ) {
		return $this->authorizer->authorize( $request, 'updates:read', true );
	}

	/** @return true|\WP_Error */
	public function packages_permission( \WP_REST_Request $request ) {
		return $this->authorizer->authorize( $request, 'packages:read', true );
	}

	/** The just-issued credential may only revoke its own link after local persistence fails. */
	public function acceptance_compensation_permission( \WP_REST_Request $request ) {
		$query = $request->get_query_params();
		if ( isset( $query['api_key'] ) || isset( $query['link_credential'] ) || isset( $query['authorization'] ) ) {
			return new \WP_Error( 'wpbridge_query_credential_forbidden', __( '凭据不得通过 query 传递。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		if ( 1 !== preg_match( '/^WPBridge-Link (WPBL1-[A-Za-z0-9_-]{43})$/', (string) $request->get_header( 'Authorization' ), $match ) ) {
			return new \WP_Error( 'wpbridge_link_credential_missing', __( '缺少 Hub link credential。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		$link = $this->store->authorize( $match[1], time() );
		$id = strtolower( (string) $request['id'] );
		$origin = self::request_origin( $request );
		$host_ok = ! is_wp_error( $origin ) && hash_equals( $this->store->network_origin(), $origin );
		return $host_ok && ( ( is_array( $link ) && hash_equals( $id, (string) $link['link_id'] ) ) || $this->store->compensation_receipt( $match[1], $id ) ) ? true : new \WP_Error( 'wpbridge_link_credential_invalid', __( 'Hub link credential 无效。', 'wpbridge' ), [ 'status' => 401 ] );
	}

	public function issue_step_up( \WP_REST_Request $request ) {
		if ( ! LinkAuthorizer::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$result = $this->step_up->issue( $request );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function create_link( \WP_REST_Request $request ) {
		$identity = self::ensure_hub_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		if ( $this->store->has_active_spoke_link() ) {
			return new \WP_Error( 'wpbridge_spoke_cannot_be_hub', __( 'Active Spoke 不能创建 Hub link。', 'wpbridge' ), [ 'status' => 409 ] );
		}
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'scopes', 'slug_allowlist' ] ) || ! is_array( $body['scopes'] ) || ! is_array( $body['slug_allowlist'] ) ) {
			return self::bad_request();
		}
		$origin = self::local_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}
		$result = $this->store->create_invitation( $body['scopes'], $body['slug_allowlist'], time(), $origin );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->response( self::invitation_response( $result, $origin ), 201 );
	}

	public function list_links( \WP_REST_Request $request ) {
		unset( $request );
		$identity = self::ensure_hub_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		return $this->response( $this->store->public_links(), 200 );
	}

	public function challenge( \WP_REST_Request $request ) {
		if ( ! LinkAuthorizer::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$identity = self::ensure_hub_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$origin = self::request_origin( $request );
		if ( is_wp_error( $origin ) || ! hash_equals( $this->store->network_origin(), $origin ) ) {
			return is_wp_error( $origin ) ? $origin : new \WP_Error( 'wpbridge_hub_origin_mismatch', __( '请求 Host 不属于此 Hub network origin。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'invitation_token' ] ) || ! is_string( $body['invitation_token'] ) ) {
			return self::bad_request();
		}
		$result = $this->store->challenge( strtolower( (string) $request['id'] ), $body['invitation_token'], time() );
		return is_wp_error( $result ) ? $result : $this->response( $result, 200 );
	}

	public function acceptance( \WP_REST_Request $request ) {
		if ( ! LinkAuthorizer::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$identity = self::ensure_hub_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$origin = self::request_origin( $request );
		if ( is_wp_error( $origin ) || ! hash_equals( $this->store->network_origin(), $origin ) ) {
			return is_wp_error( $origin ) ? $origin : new \WP_Error( 'wpbridge_hub_origin_mismatch', __( '请求 Host 不属于此 Hub network origin。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'invitation_token', 'spoke_installation_uuid', 'spoke_public_key', 'nonce', 'timestamp', 'signature' ] ) ) {
			return self::bad_request();
		}
		$result = $this->store->accept( strtolower( (string) $request['id'] ), $body, time() );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function rotate_link( \WP_REST_Request $request ) {
		$identity = self::ensure_hub_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$body = $request->get_json_params();
		if ( ! self::exact_reason( $body ) ) {
			return self::bad_request();
		}
		$result = $this->store->rotate( strtolower( (string) $request['id'] ), time(), (string) $body['reason'] );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function cancel_invitation( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_reason( $body ) ) {
			return self::bad_request();
		}
		if ( ! $this->store->cancel_invitation( strtolower( (string) $request['id'] ), time(), (string) $body['reason'] ) ) {
			return new \WP_Error( 'wpbridge_invitation_not_found', __( 'Pending invitation 不存在。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		return $this->response( null, 204 );
	}

	public function revoke_link( \WP_REST_Request $request ) {
		$identity = self::ensure_hub_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$body = $request->get_json_params();
		if ( ! self::exact_reason( $body ) ) {
			return self::bad_request();
		}
		if ( ! $this->store->revoke( strtolower( (string) $request['id'] ), time(), (string) $body['reason'] ) ) {
			return new \WP_Error( 'wpbridge_link_not_found', __( 'Hub link 不存在。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		return $this->response( null, 204 );
	}

	public function acceptance_compensation( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'reason' ] ) || ! in_array( $body['reason'] ?? '', [ 'spoke_storage_failed', 'spoke_unlink' ], true ) ) {
			return self::bad_request();
		}
		$id = strtolower( (string) $request['id'] );
		$authorization = (string) $request->get_header( 'Authorization' );
		preg_match( '/^WPBridge-Link (WPBL1-[A-Za-z0-9_-]{43})$/', $authorization, $match );
		$credential = (string) ( $match[1] ?? '' );
		if ( ! $this->store->revoke( $id, time(), (string) $body['reason'], 'link', $credential ) && ! $this->store->compensation_receipt( $credential, $id ) ) {
			return new \WP_Error( 'wpbridge_link_compensation_failed', __( 'Hub link 撤销补偿失败。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return $this->response( null, 204 );
	}

	public function accept_local( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'hub_url', 'invitation_id', 'invitation_token' ] ) ) {
			return self::bad_request();
		}
		$result = ( new SpokeClient() )->accept( (string) $body['hub_url'], strtolower( (string) $body['invitation_id'] ), (string) $body['invitation_token'] );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function spoke_status( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return $this->response( [ 'links' => $this->store->spoke_statuses(), 'reconcile' => $this->store->reconcile_statuses() ], 200 );
	}

	public function process_reconcile(): void {
		$this->store->sweep_expired_invitations();
		$this->store->process_reconciles();
	}

	public function apply_spoke_rotation( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$query = $request->get_query_params();
		$cookie = (string) $request->get_header( 'Cookie' );
		$authorization = (string) $request->get_header( 'Authorization' );
		$valid_header = 1 === preg_match( '/^WPBridge-Rotation (WPBL1-[A-Za-z0-9_-]{43})$/', $authorization, $match );
		if ( ! self::exact_keys( $body, [] ) || isset( $query['link_credential'] ) || false !== stripos( $cookie, 'WPBL1-' ) || ! $valid_header || ! $this->store->apply_spoke_rotation( strtolower( (string) $request['id'] ), $match[1], time() ) ) {
			$link = $this->store->active_spoke_link( strtolower( (string) $request['id'] ) );
			if ( is_array( $link ) && $valid_header ) {
				$this->store->save_reconcile( (string) $link['hub_origin'], strtolower( (string) $request['id'] ), $match[1], time(), 'unlink_local' );
			}
			return self::bad_request();
		}
		return $this->response( [ 'link_id' => strtolower( (string) $request['id'] ), 'status' => 'active' ], 200 );
	}

	public function unlink_spoke( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_reason( $body ) ) {
			return self::bad_request();
		}
		$result = ( new SpokeClient() )->unlink( strtolower( (string) $request['id'] ), (string) $body['reason'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'wpbridge_spoke_link_not_found', __( 'Spoke link 不存在。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		return $this->response( null, 204 );
	}

	public function resolve_uncertain_accept( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'reason', 'resolution' ] ) || ! in_array( $body['resolution'] ?? '', [ 'hub_link_revoked', 'local_link_active' ], true ) || ! self::exact_reason( [ 'reason' => $body['reason'] ?? null ] ) ) {
			return self::bad_request();
		}
		if ( ! $this->store->resolve_uncertain_accept( strtolower( (string) $request['id'] ), time(), (string) $body['reason'], (string) $body['resolution'] ) ) {
			return new \WP_Error( 'wpbridge_uncertain_accept_not_found', __( 'Acceptance recovery 记录不存在或无法清理。', 'wpbridge' ), [ 'status' => 404 ] );
		}
		return $this->response( null, 204 );
	}

	public function proxy_sources( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$link   = (array) $this->authorizer->current_link();
		$allow  = (array) ( $link['slug_allowlist'] ?? [] );
		$output = [];
		foreach ( $this->sources->get_enabled_sorted() as $source ) {
			if ( '' === $source->slug || ! in_array( $source->slug, $allow, true ) ) {
				continue;
			}
			$output[] = [ 'id' => $source->id, 'name' => $source->name, 'item_type' => $source->item_type, 'slug' => $source->slug ];
		}
		return $this->response( [ 'sources' => $output ], 200 );
	}

	public function proxy_source( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		foreach ( $this->sources->get_by_slug( $slug, 'plugin' ) as $source ) {
			return $this->response( [ 'id' => $source->id, 'name' => $source->name, 'item_type' => $source->item_type, 'slug' => $source->slug ], 200 );
		}
		return new \WP_Error( 'wpbridge_link_resource_not_found', __( '资源不存在。', 'wpbridge' ), [ 'status' => 404 ] );
	}

	public function proxy_metadata( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		foreach ( $this->sources->get_by_slug( $slug, 'plugin' ) as $source ) {
			$handler = $source->get_handler();
			if ( ! $handler instanceof BridgeServerHandler ) {
				continue;
			}
			$info = $handler->get_info( $slug );
			if ( ! is_array( $info ) ) {
				continue;
			}
			return $this->response( self::safe_metadata( $slug, $info ), 200 );
		}
		return new \WP_Error( 'wpbridge_link_resource_not_found', __( '资源不存在。', 'wpbridge' ), [ 'status' => 404 ] );
	}

	public function proxy_package( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		foreach ( $this->sources->get_by_slug( $slug, 'plugin' ) as $source ) {
			$handler = $source->get_handler();
			if ( ! $handler instanceof BridgeServerHandler ) {
				continue;
			}
			$info = $handler->get_info( $slug );
			if ( ! is_array( $info ) ) {
				continue;
			}
			$integrity = $handler->protected_integrity( $slug, $info );
			$file = $handler->download_package( $slug, $integrity );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$token = InstallationIdentity::base64url( random_bytes( 32 ) );
			$this->package_streams[ $token ] = $file;
			$response = $this->response( [ '_wpbridge_package_stream' => $token, 'slug' => $slug ], 200 );
			$response->header( 'Content-Type', 'application/zip' );
			$response->header( 'Content-Disposition', 'attachment; filename="' . $slug . '.zip"' );
			return $response;
		}
		return new \WP_Error( 'wpbridge_link_resource_not_found', __( '资源不存在。', 'wpbridge' ), [ 'status' => 404 ] );
	}

	/** Stream verified package bytes without exposing upstream grant or URL. */
	public function serve_package( bool $served, $result, \WP_REST_Request $request, $server ): bool {
		unset( $server );
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$data  = $result->get_data();
		$token = is_array( $data ) ? (string) ( $data['_wpbridge_package_stream'] ?? '' ) : '';
		$file  = $this->package_streams[ $token ] ?? '';
		if ( 1 !== preg_match( '#^/wpbridge/v2/hub-proxy/plugins/[a-z0-9][a-z0-9-]{1,99}/package$#', $route ) || null === $this->authorizer->current_link() || '' === $token || '' === $file || ! is_file( $file ) ) {
			if ( '' !== $token && '' !== $file ) {
				unset( $this->package_streams[ $token ] );
				wp_delete_file( $file );
			}
			return false;
		}
		unset( $this->package_streams[ $token ] );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- verified temporary package must be streamed byte-for-byte.
			readfile( $file );
		} finally {
			wp_delete_file( $file );
		}
		return true;
	}

	private function response( $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	/** @return array<string,mixed> */
	public static function invitation_response( array $result, string $origin ): array {
		return [
			'invitation_id'         => $result['invitation_id'],
			'invitation_token'      => $result['invitation_token'],
			'hub_installation_uuid' => $result['hub_installation_uuid'],
			'hub_url'               => $origin,
			'scopes'                => $result['scopes'],
			'slug_allowlist'        => $result['slug_allowlist'],
			'expires_at'            => $result['expires_at'],
		];
	}

	/** @return array<string,mixed> */
	private static function safe_metadata( string $slug, array $info ): array {
		$allowed = [ 'name', 'version', 'requires', 'tested', 'requires_php', 'last_updated', 'description', 'changelog', 'sha256', 'signature_scheme', 'signature_kid', 'signature', 'signature_required', 'artifact_size', 'artifact_file', 'artifact_signed_at' ];
		$output  = [ 'slug' => $slug ];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $info ) ) {
				$output[ $key ] = $info[ $key ];
			}
		}
		$output['package'] = rest_url( self::NAMESPACE . '/hub-proxy/plugins/' . rawurlencode( $slug ) . '/package' );
		return $output;
	}

	/** @return string|\WP_Error */
	private static function local_origin() {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || 443 !== (int) ( $parts['port'] ?? 443 ) ) {
			return new \WP_Error( 'wpbridge_hub_origin_invalid', __( 'Hub 必须使用 HTTPS 443 origin。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return 'https://' . strtolower( (string) ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '' );
	}

	/** @return string|\WP_Error */
	private static function request_origin( \WP_REST_Request $request ) {
		return HostCanonicalizer::origin( (string) $request->get_header( 'Host' ) );
	}

	/** @return true|\WP_Error */
	private static function ensure_hub_identity() {
		if ( ! InstallationIdentity::ensure() ) {
			return new \WP_Error( 'wpbridge_hub_identity_unavailable', __( 'Hub installation identity 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$key = InstallationIdentity::base64url_decode( InstallationIdentity::public_key() );
		if ( '' === InstallationIdentity::uuid() || ! is_string( $key ) || ! defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return new \WP_Error( 'wpbridge_hub_identity_unavailable', __( 'Hub installation identity 无法持久保存。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		return true;
	}

	private static function exact_keys( $value, array $expected ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}

	private static function exact_reason( $value ): bool {
		return self::exact_keys( $value, [ 'reason' ] ) && is_string( $value['reason'] ) && strlen( trim( $value['reason'] ) ) >= 3 && strlen( $value['reason'] ) <= 500;
	}

	private static function bad_request(): \WP_Error {
		return new \WP_Error( 'wpbridge_request_invalid', __( '请求字段无效。', 'wpbridge' ), [ 'status' => 400 ] );
	}
}
