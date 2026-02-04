<?php
/**
 * Yoast SEO AI 适配器
 *
 * @package WPBridge
 */

namespace WPBridge\AIBridge\Adapters;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Yoast SEO AI 适配器类
 * 适配 Yoast SEO Premium 的 AI 功能
 */
class YoastAdapter extends AbstractAdapter {

    /**
     * 支持的插件
     *
     * @var array
     */
    protected array $supported_plugins = [
        'wordpress-seo-premium',
        'wordpress-seo',
    ];

    /**
     * URL 匹配模式
     *
     * @var array
     */
    protected array $url_patterns = [
        '#api\.openai\.com/v1/chat/completions#',
        '#yoast\.com.*ai#i',
    ];

    /**
     * 获取适配器名称
     *
     * @return string
     */
    public function get_name(): string {
        return 'yoast';
    }

    /**
     * 获取适配器描述
     *
     * @return string
     */
    public function get_description(): string {
        return __( 'Yoast SEO Premium AI 功能适配', 'wpbridge' );
    }

    /**
     * 转换请求
     *
     * @param string $url  原始 URL
     * @param array  $args 原始参数
     * @return array [url, args]
     */
    public function transform_request( string $url, array $args ): array {
        $body = $this->get_request_body( $args );

        if ( null === $body ) {
            return [ $url, $args ];
        }

        $this->log( 'Yoast AI 请求转换', [
            'model' => $body['model'] ?? 'unknown',
        ] );

        // 转换模型名称（如果需要）
        if ( isset( $body['model'] ) ) {
            $body['model'] = $this->map_model( $body['model'] );
        }

        // 添加系统提示优化
        if ( isset( $body['messages'] ) && is_array( $body['messages'] ) ) {
            $body['messages'] = $this->optimize_messages( $body['messages'] );
        }

        $args = $this->set_request_body( $args, $body );

        return [ $url, $args ];
    }

    /**
     * 转换响应
     *
     * @param array|\WP_Error $response 原始响应
     * @return array|\WP_Error
     */
    public function transform_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->get_response_body( $response );

        if ( null === $body ) {
            return $response;
        }

        $this->log( 'Yoast AI 响应转换', [
            'has_choices' => isset( $body['choices'] ),
        ] );

        // 响应格式通常兼容，无需转换
        return $response;
    }

    /**
     * 映射模型名称
     *
     * @param string $model 原始模型名称
     * @return string
     */
    private function map_model( string $model ): string {
        $model_map = [
            'gpt-4'         => 'gpt-4',
            'gpt-4-turbo'   => 'gpt-4-turbo',
            'gpt-3.5-turbo' => 'gpt-3.5-turbo',
        ];

        return $model_map[ $model ] ?? $model;
    }

    /**
     * 优化消息
     *
     * @param array $messages 消息列表
     * @return array
     */
    private function optimize_messages( array $messages ): array {
        // 可以在这里添加中文优化提示
        // 例如：添加系统消息要求返回中文内容

        return $messages;
    }
}
