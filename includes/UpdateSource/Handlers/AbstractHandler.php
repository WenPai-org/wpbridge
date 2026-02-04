<?php
/**
 * 抽象处理器基类
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\UpdateSource\SourceModel;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 抽象处理器基类
 */
abstract class AbstractHandler implements HandlerInterface {

    /**
     * 源模型
     *
     * @var SourceModel
     */
    protected SourceModel $source;

    /**
     * 请求超时时间（秒）
     *
     * @var int
     */
    protected int $timeout = 10;

    /**
     * 构造函数
     *
     * @param SourceModel $source 源模型
     */
    public function __construct( SourceModel $source ) {
        $this->source = $source;
    }

    /**
     * 获取能力列表
     *
     * @return array
     */
    public function get_capabilities(): array {
        return [
            'auth'     => 'none',
            'version'  => 'json',
            'download' => 'direct',
        ];
    }

    /**
     * 获取检查 URL
     *
     * @return string
     */
    public function get_check_url(): string {
        return $this->source->api_url;
    }

    /**
     * 获取请求头
     *
     * @return array
     */
    public function get_headers(): array {
        return $this->source->get_headers();
    }

    /**
     * 验证认证信息
     *
     * @return bool
     */
    public function validate_auth(): bool {
        // 默认不需要认证
        return true;
    }

    /**
     * 测试连通性
     *
     * @return HealthStatus
     */
    public function test_connection(): HealthStatus {
        $start = microtime( true );

        $response = wp_remote_get( $this->get_check_url(), [
            'timeout' => $this->timeout,
            'headers' => $this->get_headers(),
        ] );

        $elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return HealthStatus::failed( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 200 && $code < 300 ) {
            return HealthStatus::healthy( $elapsed );
        }

        if ( $code >= 500 ) {
            return HealthStatus::failed( sprintf( 'HTTP %d', $code ) );
        }

        return HealthStatus::degraded( $elapsed, sprintf( 'HTTP %d', $code ) );
    }

    /**
     * 发起 HTTP 请求
     *
     * @param string $url     URL
     * @param array  $args    请求参数
     * @return array|null
     */
    protected function request( string $url, array $args = [] ): ?array {
        $defaults = [
            'timeout' => $this->timeout,
            'headers' => $this->get_headers(),
        ];

        $args     = wp_parse_args( $args, $defaults );
        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            Logger::error( '请求失败', [
                'url'   => $url,
                'error' => $response->get_error_message(),
            ] );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            Logger::warning( '请求返回非 2xx 状态码', [
                'url'  => $url,
                'code' => $code,
            ] );
            return null;
        }

        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            Logger::error( 'JSON 解析失败', [
                'url'   => $url,
                'error' => json_last_error_msg(),
            ] );
            return null;
        }

        return $data;
    }

    /**
     * 比较版本号
     *
     * @param string $current 当前版本
     * @param string $remote  远程版本
     * @return bool 远程版本是否更新
     */
    protected function is_newer_version( string $current, string $remote ): bool {
        return version_compare( $remote, $current, '>' );
    }
}
