<?php
/**
 * FAIR 源适配器
 *
 * 处理 FAIR 协议源的更新检查和下载
 *
 * @package WPBridge
 * @since 0.6.0
 */

namespace WPBridge\FAIR;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\Core\SourceRegistry;

/**
 * FAIR 源适配器类
 */
class FairSourceAdapter {

    /**
     * FAIR 协议处理器
     *
     * @var FairProtocol
     */
    private FairProtocol $protocol;

    /**
     * 源配置
     *
     * @var array
     */
    private array $source;

    /**
     * 构造函数
     *
     * @param array $source 源配置
     */
    public function __construct( array $source ) {
        $this->source   = $source;
        $this->protocol = new FairProtocol();
    }

    /**
     * 检查插件更新
     *
     * @param string $slug    插件 slug
     * @param string $version 当前版本
     * @return array|null 更新信息
     */
    public function check_plugin_update( string $slug, string $version ): ?array {
        return $this->check_update( 'plugin', $slug, $version );
    }

    /**
     * 检查主题更新
     *
     * @param string $slug    主题 slug
     * @param string $version 当前版本
     * @return array|null 更新信息
     */
    public function check_theme_update( string $slug, string $version ): ?array {
        return $this->check_update( 'theme', $slug, $version );
    }

    /**
     * 检查更新
     *
     * @param string $type    类型 (plugin/theme)
     * @param string $slug    slug
     * @param string $version 当前版本
     * @return array|null
     */
    private function check_update( string $type, string $slug, string $version ): ?array {
        $api_url = $this->source['api_url'] ?? '';

        if ( empty( $api_url ) ) {
            return null;
        }

        // 构建 API 请求 URL
        $endpoint = trailingslashit( $api_url ) . $type . 's/' . $slug;

        // 发送请求
        $response = $this->make_request( $endpoint );

        if ( ! $response ) {
            return null;
        }

        // 解析响应
        $package = $this->parse_response( $response );

        if ( ! $package ) {
            return null;
        }

        // 检查版本
        if ( version_compare( $package['version'], $version, '<=' ) ) {
            return null; // 没有更新
        }

        // 验证签名（如果需要）
        if ( ! empty( $this->source['signature_required'] ) ) {
            $verification = $this->protocol->verify_package_signature( $response );

            if ( ! $verification['valid'] ) {
                // 签名验证失败
                return null;
            }

            $package['signature_valid'] = true;
        }

        return $package;
    }

    /**
     * 获取插件信息
     *
     * @param string $slug 插件 slug
     * @return array|null
     */
    public function get_plugin_info( string $slug ): ?array {
        return $this->get_info( 'plugin', $slug );
    }

    /**
     * 获取主题信息
     *
     * @param string $slug 主题 slug
     * @return array|null
     */
    public function get_theme_info( string $slug ): ?array {
        return $this->get_info( 'theme', $slug );
    }

    /**
     * 获取项目信息
     *
     * @param string $type 类型
     * @param string $slug slug
     * @return array|null
     */
    private function get_info( string $type, string $slug ): ?array {
        $api_url = $this->source['api_url'] ?? '';

        if ( empty( $api_url ) ) {
            return null;
        }

        $endpoint = trailingslashit( $api_url ) . $type . 's/' . $slug . '/info';
        $response = $this->make_request( $endpoint );

        if ( ! $response ) {
            return null;
        }

        return $this->parse_response( $response );
    }

    /**
     * 通过 DID 查询
     *
     * @param string $did DID 字符串
     * @return array|null
     */
    public function query_by_did( string $did ): ?array {
        $parsed = $this->protocol->parse_did( $did );

        if ( ! $parsed ) {
            return null;
        }

        $api_url = $this->source['api_url'] ?? '';

        if ( empty( $api_url ) ) {
            return null;
        }

        // FAIR API 支持 DID 查询
        $endpoint = trailingslashit( $api_url ) . 'resolve?did=' . urlencode( $did );
        $response = $this->make_request( $endpoint );

        if ( ! $response ) {
            return null;
        }

        return $this->parse_response( $response );
    }

    /**
     * 发送 HTTP 请求
     *
     * @param string $url URL
     * @return array|null
     */
    private function make_request( string $url ): ?array {
        $args = [
            'timeout'    => 15,
            'user-agent' => 'WPBridge/' . WPBRIDGE_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
            'headers'    => [
                'Accept' => 'application/json',
            ],
        ];

        // 添加认证
        if ( ! empty( $this->source['auth_type'] ) && $this->source['auth_type'] !== SourceRegistry::AUTH_NONE ) {
            $auth_header = $this->get_auth_header();
            if ( $auth_header ) {
                $args['headers']['Authorization'] = $auth_header;
            }
        }

        // 添加自定义头
        if ( ! empty( $this->source['headers'] ) && is_array( $this->source['headers'] ) ) {
            $args['headers'] = array_merge( $args['headers'], $this->source['headers'] );
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'WPBridge FAIR request failed: ' . $response->get_error_message() . ' URL: ' . $url );
            }
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return null;
        }

        return $data;
    }

    /**
     * 获取认证头
     *
     * @return string|null
     */
    private function get_auth_header(): ?string {
        $auth_type = $this->source['auth_type'] ?? '';
        $secret_ref = $this->source['auth_secret_ref'] ?? '';

        if ( empty( $secret_ref ) ) {
            return null;
        }

        // 获取密钥
        $secret = get_option( 'wpbridge_secret_' . $secret_ref, '' );

        if ( empty( $secret ) ) {
            return null;
        }

        switch ( $auth_type ) {
            case SourceRegistry::AUTH_BEARER:
                return 'Bearer ' . $secret;

            case SourceRegistry::AUTH_TOKEN:
                return 'Token ' . $secret;

            case SourceRegistry::AUTH_BASIC:
                return 'Basic ' . base64_encode( $secret );

            default:
                return null;
        }
    }

    /**
     * 解析响应
     *
     * @param array $response 响应数据
     * @return array|null
     */
    private function parse_response( array $response ): ?array {
        // 标准 FAIR 响应格式
        if ( isset( $response['data'] ) ) {
            $response = $response['data'];
        }

        if ( empty( $response['slug'] ) && empty( $response['did'] ) ) {
            return null;
        }

        return [
            'did'              => $response['did'] ?? '',
            'slug'             => $response['slug'] ?? '',
            'name'             => $response['name'] ?? '',
            'version'          => $response['version'] ?? '',
            'new_version'      => $response['version'] ?? '',
            'download_url'     => $response['download_url'] ?? $response['download_link'] ?? '',
            'package'          => $response['download_url'] ?? $response['download_link'] ?? '',
            'homepage'         => $response['homepage'] ?? '',
            'url'              => $response['homepage'] ?? '',
            'description'      => $response['description'] ?? '',
            'author'           => $response['author'] ?? '',
            'requires'         => $response['requires'] ?? '',
            'requires_php'     => $response['requires_php'] ?? '',
            'tested'           => $response['tested'] ?? '',
            'last_updated'     => $response['last_updated'] ?? '',
            'signature'        => $response['signature'] ?? null,
            'signature_valid'  => null,
            'icons'            => $response['icons'] ?? [],
            'banners'          => $response['banners'] ?? [],
        ];
    }

    /**
     * 验证下载包
     *
     * @param string $file_path 文件路径
     * @param array  $package   包信息
     * @return bool
     */
    public function verify_download( string $file_path, array $package ): bool {
        // 如果不需要签名验证
        if ( empty( $this->source['signature_required'] ) ) {
            return true;
        }

        // 检查包是否有签名
        if ( empty( $package['signature'] ) ) {
            return false;
        }

        // 计算文件哈希
        $file_hash = hash_file( 'sha256', $file_path );

        // 验证哈希签名
        $signature_data = $package['signature'];

        if ( isset( $signature_data['file_hash'] ) ) {
            if ( $signature_data['file_hash'] !== $file_hash ) {
                return false;
            }
        }

        return true;
    }
}
