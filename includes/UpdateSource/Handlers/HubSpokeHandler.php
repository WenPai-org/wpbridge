<?php
/** Spoke runtime source handler. */

declare(strict_types=1);

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\HubSpoke\SpokeProxyClient;
use WPBridge\Security\PackageIntegrityVerifier;
use WPBridge\UpdateSource\SourceModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HubSpokeHandler extends AbstractHandler implements ProtectedPackageHandlerInterface {
	/** @var SpokeProxyClient|\WP_Error */
	private $client;

	public function __construct( SourceModel $source ) {
		parent::__construct( $source );
		$this->client = SpokeProxyClient::from_link( (string) ( $source->metadata['spoke_link_id'] ?? '' ) );
	}

	public function get_capabilities(): array {
		return [ 'auth' => 'wpbridge-link', 'version' => 'v2', 'download' => 'verified-proxy' ];
	}

	public function get_check_url(): string {
		return is_wp_error( $this->client ) ? '' : $this->client->package_url( $this->source->slug );
	}

	public function get_headers(): array {
		return [];
	}

	public function check_update( string $slug, string $version ): ?UpdateInfo {
		$info = $this->get_info( $slug );
		return is_array( $info ) && ! empty( $info['version'] ) && version_compare( (string) $info['version'], $version, '>' ) ? UpdateInfo::from_array( $info ) : null;
	}

	public function get_info( string $slug ): ?array {
		if ( is_wp_error( $this->client ) || $slug !== $this->source->slug ) {
			return null;
		}
		$info = $this->client->metadata( $slug );
		if ( is_wp_error( $info ) ) {
			return null;
		}
		$keyring = is_array( $this->source->metadata['artifact_public_keys'] ?? null ) ? $this->source->metadata['artifact_public_keys'] : [];
		if ( defined( 'WPBRIDGE_ARTIFACT_PUBLIC_KEYS' ) && is_array( WPBRIDGE_ARTIFACT_PUBLIC_KEYS ) ) {
			$keyring = PackageIntegrityVerifier::restrict_to_deployment_keyring( WPBRIDGE_ARTIFACT_PUBLIC_KEYS, $keyring );
		}
		$info['_wpbridge_artifact_keys'] = $keyring;
		$info['signature_required'] = true;
		return $info;
	}

	public function validate_auth(): bool {
		return ! is_wp_error( $this->client );
	}

	public function test_connection(): HealthStatus {
		if ( is_wp_error( $this->client ) ) {
			return HealthStatus::failed( 'Spoke link unavailable' );
		}
		$result = $this->client->sources();
		return is_wp_error( $result ) ? HealthStatus::failed( $result->get_error_message() ) : HealthStatus::healthy( 0 );
	}

	public function can_handle_download( string $package, string $slug ): bool {
		return ! is_wp_error( $this->client ) && $slug === $this->source->slug && hash_equals( $this->client->package_url( $slug ), $package );
	}

	public function download_package( string $slug, array $integrity ) {
		return is_wp_error( $this->client ) ? $this->client : $this->client->download( $slug, $integrity );
	}
}
