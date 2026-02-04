<?php
/**
 * 并行请求管理器
 *
 * @package WPBridge
 */

namespace WPBridge\Performance;

use WPBridge\UpdateSource\SourceModel;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 并行请求管理器类
 * 使用 WordPress Requests API 实现并行请求
 */
class ParallelRequestManager {

    /**
     * 默认超时时间（秒）
     *
     * @var int
     */
    private int $timeout = 10;

    /**
     * 构造函数
     *
     * @param int $timeout 超时时间
     */
    public function __construct( int $timeout = 10 ) {
        $this->timeout = $timeout;
    }

    /**
     * 批量检查多个更新源
     *
     * @param SourceModel[] $sources 源列表
     * @return array<string, array|null> 响应数据，键为源 ID
     */
    public function check_multiple_sources( array $sources ): array {
        if ( empty( $sources ) ) {
            return [];
        }

        $requests = [];

        foreach ( $sources as $source ) {
            $requests[ $source->id ] = [
                'url'     => $source->get_check_url(),
                'type'    => \WpOrg\Requests\Requests::GET,
                'headers' => $source->get_headers(),
            ];
        }

        Logger::debug( '开始并行请求', [ 'count' => count( $requests ) ] );

        $start = microtime( true );

        // 使用 WordPress Requests API 并行请求
        $responses = \WpOrg\Requests\Requests::request_multiple(
            $requests,
            [
                'timeout'          => $this->timeout,
                'connect_timeout'  => 5,
                'follow_redirects' => true,
                'redirects'        => 3,
            ]
        );

        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        Logger::debug( '并行请求完成', [
            'count'   => count( $requests ),
            'time_ms' => $elapsed,
        ] );

        return $this->process_responses( $responses );
    }

    /**
     * 处理响应
     *
     * @param array $responses 响应数组
     * @return array<string, array|null>
     */
    private function process_responses( array $responses ): array {
        $results = [];

        foreach ( $responses as $source_id => $response ) {
            if ( $response instanceof \WpOrg\Requests\Exception ) {
                Logger::warning( '请求失败', [
                    'source' => $source_id,
                    'error'  => $response->getMessage(),
                ] );
                $results[ $source_id ] = null;
                continue;
            }

            if ( ! $response->success ) {
                Logger::warning( '请求返回非成功状态', [
                    'source' => $source_id,
                    'status' => $response->status_code,
                ] );
                $results[ $source_id ] = null;
                continue;
            }

            $data = json_decode( $response->body, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                Logger::warning( 'JSON 解析失败', [
                    'source' => $source_id,
                    'error'  => json_last_error_msg(),
                ] );
                $results[ $source_id ] = null;
                continue;
            }

            $results[ $source_id ] = $data;
        }

        return $results;
    }

    /**
     * 批量请求 URL
     *
     * @param array $urls URL 数组，键为标识符
     * @param array $headers 公共请求头
     * @return array<string, array|null>
     */
    public function fetch_multiple( array $urls, array $headers = [] ): array {
        if ( empty( $urls ) ) {
            return [];
        }

        $requests = [];

        foreach ( $urls as $key => $url ) {
            $requests[ $key ] = [
                'url'     => $url,
                'type'    => \WpOrg\Requests\Requests::GET,
                'headers' => $headers,
            ];
        }

        $responses = \WpOrg\Requests\Requests::request_multiple(
            $requests,
            [
                'timeout'          => $this->timeout,
                'connect_timeout'  => 5,
            ]
        );

        return $this->process_responses( $responses );
    }
}
