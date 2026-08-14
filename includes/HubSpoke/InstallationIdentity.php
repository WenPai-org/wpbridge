<?php
/**
 * Immutable installation identity and Ed25519 key custody.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

use WPBridge\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the installation identifier and link proof key. */
final class InstallationIdentity {
	private const UUID_OPTION       = 'wpbridge_installation_uuid';
	private const PUBLIC_KEY_OPTION = 'wpbridge_link_public_key';
	private const PRIVATE_KEY_OPTION = 'wpbridge_link_private_key';

	/** Ensure the immutable identity exists. */
	public static function ensure(): bool {
		if ( '' === self::uuid() && ! self::write_network_option( self::UUID_OPTION, wp_generate_uuid4() ) ) {
			return false;
		}
		if ( '' !== (string) get_option( self::PUBLIC_KEY_OPTION, '' ) && '' !== (string) get_option( self::PRIVATE_KEY_OPTION, '' ) ) {
			return true;
		}
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) || ! function_exists( 'sodium_memzero' ) ) {
			return false;
		}

		$keypair = sodium_crypto_sign_keypair();
		$public  = sodium_crypto_sign_publickey( $keypair );
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$encoded = self::base64url( $secret );
		try {
			$ciphertext = Encryption::encrypt( $encoded );
		} finally {
			sodium_memzero( $secret );
			sodium_memzero( $keypair );
		}
		if ( '' === $ciphertext ) {
			return false;
		}
		update_option( self::PUBLIC_KEY_OPTION, self::base64url( $public ), false );
		update_option( self::PRIVATE_KEY_OPTION, $ciphertext, false );
		return true;
	}

	/** Return the immutable network installation UUID. */
	public static function uuid(): string {
		$value = self::read_network_option( self::UUID_OPTION );
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower( $value ) ) ? strtolower( $value ) : '';
	}

	/** Return the base64url public key. */
	public static function public_key(): string {
		return (string) get_option( self::PUBLIC_KEY_OPTION, '' );
	}

	/** Sign a canonical string without exposing the private key. */
	public static function sign( string $canonical ) {
		if ( ! self::ensure() || ! function_exists( 'sodium_crypto_sign_detached' ) || ! function_exists( 'sodium_memzero' ) ) {
			return new \WP_Error( 'wpbridge_link_key_unavailable', __( '安装身份密钥不可用。', 'wpbridge' ) );
		}
		$encoded = Encryption::decrypt( (string) get_option( self::PRIVATE_KEY_OPTION, '' ) );
		$secret  = self::base64url_decode( $encoded );
		if ( ! is_string( $secret ) || ! defined( 'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES' ) || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
			return new \WP_Error( 'wpbridge_link_key_unavailable', __( '安装身份密钥不可用。', 'wpbridge' ) );
		}
		$signature = sodium_crypto_sign_detached( $canonical, $secret );
		sodium_memzero( $secret );
		return self::base64url( $signature );
	}

	public static function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	public static function base64url_decode( string $value ) {
		if ( '' === $value || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );
	}

	private static function read_network_option( string $name ) {
		return is_multisite() ? get_site_option( $name, '' ) : get_option( $name, '' );
	}

	private static function write_network_option( string $name, string $value ): bool {
		return is_multisite() ? update_site_option( $name, $value ) : update_option( $name, $value, false );
	}
}
