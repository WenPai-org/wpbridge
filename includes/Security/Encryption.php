<?php
/**
 * 密钥加密
 *
 * 使用 AES-256-GCM (AEAD) 提供加密 + 完整性校验。
 * 密钥通过 HKDF 派生，加密和 MAC 使用独立子密钥。
 *
 * 数据格式: $wpb$2$ + base64( iv + tag + ciphertext )
 * 向后兼容: 能解密旧版 CBC 格式（无前缀的 base64 数据）
 *
 * @package WPBridge
 */

namespace WPBridge\Security;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 密钥加密类
 */
class Encryption {

	/**
	 * 加密方法 — AEAD
	 */
	const METHOD = 'aes-256-gcm';

	/**
	 * 旧版加密方法（向后兼容解密）
	 */
	const LEGACY_METHOD = 'aes-256-cbc';

	/**
	 * 数据格式前缀（版本 2 = GCM）
	 */
	const PREFIX = '$wpb$2$';

	/**
	 * GCM tag 长度（字节）
	 */
	const TAG_LENGTH = 16;

	/**
	 * 获取主密钥
	 *
	 * 优先级: WPBRIDGE_ENCRYPTION_KEY > AUTH_KEY > SECURE_AUTH_KEY > 旧 option.
	 *
	 * @return string
	 * @throws \RuntimeException 当无可用密钥时
	 */
	private static function get_key(): string {
		$keys = self::get_decryption_keys();
		if ( ! empty( $keys ) ) {
			return $keys[0];
		}

		throw new \RuntimeException( 'WPBridge encryption key is not configured.' );
	}

	/**
	 * 获取当前及轮换前的候选密钥.
	 *
	 * WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS 可定义为数组或逗号分隔字符串；只用于解密。
	 * 旧 option 仅为迁移兼容候选，不会覆盖新的主密钥。
	 *
	 * @return string[]
	 */
	private static function get_decryption_keys(): array {
		$keys = [];

		if ( defined( 'WPBRIDGE_ENCRYPTION_KEY' ) && WPBRIDGE_ENCRYPTION_KEY ) {
			$keys[] = (string) WPBRIDGE_ENCRYPTION_KEY;
		}

		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$keys[] = (string) AUTH_KEY;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$keys[] = (string) SECURE_AUTH_KEY;
		}

		if ( defined( 'WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS' ) && WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS ) {
			$previous = WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS;
			if ( is_string( $previous ) ) {
				$previous = array_map( 'trim', explode( ',', $previous ) );
			}
			if ( is_array( $previous ) ) {
				foreach ( $previous as $previous_key ) {
					if ( is_string( $previous_key ) && '' !== $previous_key ) {
						$keys[] = $previous_key;
					}
				}
			}
		}

		// 迁移: 旧版 option 始终作为最后一个解密候选。
		$legacy_key = get_option( 'wpbridge_encryption_key' );
		if ( ! empty( $legacy_key ) ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', [ __CLASS__, 'show_key_warning' ] );
			}
			$keys[] = (string) $legacy_key;
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * 显示密钥配置警告
	 */
	public static function show_key_warning(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'云桥安全提示: 请在 wp-config.php 中定义 WPBRIDGE_ENCRYPTION_KEY 常量以提升加密安全性。',
			'wpbridge'
		);
		echo '</p></div>';
	}

	/**
	 * 通过 HKDF 派生加密子密钥
	 *
	 * @param string $purpose 用途标识 ('encrypt' 或 'mac')
	 * @return string 32 字节二进制密钥
	 */
	private static function derive_key( string $purpose, string $master = '' ): string {
		if ( '' === $master ) {
			$master = self::get_key();
		}
		return hash_hkdf( 'sha256', $master, 32, 'wpbridge-' . $purpose );
	}

	/**
	 * 加密数据 (AES-256-GCM)
	 *
	 * @param string $data 明文数据
	 * @return string 加密后的数据（带版本前缀）
	 */
	public static function encrypt( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		$key = self::derive_key( 'encrypt' );
		$iv  = random_bytes( 12 ); // GCM 推荐 12 字节 IV

		$tag       = '';
		$encrypted = openssl_encrypt(
			$data,
			self::METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $encrypted ) {
			return '';
		}

		// 格式: prefix + base64( iv + tag + ciphertext )
		return self::PREFIX . base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * 解密数据
	 *
	 * 自动检测格式版本，兼容旧版 CBC 数据
	 *
	 * @param string $data 加密数据
	 * @return string 解密后的明文
	 */
	public static function decrypt( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		// 新版 GCM 格式
		if ( strpos( $data, self::PREFIX ) === 0 ) {
			return self::decrypt_gcm( substr( $data, strlen( self::PREFIX ) ) );
		}

		// 旧版 CBC 格式（向后兼容）
		return self::decrypt_legacy( $data );
	}

	/**
	 * GCM 解密
	 *
	 * @param string $encoded base64 编码的 iv+tag+ciphertext
	 * @return string
	 */
	private static function decrypt_gcm( string $encoded ): string {
		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}

		// iv(12) + tag(16) + ciphertext
		$min_length = 12 + self::TAG_LENGTH;
		if ( strlen( $raw ) <= $min_length ) {
			return '';
		}

		$iv        = substr( $raw, 0, 12 );
		$tag       = substr( $raw, 12, self::TAG_LENGTH );
		$encrypted = substr( $raw, $min_length );
		foreach ( self::get_decryption_keys() as $master_key ) {
			$decrypted = openssl_decrypt(
				$encrypted,
				self::METHOD,
				self::derive_key( 'encrypt', $master_key ),
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		do_action( 'wpbridge_decryption_failed', 'gcm' );
		return '';
	}

	/**
	 * 旧版 CBC 解密（向后兼容）
	 *
	 * @param string $data base64 编码的 iv+ciphertext
	 * @return string
	 */
	private static function decrypt_legacy( string $data ): string {
		$decoded = base64_decode( $data, true );
		if ( false === $decoded ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::LEGACY_METHOD );

		if ( strlen( $decoded ) <= $iv_length ) {
			return '';
		}

		$iv        = substr( $decoded, 0, $iv_length );
		$encrypted = substr( $decoded, $iv_length );

		foreach ( self::get_decryption_keys() as $master_key ) {
			$key       = hash( 'sha256', $master_key, true );
			$decrypted = openssl_decrypt( $encrypted, self::LEGACY_METHOD, $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		do_action( 'wpbridge_decryption_failed', 'legacy' );
		return '';
	}

	/**
	 * 检查数据是否已加密
	 *
	 * @param string $data 数据
	 * @return bool
	 */
	public static function is_encrypted( string $data ): bool {
		if ( empty( $data ) ) {
			return false;
		}

		// 新版格式检测
		if ( strpos( $data, self::PREFIX ) === 0 ) {
			return true;
		}

		// 旧版格式检测: 有效 base64 且长度足够包含 IV
		$decoded = base64_decode( $data, true );
		if ( false === $decoded ) {
			return false;
		}

		$iv_length = openssl_cipher_iv_length( self::LEGACY_METHOD );
		return strlen( $decoded ) > $iv_length;
	}

	/**
	 * 安全地存储敏感数据
	 *
	 * @param string $key   选项键
	 * @param string $value 敏感值
	 * @return bool
	 */
	public static function store_secure( string $key, string $value ): bool {
		if ( class_exists( \WPBridge\HubSpoke\CredentialBoundary::class ) && ! \WPBridge\HubSpoke\CredentialBoundary::secure_write_allowed( $value, self::get_secure( $key, '' ) ) ) {
			return false;
		}
		$encrypted = self::encrypt( $value );
		return update_option( 'wpbridge_secure_' . $key, $encrypted );
	}

	/**
	 * 安全地获取敏感数据
	 *
	 * @param string $key     选项键
	 * @param string $default 默认值
	 * @return string
	 */
	public static function get_secure( string $key, string $default = '' ): string {
		$encrypted = get_option( 'wpbridge_secure_' . $key, '' );

		if ( empty( $encrypted ) ) {
			return $default;
		}

		$decrypted = self::decrypt( $encrypted );

		return ! empty( $decrypted ) ? $decrypted : $default;
	}

	/**
	 * 删除安全存储的数据
	 *
	 * @param string $key 选项键
	 * @return bool
	 */
	public static function delete_secure( string $key ): bool {
		return delete_option( 'wpbridge_secure_' . $key );
	}

	/**
	 * 生成随机令牌
	 *
	 * @param int $length 期望的十六进制字符长度
	 * @return string
	 */
	public static function generate_token( int $length = 32 ): string {
		return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
	}

	/**
	 * HMAC 哈希（用于比较）
	 *
	 * @param string $data 数据
	 * @return string
	 */
	public static function hash( string $data ): string {
		return hash_hmac( 'sha256', $data, self::derive_key( 'mac' ) );
	}

	/**
	 * 验证 HMAC 哈希
	 *
	 * @param string $data 原始数据
	 * @param string $hash 哈希值
	 * @return bool
	 */
	public static function verify_hash( string $data, string $hash ): bool {
		return hash_equals( self::hash( $data ), $hash );
	}
}
