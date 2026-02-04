<?php
/**
 * AI 适配器抽象基类
 *
 * @package WPBridge
 */

namespace WPBridge\AIBridge\Adapters;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI 适配器抽象基类
 */
abstract class AbstractAdapter implements AdapterInterface {

    /**
     * 设置实例
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * 支持的插件 slug 列表
     *
     * @var array
     */
    protected array $supported_plugins = [];

    /**
     * 匹配的 URL 模式
     *
     * @var array
     */
    protected array $url_patterns = [];

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * 检查是否支持该插件
     *
     * @param string $plugin_slug 插件 slug
     * @return bool
     */
    public function supports( string $plugin_slug ): bool {
        return in_array( $plugin_slug, $this->supported_plugins, true );
    }

    /**
     * 检查请求是否匹配
     *
     * @param string $url  请求 URL
     * @param array  $args 请求参数
     * @return bool
     */
    public function matches( string $url, array $args ): bool {
        foreach ( $this->url_patterns as $pattern ) {
            if ( preg_match( $pattern, $url ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否启用
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $ai_settings = $this->settings->get( 'ai_bridge', [] );
        $adapters    = $ai_settings['adapters'] ?? [];

        return in_array( $this->get_name(), $adapters, true );
    }

    /**
     * 记录日志
     *
     * @param string $message 消息
     * @param array  $context 上下文
     */
    protected function log( string $message, array $context = [] ): void {
        $context['adapter'] = $this->get_name();
        Logger::debug( $message, $context );
    }

    /**
     * 获取请求体
     *
     * @param array $args 请求参数
     * @return array|null
     */
    protected function get_request_body( array $args ): ?array {
        if ( empty( $args['body'] ) ) {
            return null;
        }

        $body = $args['body'];

        if ( is_string( $body ) ) {
            $decoded = json_decode( $body, true );
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return is_array( $body ) ? $body : null;
    }

    /**
     * 设置请求体
     *
     * @param array $args 请求参数
     * @param array $body 请求体
     * @return array
     */
    protected function set_request_body( array $args, array $body ): array {
        $args['body'] = wp_json_encode( $body );
        return $args;
    }

    /**
     * 获取响应体
     *
     * @param array|\WP_Error $response 响应
     * @return array|null
     */
    protected function get_response_body( $response ): ?array {
        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( empty( $body ) ) {
            return null;
        }

        $decoded = json_decode( $body, true );
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * 设置响应体
     *
     * @param array $response 响应
     * @param array $body     响应体
     * @return array
     */
    protected function set_response_body( array $response, array $body ): array {
        $response['body'] = wp_json_encode( $body );
        return $response;
    }
}
