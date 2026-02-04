<?php
/**
 * FAIR 协议处理器
 *
 * 实现 FAIR (Federated And Independent Repositories) 协议支持
 * 包括 DID 解析和 ED25519 签名验证
 *
 * @package WPBridge
 * @since 0.6.0
 * @see https://github.com/nicholaswilson/fair-pm
 */

namespace WPBridge\FAIR;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FAIR 协议处理器类
 */
class FairProtocol {

    /**
     * DID 方法前缀
     */
    const DID_METHOD = 'did:fair:';

    /**
     * 支持的签名方案
     */
    const SIGNATURE_ED25519 = 'ed25519';

    /**
     * 解析 FAIR DID
     *
     * DID 格式: did:fair:<namespace>:<identifier>
     * 例如: did:fair:wp:plugin:hello-dolly
     *
     * @param string $did DID 字符串
     * @return array|null 解析结果
     */
    public function parse_did( string $did ): ?array {
        if ( strpos( $did, self::DID_METHOD ) !== 0 ) {
            return null;
        }

        $parts = explode( ':', substr( $did, strlen( self::DID_METHOD ) ) );

        if ( count( $parts ) < 2 ) {
            return null;
        }

        return [
            'method'     => 'fair',
            'namespace'  => $parts[0] ?? '',
            'type'       => $parts[1] ?? '',
            'identifier' => $parts[2] ?? '',
            'version'    => $parts[3] ?? null,
            'raw'        => $did,
        ];
    }

    /**
     * 构建 FAIR DID
     *
     * @param string      $namespace  命名空间 (如 'wp')
     * @param string      $type       类型 (如 'plugin', 'theme')
     * @param string      $identifier 标识符 (如 'hello-dolly')
     * @param string|null $version    版本号 (可选)
     * @return string
     */
    public function build_did( string $namespace, string $type, string $identifier, ?string $version = null ): string {
        $did = self::DID_METHOD . $namespace . ':' . $type . ':' . $identifier;

        if ( $version ) {
            $did .= ':' . $version;
        }

        return $did;
    }

    /**
     * 从 WordPress 项目生成 DID
     *
     * @param string $item_key  项目键 (如 'plugin:hello-dolly/hello.php')
     * @param string $item_slug 项目 slug
     * @return string
     */
    public function generate_did_from_item( string $item_key, string $item_slug ): string {
        $type = 'plugin';

        if ( strpos( $item_key, 'theme:' ) === 0 ) {
            $type = 'theme';
        } elseif ( strpos( $item_key, 'mu-plugin:' ) === 0 ) {
            $type = 'mu-plugin';
        }

        return $this->build_did( 'wp', $type, $item_slug );
    }

    /**
     * 验证 ED25519 签名
     *
     * @param string $message    原始消息
     * @param string $signature  签名 (base64 编码)
     * @param string $public_key 公钥 (base64 编码)
     * @return bool
     */
    public function verify_ed25519_signature( string $message, string $signature, string $public_key ): bool {
        // 检查 sodium 扩展
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            return $this->verify_ed25519_fallback( $message, $signature, $public_key );
        }

        try {
            $signature_bin  = base64_decode( $signature, true );
            $public_key_bin = base64_decode( $public_key, true );

            if ( false === $signature_bin || false === $public_key_bin ) {
                return false;
            }

            // 验证签名长度
            if ( strlen( $signature_bin ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
                return false;
            }

            // 验证公钥长度
            if ( strlen( $public_key_bin ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
                return false;
            }

            return sodium_crypto_sign_verify_detached( $signature_bin, $message, $public_key_bin );

        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * ED25519 签名验证回退方案
     *
     * 当 sodium 扩展不可用时使用
     *
     * @param string $message    原始消息
     * @param string $signature  签名
     * @param string $public_key 公钥
     * @return bool
     */
    private function verify_ed25519_fallback( string $message, string $signature, string $public_key ): bool {
        // 尝试使用 paragonie/sodium_compat
        if ( class_exists( '\ParagonIE_Sodium_Compat' ) ) {
            try {
                $signature_bin  = base64_decode( $signature, true );
                $public_key_bin = base64_decode( $public_key, true );

                if ( false === $signature_bin || false === $public_key_bin ) {
                    return false;
                }

                return \ParagonIE_Sodium_Compat::crypto_sign_verify_detached(
                    $signature_bin,
                    $message,
                    $public_key_bin
                );
            } catch ( \Exception $e ) {
                return false;
            }
        }

        // 无法验证签名
        return false;
    }

    /**
     * 验证包签名
     *
     * @param array $package 包数据
     * @return array 验证结果
     */
    public function verify_package_signature( array $package ): array {
        $result = [
            'valid'     => false,
            'signed'    => false,
            'signer'    => null,
            'algorithm' => null,
            'error'     => null,
        ];

        // 检查是否有签名
        if ( empty( $package['signature'] ) ) {
            $result['error'] = 'no_signature';
            return $result;
        }

        $result['signed'] = true;

        // 获取签名信息
        $signature_data = $package['signature'];
        $algorithm      = $signature_data['algorithm'] ?? self::SIGNATURE_ED25519;
        $signature      = $signature_data['value'] ?? '';
        $public_key     = $signature_data['public_key'] ?? '';
        $signer_did     = $signature_data['signer'] ?? '';

        $result['algorithm'] = $algorithm;
        $result['signer']    = $signer_did;

        // 目前只支持 ED25519
        if ( $algorithm !== self::SIGNATURE_ED25519 ) {
            $result['error'] = 'unsupported_algorithm';
            return $result;
        }

        // 构建待验证消息
        $message = $this->build_signature_message( $package );

        // 验证签名
        if ( $this->verify_ed25519_signature( $message, $signature, $public_key ) ) {
            $result['valid'] = true;
        } else {
            $result['error'] = 'invalid_signature';
        }

        return $result;
    }

    /**
     * 构建签名消息
     *
     * @param array $package 包数据
     * @return string
     */
    private function build_signature_message( array $package ): string {
        // 移除签名字段
        $data = $package;
        unset( $data['signature'] );

        // 按键排序
        ksort( $data );

        // JSON 编码
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * 解析 FAIR 仓库响应
     *
     * @param array $response API 响应
     * @return array 解析后的包列表
     */
    public function parse_repository_response( array $response ): array {
        $packages = [];

        // FAIR 响应格式
        if ( isset( $response['packages'] ) ) {
            foreach ( $response['packages'] as $package ) {
                $parsed = $this->parse_package( $package );
                if ( $parsed ) {
                    $packages[] = $parsed;
                }
            }
        }

        return $packages;
    }

    /**
     * 解析单个包
     *
     * @param array $package 包数据
     * @return array|null
     */
    private function parse_package( array $package ): ?array {
        if ( empty( $package['did'] ) && empty( $package['slug'] ) ) {
            return null;
        }

        return [
            'did'              => $package['did'] ?? '',
            'slug'             => $package['slug'] ?? '',
            'name'             => $package['name'] ?? '',
            'version'          => $package['version'] ?? '',
            'download_url'     => $package['download_url'] ?? '',
            'homepage'         => $package['homepage'] ?? '',
            'description'      => $package['description'] ?? '',
            'author'           => $package['author'] ?? '',
            'requires'         => $package['requires'] ?? '',
            'requires_php'     => $package['requires_php'] ?? '',
            'tested'           => $package['tested'] ?? '',
            'signature'        => $package['signature'] ?? null,
            'signature_valid'  => null,
            'last_updated'     => $package['last_updated'] ?? '',
        ];
    }

    /**
     * 检查 sodium 扩展是否可用
     *
     * @return bool
     */
    public function is_sodium_available(): bool {
        return function_exists( 'sodium_crypto_sign_verify_detached' ) ||
               class_exists( '\ParagonIE_Sodium_Compat' );
    }

    /**
     * 获取支持的签名算法
     *
     * @return array
     */
    public function get_supported_algorithms(): array {
        $algorithms = [];

        if ( $this->is_sodium_available() ) {
            $algorithms[] = self::SIGNATURE_ED25519;
        }

        return $algorithms;
    }
}
