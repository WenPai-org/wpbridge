<?php
/**
 * Rank Math AI 适配器
 *
 * @package WPBridge
 */

namespace WPBridge\AIBridge\Adapters;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rank Math AI 适配器类
 * 适配 Rank Math SEO 的 Content AI 功能
 */
class RankMathAdapter extends AbstractAdapter {

    /**
     * 支持的插件
     *
     * @var array
     */
    protected array $supported_plugins = [
        'seo-by-rank-math',
        'seo-by-rank-math-pro',
    ];

    /**
     * URL 匹配模式
     *
     * @var array
     */
    protected array $url_patterns = [
        '#api\.openai\.com/v1/chat/completions#',
        '#rankmath\.com.*api#i',
        '#content-ai\.rankmath\.com#i',
    ];

    /**
     * 获取适配器名称
     *
     * @return string
     */
    public function get_name(): string {
        return 'rankmath';
    }

    /**
     * 获取适配器描述
     *
     * @return string
     */
    public function get_description(): string {
        return __( 'Rank Math Content AI 功能适配', 'wpbridge' );
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

        $this->log( 'Rank Math AI 请求转换', [
            'model' => $body['model'] ?? 'unknown',
            'type'  => $this->detect_request_type( $body ),
        ] );

        // 转换模型名称
        if ( isset( $body['model'] ) ) {
            $body['model'] = $this->map_model( $body['model'] );
        }

        // 根据请求类型优化
        $request_type = $this->detect_request_type( $body );
        $body         = $this->optimize_for_type( $body, $request_type );

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

        $this->log( 'Rank Math AI 响应转换', [
            'has_choices' => isset( $body['choices'] ),
        ] );

        return $response;
    }

    /**
     * 检测请求类型
     *
     * @param array $body 请求体
     * @return string
     */
    private function detect_request_type( array $body ): string {
        if ( ! isset( $body['messages'] ) || ! is_array( $body['messages'] ) ) {
            return 'unknown';
        }

        $content = '';
        foreach ( $body['messages'] as $message ) {
            if ( isset( $message['content'] ) ) {
                $content .= $message['content'] . ' ';
            }
        }

        $content = strtolower( $content );

        if ( strpos( $content, 'title' ) !== false || strpos( $content, '标题' ) !== false ) {
            return 'title';
        }

        if ( strpos( $content, 'description' ) !== false || strpos( $content, '描述' ) !== false ) {
            return 'description';
        }

        if ( strpos( $content, 'keyword' ) !== false || strpos( $content, '关键词' ) !== false ) {
            return 'keyword';
        }

        if ( strpos( $content, 'content' ) !== false || strpos( $content, '内容' ) !== false ) {
            return 'content';
        }

        return 'general';
    }

    /**
     * 根据类型优化请求
     *
     * @param array  $body 请求体
     * @param string $type 请求类型
     * @return array
     */
    private function optimize_for_type( array $body, string $type ): array {
        // 可以根据不同类型添加优化逻辑
        // 例如：为中文内容生成添加特定提示

        return $body;
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
}
