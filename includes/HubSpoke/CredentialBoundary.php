<?php
/** Network-wide upstream credential inventory and mutation guard. */
declare(strict_types=1);

namespace WPBridge\HubSpoke;

use WPBridge\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CredentialBoundary {
	private const SENSITIVE_KEYS = [ 'auth_token', 'auth_secret_ref', 'headers', 'api_key', 'api_secret', 'license_key', 'consumer_key', 'consumer_secret', 'token', 'access_token', 'refresh_token', 'update_private_key', 'update_device_id' ];

	public static function has_upstream_credentials(): bool {
		if ( ! is_multisite() ) {
			return self::current_site_has_credentials();
		}
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$found = self::current_site_has_credentials();
			restore_current_blog();
			if ( $found ) {
				return true;
			}
		}
		return false;
	}

	public static function mutation_allowed(): bool {
		if ( ! class_exists( HubSpokeStore::class ) ) {
			return true;
		}
		$allowed = false;
		( new HubSpokeStore() )->guarded_credential_write( true, static function () use ( &$allowed ): bool { $allowed = true; return true; } );
		return $allowed;
	}

	/** Execute the actual option write while holding the same lifecycle lock as role selection. */
	public static function guarded_write( array $value, array $previous, callable $writer ): bool {
		$increasing = self::credentials_increase( $value, $previous );
		return ! class_exists( HubSpokeStore::class ) ? (bool) call_user_func( $writer ) : ( new HubSpokeStore() )->guarded_credential_write( $increasing, $writer );
	}

	/** A deletion/empty replacement reduces authority and remains permitted. */
	public static function credential_write_allowed( array $value, array $previous = [] ): bool {
		return ! self::credentials_increase( $value, $previous ) || self::mutation_allowed();
	}

	private static function credentials_increase( array $value, array $previous = [] ): bool {
		$new_credentials = self::credential_map( $value );
		$old_credentials = self::credential_map( $previous );
		foreach ( $new_credentials as $path => $credential ) {
			if ( '' !== $credential && ( ! isset( $old_credentials[ $path ] ) || ! hash_equals( $old_credentials[ $path ], $credential ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function contains_nonempty_credentials( array $value ): bool {
		foreach ( $value as $key => $item ) {
			if ( in_array( strtolower( (string) $key ), self::SENSITIVE_KEYS, true ) && ! empty( $item ) && '***REDACTED***' !== $item && '********' !== $item ) {
				return true;
			}
			if ( is_array( $item ) && self::contains_nonempty_credentials( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/** Guard the generic encrypted-option API; an empty value remains authority-reducing. */
	public static function secure_write_allowed( string $value, string $previous = '' ): bool {
		return '' === $value || self::mutation_allowed() || ( '' !== $previous && hash_equals( $previous, $value ) );
	}

	public static function guarded_secure_write( string $value, string $previous, callable $writer ): bool {
		return self::guarded_write( [ 'api_key' => $value ], [ 'api_key' => $previous ], $writer );
	}

	private static function current_site_has_credentials(): bool {
		if ( '' !== (string) Encryption::get_secure( 'bridge_server_api_key', '' ) ) {
			return true;
		}
		foreach ( [ 'wpbridge_settings', 'wpbridge_sources', 'wpbridge_source_registry' ] as $option ) {
			if ( self::contains_nonempty_credentials( (array) get_option( $option, [] ) ) ) {
				return true;
			}
		}
		$settings = (array) get_option( 'wpbridge_settings', [] );
		foreach ( (array) ( $settings['vendors'] ?? [] ) as $vendor_id => $config ) {
			foreach ( self::SENSITIVE_KEYS as $field ) {
				if ( '' !== (string) Encryption::get_secure( 'vendor_' . $vendor_id . '_' . $field, '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return array<string,string> */
	private static function credential_map( array $value, string $prefix = '' ): array {
		$output = [];
		foreach ( $value as $key => $item ) {
			$path = $prefix . '/' . (string) $key;
			if ( in_array( strtolower( (string) $key ), self::SENSITIVE_KEYS, true ) ) {
				if ( is_array( $item ) ) {
					if ( [] !== $item ) { $output[ $path ] = hash( 'sha256', serialize( $item ) ); }
				} elseif ( ! empty( $item ) && '***REDACTED***' !== $item && '********' !== $item ) {
					$output[ $path ] = hash( 'sha256', (string) $item );
				}
			}
			if ( is_array( $item ) ) { $output += self::credential_map( $item, $path ); }
		}
		return $output;
	}
}
