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
	private static int $lifecycle_depth = 0;

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
		if ( self::$lifecycle_depth > 0 ) {
			return (bool) call_user_func( $writer );
		}
		$increasing = self::credentials_increase( $value, $previous );
		$scoped_writer = static function () use ( $writer ): bool {
			++self::$lifecycle_depth;
			try {
				return (bool) call_user_func( $writer );
			} finally {
				--self::$lifecycle_depth;
			}
		};
		return ! class_exists( HubSpokeStore::class ) ? $scoped_writer() : ( new HubSpokeStore() )->guarded_credential_write( $increasing, $scoped_writer );
	}

	/**
	 * Read a multi-option credential generation without racing its writer.
	 *
	 * @return mixed
	 */
	public static function guarded_read( callable $reader ) {
		if ( self::$lifecycle_depth > 0 || ! class_exists( HubSpokeStore::class ) ) {
			return call_user_func( $reader );
		}
		$scoped_reader = static function () use ( $reader ) {
			++self::$lifecycle_depth;
			try {
				return call_user_func( $reader );
			} finally {
				--self::$lifecycle_depth;
			}
		};
		$result = ( new HubSpokeStore() )->guarded_lifecycle_read( $scoped_reader );
		return is_wp_error( $result ) ? false : $result;
	}

	/**
	 * Persist a multi-option credential change as one database transaction while
	 * holding the installation lifecycle lock. Nested Settings/Encryption writes
	 * inherit this scope and therefore cannot release the lock between fields.
	 *
	 * @param array    $value        Proposed credential-bearing value.
	 * @param array    $previous     Previous credential-bearing value.
	 * @param string[] $option_names Option cache entries touched by the writer.
	 * @param callable $writer       Receives committed values and returns option mutations or false.
	 */
	public static function transactional_write( array $value, array $previous, array $option_names, callable $writer ): bool {
		return self::guarded_write(
			$value,
			$previous,
			static function () use ( $writer, $option_names ): bool {
				global $wpdb;
				if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'update' ) || ! method_exists( $wpdb, 'insert' ) ) {
					return false;
				}
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options ) );
				if ( ! is_string( $engine ) || ! in_array( strtoupper( $engine ), [ 'INNODB', 'NDBCLUSTER', 'ROCKSDB' ], true ) ) {
					return false;
				}
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					return false;
				}
				$committed = false;
				try {
					$current = [];
					$rows = [];
					foreach ( array_unique( $option_names ) as $option_name ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table is the trusted current-blog table name.
						$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", $option_name ) );
						if ( is_object( $row ) ) {
							$rows[ $option_name ] = $row;
							$current[ $option_name ] = maybe_unserialize( $row->option_value );
						}
					}
					$mutations = call_user_func( $writer, $current );
					if ( ! is_array( $mutations ) ) {
						return false;
					}
					foreach ( $mutations as $option_name => $option_value ) {
						if ( ! in_array( $option_name, $option_names, true ) ) { return false; }
						$serialized = maybe_serialize( $option_value );
						if ( isset( $rows[ $option_name ] ) ) {
							$result = $wpdb->update( $wpdb->options, [ 'option_value' => $serialized ], [ 'option_name' => $option_name ], [ '%s' ], [ '%s' ] );
						} else {
							$autoload = 0 === strpos( $option_name, 'wpbridge_secure_' ) ? 'no' : 'yes';
							$result = $wpdb->insert( $wpdb->options, [ 'option_name' => $option_name, 'option_value' => $serialized, 'autoload' => $autoload ], [ '%s', '%s', '%s' ] );
						}
						if ( false === $result ) { return false; }
					}
					$committed = false !== $wpdb->query( 'COMMIT' );
					return $committed;
				} catch ( \Throwable $error ) {
					unset( $error );
					return false;
				} finally {
					if ( ! $committed ) {
						$wpdb->query( 'ROLLBACK' );
					}
					self::invalidate_option_caches( $option_names );
				}
			}
		);
	}

	/** Invalidate public/index caches before secret caches while the lifecycle lock is held. */
	private static function invalidate_option_caches( array $option_names ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) { return; }
		$names = array_unique( $option_names );
		foreach ( $names as $option_name ) {
			if ( 0 !== strpos( $option_name, 'wpbridge_secure_' ) ) { wp_cache_delete( $option_name, 'options' ); }
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( $names as $option_name ) {
			if ( 0 === strpos( $option_name, 'wpbridge_secure_' ) ) { wp_cache_delete( $option_name, 'options' ); }
		}
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
		$manifest = (array) get_option( 'wpbridge_secure_vendor_manifest', [] );
		foreach ( $manifest as $secure_key ) {
			if ( is_string( $secure_key ) && 0 === strpos( $secure_key, 'vendor_' ) && ! empty( get_option( 'wpbridge_secure_' . $secure_key, '' ) ) ) { return true; }
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'esc_like' ) ) {
			$like = $wpdb->esc_like( 'wpbridge_secure_vendor_' ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table is the trusted current-blog table name.
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			foreach ( (array) $names as $option_name ) {
				if ( 'wpbridge_secure_vendor_manifest' !== $option_name && ! empty( get_option( (string) $option_name, '' ) ) ) { return true; }
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
