<?php
/**
 * Verifies update package digests and detached Ed25519 signatures.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PackageIntegrityVerifier {
	const TRANSIENT_PREFIX = 'wpbridge_package_sha256_';
	const SIGNATURE_SCHEME = 'ed25519';
	const CANONICAL_HEADER = 'WENPAI-RELEASE-SIGNATURE-V1';

	/** @var bool */
	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'upgrader_pre_download', [ __CLASS__, 'verify' ], 10, 2 );
	}

	/**
	 * Remember integrity metadata from an update response.
	 *
	 * Trust roots are supplied only by local source configuration. A public key
	 * returned by the remote Bridge is deliberately not accepted here.
	 */
	public static function remember( string $package, string $sha256, int $ttl = DAY_IN_SECONDS, array $signature = [] ): bool {
		$sha256 = strtolower( trim( $sha256 ) );
		if ( 'https' !== wp_parse_url( $package, PHP_URL_SCHEME ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return false;
		}

		$record = self::normalize_record( array_merge( $signature, [ 'sha256' => $sha256 ] ) );
		if ( is_wp_error( $record ) ) {
			return false;
		}
		return set_site_transient( self::key( $package ), $record, max( 300, $ttl ) );
	}

	/**
	 * @param mixed  $reply Existing pre-download result.
	 * @param string $package Package URL.
	 * @return mixed
	 */
	public static function verify( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}

		$record = self::stored_record( $package );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( null === $record ) {
			return $reply; // Legacy source: no advertised digest or signature policy.
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$result = self::verify_file( $file, $record );
		if ( is_wp_error( $result ) ) {
			wp_delete_file( $file );
			return $result;
		}

		delete_site_transient( self::key( $package ) );
		return $file;
	}

	/** Verify a file already downloaded through a protected Bridge grant. */
	public static function verify_downloaded_file( string $file, array $record ) {
		$record = self::normalize_record( $record );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		return self::verify_file( $file, $record );
	}

	/** Return the full locally remembered record for an authorized downloader. */
	public static function expected_integrity( string $package ): array {
		$record = self::stored_record( $package );
		return is_array( $record ) ? $record : [];
	}

	/** Return a previously advertised digest for backward-compatible callers. */
	public static function expected( string $package ): string {
		$record = self::stored_record( $package );
		return is_array( $record ) ? $record['sha256'] : '';
	}

	public static function forget( string $package ): void {
		delete_site_transient( self::key( $package ) );
	}

	/**
	 * Apply a deployment-owned allowlist to source-local trust roots.
	 *
	 * When a deployment keyring exists, source metadata may only repeat an
	 * approved kid with the exact same public key. It cannot rotate, replace, or
	 * append trust roots. An invalid source overlay poisons the effective ring so
	 * a required signature subsequently fails closed as an unknown kid.
	 */
	public static function restrict_to_deployment_keyring( array $deployment, array $source ): array {
		$approved = self::normalize_keyring( $deployment );
		foreach ( $source as $kid => $entry ) {
			$source_key = is_array( $entry ) ? ( $entry['public_key'] ?? '' ) : $entry;
			$candidate = self::normalize_keyring( [ $kid => $source_key ] );
			if ( 1 !== count( $candidate ) || ! isset( $approved[ $kid ] ) || ! hash_equals( $approved[ $kid ]['public_key'], $candidate[ $kid ]['public_key'] ) ) {
				return [];
			}
		}
		return $approved;
	}

	/** @return true|\WP_Error */
	private static function verify_file( string $file, array $record ) {
		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $record['sha256'], strtolower( $actual ) ) ) {
			return new \WP_Error(
				'wpbridge_checksum_mismatch',
				__( '更新包 SHA-256 校验失败，已中止安装。', 'wpbridge' ),
				[ 'expected' => $record['sha256'], 'actual' => (string) $actual ]
			);
		}

		if ( $record['signature_required'] || '' !== $record['signature'] ) {
			$actual_size = filesize( $file );
			if ( ! is_int( $actual_size ) || $actual_size !== $record['artifact_size'] ) {
				return new \WP_Error( 'wpbridge_artifact_size_mismatch', __( '更新包大小与签名元数据不一致，已中止安装。', 'wpbridge' ) );
			}
		}

		if ( ! $record['signature_required'] && '' === $record['signature'] ) {
			return true;
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) || ! defined( 'SODIUM_CRYPTO_SIGN_BYTES' ) ) {
			return new \WP_Error( 'wpbridge_signature_unsupported', __( '当前 PHP 环境无法验证 Ed25519 更新签名。', 'wpbridge' ) );
		}
		$key = $record['artifact_public_keys'][ $record['signature_kid'] ] ?? '';
		if ( ! is_array( $key ) ) {
			return new \WP_Error( 'wpbridge_signature_unknown_key', __( '更新包签名使用了未受信任的密钥。', 'wpbridge' ) );
		}
		$signed_at = strtotime( $record['artifact_signed_at'] );
		$not_before = '' === $key['not_before'] ? false : strtotime( $key['not_before'] );
		$not_after  = '' === $key['not_after'] ? false : strtotime( $key['not_after'] );
		if ( false === $signed_at || ( false !== $not_before && $signed_at < $not_before ) || ( false !== $not_after && $signed_at > $not_after ) ) {
			return new \WP_Error( 'wpbridge_signature_key_window', __( '更新包签名时间不在受信密钥的验证窗口内。', 'wpbridge' ) );
		}

		$public_key = self::base64url_decode( $key['public_key'] );
		$signature  = self::base64url_decode( $record['signature'] );
		if ( ! is_string( $public_key ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) || ! is_string( $signature ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return new \WP_Error( 'wpbridge_signature_invalid', __( '更新包签名格式无效，已中止安装。', 'wpbridge' ) );
		}

		$canonical = self::canonical( $record );
		if ( ! sodium_crypto_sign_verify_detached( $signature, $canonical, $public_key ) ) {
			return new \WP_Error( 'wpbridge_signature_mismatch', __( '更新包 Ed25519 签名校验失败，已中止安装。', 'wpbridge' ) );
		}
		return true;
	}

	/** @return array|\WP_Error */
	private static function normalize_record( array $record ) {
		$normalized = [
			'sha256'              => strtolower( trim( (string) ( $record['sha256'] ?? '' ) ) ),
			'slug'                => sanitize_title( (string) ( $record['slug'] ?? '' ) ),
			'version'             => trim( (string) ( $record['version'] ?? '' ) ),
			'artifact_file'       => trim( (string) ( $record['artifact_file'] ?? '' ) ),
			'artifact_signed_at'  => trim( (string) ( $record['artifact_signed_at'] ?? '' ) ),
			'artifact_size'       => max( 0, (int) ( $record['artifact_size'] ?? 0 ) ),
			'signature_scheme'    => strtolower( trim( (string) ( $record['signature_scheme'] ?? '' ) ) ),
			'signature_kid'       => trim( (string) ( $record['signature_kid'] ?? '' ) ),
			'signature'           => trim( (string) ( $record['signature'] ?? '' ) ),
			'signature_required'  => ! empty( $record['signature_required'] ),
			'artifact_public_keys' => self::normalize_keyring( $record['artifact_public_keys'] ?? [] ),
		];

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $normalized['sha256'] ) ) {
			return new \WP_Error( 'wpbridge_checksum_missing', __( '更新包缺少有效 SHA-256 摘要。', 'wpbridge' ) );
		}
		$has_signature = '' !== $normalized['signature'] || '' !== $normalized['signature_scheme'] || '' !== $normalized['signature_kid'];
		if ( ! $normalized['signature_required'] && ! $has_signature ) {
			return $normalized;
		}
		if ( self::SIGNATURE_SCHEME !== $normalized['signature_scheme'] || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $normalized['signature_kid'] ) || ! preg_match( '/^[A-Za-z0-9_-]{86}$/', $normalized['signature'] ) ) {
			return new \WP_Error( 'wpbridge_signature_metadata_invalid', __( '更新包签名元数据不完整或格式无效。', 'wpbridge' ) );
		}
		$expected_file = $normalized['slug'] . '-' . $normalized['version'] . '.zip';
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $normalized['slug'] ) || ! preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/', $normalized['version'] ) || ! hash_equals( $expected_file, $normalized['artifact_file'] ) || basename( $normalized['artifact_file'] ) !== $normalized['artifact_file'] || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,199}\.zip$/', $normalized['artifact_file'] ) || $normalized['artifact_size'] < 1 || ! self::is_rfc3339_utc( $normalized['artifact_signed_at'] ) ) {
			return new \WP_Error( 'wpbridge_signature_metadata_invalid', __( '更新包签名元数据不完整或格式无效。', 'wpbridge' ) );
		}
		return $normalized;
	}

	/** Accept active and verify-only public keys, indexed by kid. */
	private static function normalize_keyring( $keyring ): array {
		if ( ! is_array( $keyring ) ) {
			return [];
		}
		$keys = [];
		foreach ( $keyring as $kid => $entry ) {
			$status     = 'active';
			$key        = $entry;
			$not_before = '';
			$not_after  = '';
			if ( is_array( $entry ) ) {
				$status     = strtolower( trim( (string) ( $entry['status'] ?? '' ) ) );
				$key        = $entry['public_key'] ?? '';
				$not_before = trim( (string) ( $entry['not_before'] ?? '' ) );
				$not_after  = trim( (string) ( $entry['not_after'] ?? '' ) );
			}
			if ( ! is_string( $kid ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $kid ) || ! in_array( $status, [ 'active', 'verify-only' ], true ) || ! is_string( $key ) || ( '' !== $not_before && ! self::is_rfc3339_utc( $not_before ) ) || ( '' !== $not_after && ! self::is_rfc3339_utc( $not_after ) ) || ( 'verify-only' === $status && '' === $not_after ) || ( '' !== $not_before && '' !== $not_after && strtotime( $not_before ) > strtotime( $not_after ) ) ) {
				continue;
			}
			$decoded = self::base64url_decode( $key );
			if ( is_string( $decoded ) && 32 === strlen( $decoded ) ) {
				$keys[ $kid ] = [
					'public_key' => $key,
					'status'     => $status,
					'not_before' => $not_before,
					'not_after'  => $not_after,
				];
			}
		}
		return $keys;
	}

	private static function canonical( array $record ): string {
		return self::CANONICAL_HEADER . "\n"
			. 'slug:' . $record['slug'] . "\n"
			. 'version:' . $record['version'] . "\n"
			. 'file:' . $record['artifact_file'] . "\n"
			. 'size:' . $record['artifact_size'] . "\n"
			. 'sha256:' . $record['sha256'] . "\n"
			. 'signed_at:' . $record['artifact_signed_at'] . "\n";
	}

	private static function is_rfc3339_utc( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return false;
		}
		try {
			$parsed = new \DateTimeImmutable( $value );
			return $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) === $value;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/** @return string|false */
	private static function base64url_decode( string $value ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/** @return array|\WP_Error|null */
	private static function stored_record( string $package ) {
		$stored = get_site_transient( self::key( $package ) );
		if ( is_string( $stored ) && preg_match( '/^[a-f0-9]{64}$/', $stored ) ) {
			return [
				'sha256' => $stored,
				'slug' => '', 'version' => '', 'artifact_file' => '', 'artifact_size' => 0, 'artifact_signed_at' => '',
				'signature_scheme' => '', 'signature_kid' => '', 'signature' => '',
				'signature_required' => false, 'artifact_public_keys' => [],
			];
		}
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$record = self::normalize_record( $stored );
		if ( is_wp_error( $record ) ) {
			return new \WP_Error( 'wpbridge_integrity_record_invalid', __( '更新包完整性记录无效，已中止安装。', 'wpbridge' ) );
		}
		return $record;
	}

	private static function key( string $package ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', $package );
	}
}
