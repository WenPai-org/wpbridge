<?php
/** Domain-separated Hub link credential envelope. */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CredentialEnvelope {
	private const PREFIX = '$wpbh$1$';

	public static function encrypt( string $plaintext, string $link_id, string $origin ): string {
		$version = self::active_version();
		$key     = self::key( $version );
		if ( '' === $version || ! is_string( $key ) ) {
			return '';
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad( $link_id, $origin ), 16 );
		return false === $ciphertext ? '' : self::PREFIX . $version . '$' . InstallationIdentity::base64url( $iv . $tag . $ciphertext );
	}

	public static function decrypt( string $envelope, string $link_id, string $origin ): string {
		if ( 1 !== preg_match( '/^\$wpbh\$1\$([A-Za-z0-9._-]{1,64})\$([A-Za-z0-9_-]+)$/', $envelope, $match ) ) {
			return '';
		}
		$key = self::key( $match[1] );
		$raw = InstallationIdentity::base64url_decode( $match[2] );
		if ( ! is_string( $key ) || ! is_string( $raw ) || strlen( $raw ) <= 28 ) {
			return '';
		}
		$value = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), self::aad( $link_id, $origin ) );
		return is_string( $value ) ? $value : '';
	}

	public static function version( string $envelope ): string {
		return 1 === preg_match( '/^\$wpbh\$1\$([A-Za-z0-9._-]{1,64})\$/', $envelope, $match ) ? $match[1] : '';
	}

	private static function active_version(): string {
		return defined( 'WPBRIDGE_HUB_LINK_ACTIVE_KEY_VERSION' ) ? (string) WPBRIDGE_HUB_LINK_ACTIVE_KEY_VERSION : '';
	}

	/** @return string|null */
	private static function key( string $version ) {
		$keys = defined( 'WPBRIDGE_HUB_LINK_MASTER_KEYS' ) && is_array( WPBRIDGE_HUB_LINK_MASTER_KEYS ) ? WPBRIDGE_HUB_LINK_MASTER_KEYS : [];
		$master = InstallationIdentity::base64url_decode( (string) ( $keys[ $version ] ?? '' ) );
		return is_string( $master ) && 32 === strlen( $master ) ? hash_hkdf( 'sha256', $master, 32, 'wpbridge-hub-link-credential-v1', 'wpbridge-hub-link-domain-v1' ) : null;
	}

	private static function aad( string $link_id, string $origin ): string {
		return "WPBRIDGE-HUB-LINK-CREDENTIAL-V1\nlink_id:{$link_id}\norigin:{$origin}\n";
	}
}
