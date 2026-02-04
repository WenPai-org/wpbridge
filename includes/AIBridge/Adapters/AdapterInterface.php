<?php
/**
 * AI 适配器接口
 *
 * @package WPBridge
 */

namespace WPBridge\AIBridge\Adapters;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI 适配器接口
 */
interface AdapterInterface {

    /**
     * 获取适配器名称
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * 获取适配器描述
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * 检查是否支持该插件
     *
     * @param string $plugin_slug 插件 slug
     * @return bool
     */
    public function supports( string $plugin_slug ): bool;

    /**
     * 检查请求是否匹配
     *
     * @param string $url  请求 URL
     * @param array  $args 请求参数
     * @return bool
     */
    public function matches( string $url, array $args ): bool;

    /**
     * 转换请求
     *
     * @param string $url  原始 URL
     * @param array  $args 原始参数
     * @return array [url, args]
     */
    public function transform_request( string $url, array $args ): array;

    /**
     * 转换响应
     *
     * @param array|\WP_Error $response 原始响应
     * @return array|\WP_Error
     */
    public function transform_response( $response );

    /**
     * 是否启用
     *
     * @return bool
     */
    public function is_enabled(): bool;
}
