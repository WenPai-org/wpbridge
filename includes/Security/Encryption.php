<?php
/**
 * 密钥加密
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
     * 加密方法
     *
     * @var string
     */
    const METHOD = 'aes-256-cbc';

    /**
     * 获取加密密钥
     *
     * @return string
     */
    private static function get_key(): string {
        // 优先使用自定义密钥
        if ( defined( 'WPBRIDGE_ENCRYPTION_KEY' ) && WPBRIDGE_ENCRYPTION_KEY ) {
            return WPBRIDGE_ENCRYPTION_KEY;
        }

        // 使用 WordPress 的 AUTH_KEY
        if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
            return AUTH_KEY;
        }

        // 最后使用 SECURE_AUTH_KEY
        if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
            return SECURE_AUTH_KEY;
        }

        // 如果都没有，使用站点 URL 的哈希（不推荐）
        return hash( 'sha256', get_site_url() );
    }

    /**
     * 加密数据
     *
     * @param string $data 明文数据
     * @return string 加密后的数据（base64 编码）
     */
    public static function encrypt( string $data ): string {
        if ( empty( $data ) ) {
            return '';
        }

        $key = hash( 'sha256', self::get_key(), true );
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );

        $encrypted = openssl_encrypt( $data, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $encrypted ) {
            return '';
        }

        // 将 IV 和加密数据一起存储
        return base64_encode( $iv . $encrypted );
    }

    /**
     * 解密数据
     *
     * @param string $data 加密数据（base64 编码）
     * @return string 解密后的明文
     */
    public static function decrypt( string $data ): string {
        if ( empty( $data ) ) {
            return '';
        }

        $data = base64_decode( $data );

        if ( false === $data ) {
            return '';
        }

        $key       = hash( 'sha256', self::get_key(), true );
        $iv_length = openssl_cipher_iv_length( self::METHOD );

        if ( strlen( $data ) < $iv_length ) {
            return '';
        }

        $iv        = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );

        $decrypted = openssl_decrypt( $encrypted, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $decrypted ) {
            return '';
        }

        return $decrypted;
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

        // 检查是否是有效的 base64
        $decoded = base64_decode( $data, true );

        if ( false === $decoded ) {
            return false;
        }

        // 检查长度是否足够包含 IV
        $iv_length = openssl_cipher_iv_length( self::METHOD );

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
     * @param int $length 长度
     * @return string
     */
    public static function generate_token( int $length = 32 ): string {
        return bin2hex( random_bytes( $length / 2 ) );
    }

    /**
     * 哈希密码/令牌（用于比较）
     *
     * @param string $data 数据
     * @return string
     */
    public static function hash( string $data ): string {
        return hash( 'sha256', $data . self::get_key() );
    }

    /**
     * 验证哈希
     *
     * @param string $data 原始数据
     * @param string $hash 哈希值
     * @return bool
     */
    public static function verify_hash( string $data, string $hash ): bool {
        return hash_equals( self::hash( $data ), $hash );
    }
}
