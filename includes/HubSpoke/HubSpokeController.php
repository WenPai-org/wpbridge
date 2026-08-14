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

	private HubSpokeStore $store;
	private StepUpVerifier $step_up;
	private LinkAuthorizer $authorizer;
	private SourceManager $sources;

	public function __construct( Settings $settings ) {
		$this->store      = new HubSpokeStore();
		$this->step_up    = new StepUpVerifier();
		$this->authorizer = new LinkAuthorizer( $this->store );
		$this->sources    = new SourceManager( $settings );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'rest_pre_serve_request', [ $this, 'serve_package' ], 10, 4 );
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
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})/acceptances', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'acceptance' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})/rotations', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'rotate_link' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/hub-links/(?P<id>[0-9a-f-]{36})', [ 'methods' => \WP_REST_Server::DELETABLE, 'callback' => [ $this, 'revoke_link' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );
		register_rest_route( self::NAMESPACE, '/spoke-links/accept', [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'accept_local' ], 'permission_callback' => [ $this, 'admin_permission' ] ] );

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

	public function issue_step_up( \WP_REST_Request $request ) {
		if ( ! LinkAuthorizer::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
		}
		$result = $this->step_up->issue( $request );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function create_link( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'scopes', 'slug_allowlist' ] ) || ! is_array( $body['scopes'] ) || ! is_array( $body['slug_allowlist'] ) ) {
			return self::bad_request();
		}
		$origin = self::local_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}
		$result = $this->store->create_invitation( $body['scopes'], $body['slug_allowlist'], time() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['hub_url'] = $origin;
		return $this->response( $result, 201 );
	}

	public function list_links( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return $this->response( $this->store->public_links(), 200 );
	}

	public function challenge( \WP_REST_Request $request ) {
		if ( ! LinkAuthorizer::enabled() ) {
			return new \WP_Error( 'wpbridge_hub_spoke_disabled', __( 'Hub-Spoke 未启用。', 'wpbridge' ), [ 'status' => 503 ] );
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
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'invitation_token', 'spoke_installation_uuid', 'spoke_public_key', 'nonce', 'timestamp', 'signature' ] ) ) {
			return self::bad_request();
		}
		$result = $this->store->accept( strtolower( (string) $request['id'] ), $body, time() );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function rotate_link( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_reason( $body ) ) {
			return self::bad_request();
		}
		$result = $this->store->rotate( strtolower( (string) $request['id'] ), time() );
		return is_wp_error( $result ) ? $result : $this->response( $result, 201 );
	}

	public function revoke_link( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! self::exact_reason( $body ) ) {
			return self::bad_request();
		}
		if ( ! $this->store->revoke( strtolower( (string) $request['id'] ), time() ) ) {
			return new \WP_Error( 'wpbridge_link_not_found', __( 'Hub link 不存在。', 'wpbridge' ), [ 'status' => 404 ] );
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
			$integrity = [
				'sha256'            => $info['sha256'] ?? $info['checksum_sha256'] ?? '',
				'signature_scheme'   => $info['signature_scheme'] ?? '',
				'signature_kid'      => $info['signature_kid'] ?? '',
				'signature'          => $info['signature'] ?? '',
				'signature_required' => true,
				'artifact_size'      => $info['artifact_size'] ?? 0,
				'artifact_file'      => $info['artifact_file'] ?? '',
				'artifact_signed_at' => $info['artifact_signed_at'] ?? '',
				'_wpbridge_artifact_keys' => $source->metadata['artifact_public_keys'] ?? [],
			];
			$file = $handler->download_package( $slug, $integrity );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$response = $this->response( [ '_wpbridge_package_file' => $file, 'slug' => $slug ], 200 );
			$response->header( 'Content-Type', 'application/zip' );
			$response->header( 'Content-Disposition', 'attachment; filename="' . $slug . '.zip"' );
			return $response;
		}
		return new \WP_Error( 'wpbridge_link_resource_not_found', __( '资源不存在。', 'wpbridge' ), [ 'status' => 404 ] );
	}

	/** Stream verified package bytes without exposing upstream grant or URL. */
	public function serve_package( bool $served, $result, \WP_REST_Request $request, $server ): bool {
		unset( $request, $server );
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}
		$data = $result->get_data();
		$file = is_array( $data ) ? (string) ( $data['_wpbridge_package_file'] ?? '' ) : '';
		if ( '' === $file || ! is_file( $file ) ) {
			return false;
		}
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
