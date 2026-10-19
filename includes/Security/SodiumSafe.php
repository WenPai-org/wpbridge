<?php
/**
 * Exception-safe wrappers around libsodium / sodium_compat.
 *
 * @package WPBridge
 */

namespace WPBridge\Security;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * sodium_compat (WordPress core 自带的 polyfill) 与 ext-sodium 的行为差异：
 * 小阶 / 畸形签名时 verify 会抛 SodiumException，sodium_memzero 永远抛异常。
 * 这里统一成：验签失败一律返回 false；清零失败时把变量置为空字符串.
 */
final class SodiumSafe {

	/** Ed25519 detached signature length (SODIUM_CRYPTO_SIGN_BYTES). */
	const SIGNATURE_BYTES = 64;

	/** Ed25519 public key length (SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES). */
	const PUBLIC_KEY_BYTES = 32;

	/**
	 * Verify a detached Ed25519 signature without ever throwing.
	 *
	 * @param mixed $signature  Raw 64-byte signature.
	 * @param mixed $message    Signed message.
	 * @param mixed $public_key Raw 32-byte public key.
	 * @return bool True only when the signature is valid.
	 */
	public static function verify_detached( $signature, $message, $public_key ): bool {
		if ( ! is_string( $signature ) || ! is_string( $message ) || ! is_string( $public_key ) ) {
			self::log_failure( 'invalid_type', $signature, $public_key );
			return false;
		}
		if ( self::SIGNATURE_BYTES !== strlen( $signature ) || self::PUBLIC_KEY_BYTES !== strlen( $public_key ) ) {
			self::log_failure( 'invalid_length', $signature, $public_key );
			return false;
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			self::log_failure( 'sodium_unavailable', $signature, $public_key );
			return false;
		}

		try {
			$valid = true === sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( \SodiumException $error ) {
			self::log_failure( 'sodium_exception', $signature, $public_key, get_class( $error ) );
			return false;
		} catch ( \Throwable $error ) {
			self::log_failure( 'sodium_error', $signature, $public_key, get_class( $error ) );
			return false;
		}

		if ( ! $valid ) {
			self::log_failure( 'signature_mismatch', $signature, $public_key );
		}
		return $valid;
	}

	/**
	 * Wipe a secret without ever throwing.
	 *
	 * ext-sodium 会真正清零内存；sodium_compat 无法清零、会抛异常，此时只能丢弃引用.
	 * 无论哪种情况，调用后变量都是空字符串.
	 *
	 * @param mixed $secret Secret to wipe (by reference).
	 */
	public static function memzero( &$secret ): void {
		if ( is_string( $secret ) && function_exists( 'sodium_memzero' ) ) {
			try {
				sodium_memzero( $secret );
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}
		$secret = '';
	}

	/**
	 * Record a verification failure.  Never logs the signature, key or message.
	 *
	 * 只使用本次请求里已经加载的 Logger 和 Settings（不触发自动加载）；
	 * 否则只在 WP_DEBUG && WP_DEBUG_LOG 下写 error_log.
	 *
	 * @param string $reason     Failure reason code.
	 * @param mixed  $signature  Signature (only its type/length is logged).
	 * @param mixed  $public_key Public key (only its type/length is logged).
	 * @param string $exception  Exception class, if any.
	 */
	private static function log_failure( string $reason, $signature, $public_key, string $exception = '' ): void {
		$context = [
			'reason'         => $reason,
			'signature_len'  => is_string( $signature ) ? strlen( $signature ) : gettype( $signature ),
			'public_key_len' => is_string( $public_key ) ? strlen( $public_key ) : gettype( $public_key ),
		];
		if ( '' !== $exception ) {
			$context['exception'] = $exception;
		}

		if ( class_exists( '\\WPBridge\\Core\\Logger', false ) && class_exists( '\\WPBridge\\Core\\Settings', false ) && method_exists( '\\WPBridge\\Core\\Logger', 'error' ) ) {
			\WPBridge\Core\Logger::error( 'Ed25519 签名验证失败', $context );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[WPBridge] Ed25519 签名验证失败 ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
