<?php
/**
 * AI 网关
 *
 * @package WPBridge
 */

namespace WPBridge\AIBridge;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Security\Validator;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI 网关类
 * 拦截并转发 AI API 请求
 */
class AIGateway {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 已注册的适配器
     *
     * @var array
     */
    private array $adapters = [];

    /**
     * 白名单域名
     *
     * @var array
     */
    private array $whitelist = [];

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings  = $settings;
        $this->whitelist = $this->get_whitelist();

        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        // 只有启用 AI 桥接时才注册钩子
        if ( ! $this->is_enabled() ) {
            return;
        }

        add_filter( 'pre_http_request', [ $this, 'intercept_request' ], 10, 3 );
    }

    /**
     * 是否启用 AI 桥接
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $ai_settings = $this->settings->get( 'ai_bridge', [] );
        return ! empty( $ai_settings['enabled'] );
    }

    /**
     * 获取桥接模式
     *
     * @return string disabled|passthrough|wpmind
     */
    public function get_mode(): string {
        $ai_settings = $this->settings->get( 'ai_bridge', [] );
        return $ai_settings['mode'] ?? 'disabled';
    }

    /**
     * 获取白名单
     *
     * @return array
     */
    private function get_whitelist(): array {
        $ai_settings = $this->settings->get( 'ai_bridge', [] );
        $whitelist   = $ai_settings['whitelist'] ?? [];

        // 默认白名单
        $default_whitelist = [
            'api.openai.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
        ];

        return array_unique( array_merge( $default_whitelist, $whitelist ) );
    }

    /**
     * 拦截 HTTP 请求
     *
     * @param false|array|\WP_Error $preempt 预处理结果
     * @param array                 $args    请求参数
     * @param string                $url     请求 URL
     * @return false|array|\WP_Error
     */
    public function intercept_request( $preempt, array $args, string $url ) {
        // 如果已经被其他过滤器处理，跳过
        if ( false !== $preempt ) {
            return $preempt;
        }

        // 检查是否是 AI API 请求
        if ( ! $this->is_ai_request( $url ) ) {
            return false;
        }

        Logger::debug( 'AI 请求拦截', [ 'url' => $url ] );

        // 根据模式处理
        $mode = $this->get_mode();

        switch ( $mode ) {
            case 'passthrough':
                return $this->handle_passthrough( $url, $args );

            case 'wpmind':
                return $this->handle_wpmind( $url, $args );

            default:
                return false;
        }
    }

    /**
     * 检查是否是 AI API 请求
     *
     * @param string $url URL
     * @return bool
     */
    private function is_ai_request( string $url ): bool {
        $host = wp_parse_url( $url, PHP_URL_HOST );

        if ( empty( $host ) ) {
            return false;
        }

        $host = strtolower( $host );

        foreach ( $this->whitelist as $allowed ) {
            if ( strtolower( $allowed ) === $host ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 透传模式处理
     *
     * @param string $url  原始 URL
     * @param array  $args 请求参数
     * @return array|\WP_Error
     */
    private function handle_passthrough( string $url, array $args ) {
        $ai_settings    = $this->settings->get( 'ai_bridge', [] );
        $custom_endpoint = $ai_settings['custom_endpoint'] ?? '';

        if ( empty( $custom_endpoint ) ) {
            Logger::warning( '透传模式未配置自定义端点' );
            return false;
        }

        // SSRF 防护：验证端点安全性
        if ( ! Validator::is_valid_url( $custom_endpoint ) ) {
            Logger::error( '自定义端点不安全', [ 'endpoint' => $custom_endpoint ] );
            return false;
        }

        // 替换 URL
        $new_url = $this->replace_endpoint( $url, $custom_endpoint );

        Logger::debug( 'AI 请求透传', [
            'original' => $url,
            'new'      => $new_url,
        ] );

        // 发送请求
        return $this->forward_request( $new_url, $args );
    }

    /**
     * WPMind 模式处理
     *
     * @param string $url  原始 URL
     * @param array  $args 请求参数
     * @return array|\WP_Error
     */
    private function handle_wpmind( string $url, array $args ) {
        // 检查 WPMind 是否可用
        if ( ! $this->is_wpmind_available() ) {
            Logger::warning( 'WPMind 不可用，回退到透传模式' );
            return $this->handle_passthrough( $url, $args );
        }

        // 使用 WPMind API
        $wpmind_endpoint = apply_filters( 'wpmind_api_endpoint', 'https://api.wpmind.cn/v1' );

        // 转换请求格式
        $converted_args = $this->convert_to_wpmind_format( $url, $args );

        Logger::debug( 'AI 请求转发到 WPMind', [
            'original' => $url,
            'endpoint' => $wpmind_endpoint,
        ] );

        return $this->forward_request( $wpmind_endpoint, $converted_args );
    }

    /**
     * 检查 WPMind 是否可用
     *
     * @return bool
     */
    private function is_wpmind_available(): bool {
        return class_exists( 'WPMind\\Core\\Plugin' ) ||
               function_exists( 'wpmind_get_api_key' );
    }

    /**
     * 替换端点
     *
     * @param string $url      原始 URL
     * @param string $endpoint 新端点
     * @return string
     */
    private function replace_endpoint( string $url, string $endpoint ): string {
        $parsed = wp_parse_url( $url );
        $path   = $parsed['path'] ?? '';
        $query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

        // 移除端点末尾的斜杠
        $endpoint = rtrim( $endpoint, '/' );

        return $endpoint . $path . $query;
    }

    /**
     * 转换为 WPMind 格式
     *
     * @param string $url  原始 URL
     * @param array  $args 请求参数
     * @return array
     */
    private function convert_to_wpmind_format( string $url, array $args ): array {
        // 获取 WPMind API Key
        $api_key = '';
        if ( function_exists( 'wpmind_get_api_key' ) ) {
            $api_key = wpmind_get_api_key();
        }

        // 更新认证头
        if ( ! empty( $api_key ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $api_key;
        }

        // 添加来源标识
        $args['headers']['X-WPBridge-Source'] = 'wpbridge';

        return $args;
    }

    /**
     * 转发请求
     *
     * @param string $url  URL
     * @param array  $args 请求参数
     * @return array|\WP_Error
     */
    private function forward_request( string $url, array $args ) {
        // 移除 pre_http_request 过滤器避免递归
        remove_filter( 'pre_http_request', [ $this, 'intercept_request' ], 10 );

        $response = wp_remote_request( $url, $args );

        // 重新添加过滤器
        add_filter( 'pre_http_request', [ $this, 'intercept_request' ], 10, 3 );

        if ( is_wp_error( $response ) ) {
            Logger::error( 'AI 请求转发失败', [
                'url'   => $url,
                'error' => $response->get_error_message(),
            ] );
        }

        return $response;
    }

    /**
     * 注册适配器
     *
     * @param string                            $name    适配器名称
     * @param Adapters\AdapterInterface $adapter 适配器实例
     */
    public function register_adapter( string $name, Adapters\AdapterInterface $adapter ): void {
        $this->adapters[ $name ] = $adapter;
        Logger::debug( '注册 AI 适配器', [ 'name' => $name ] );
    }

    /**
     * 获取适配器
     *
     * @param string $name 适配器名称
     * @return Adapters\AdapterInterface|null
     */
    public function get_adapter( string $name ): ?Adapters\AdapterInterface {
        return $this->adapters[ $name ] ?? null;
    }

    /**
     * 获取所有适配器
     *
     * @return array
     */
    public function get_adapters(): array {
        return $this->adapters;
    }

    /**
     * 获取状态信息
     *
     * @return array
     */
    public function get_status(): array {
        return [
            'enabled'           => $this->is_enabled(),
            'mode'              => $this->get_mode(),
            'wpmind_available'  => $this->is_wpmind_available(),
            'whitelist'         => $this->whitelist,
            'adapters'          => array_keys( $this->adapters ),
        ];
    }
}
