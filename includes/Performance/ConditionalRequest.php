<?php
/**
 * 条件请求处理器
 *
 * @package WPBridge
 */

namespace WPBridge\Performance;

use WPBridge\Cache\CacheManager;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 条件请求处理器类
 * 使用 ETag 和 Last-Modified 减少数据传输
 */
class ConditionalRequest {

    /**
     * 缓存管理器
     *
     * @var CacheManager
     */
    private CacheManager $cache;

    /**
     * 缓存前缀
     *
     * @var string
     */
    const CACHE_PREFIX = 'conditional_';

    /**
     * 构造函数
     */
    public function __construct() {
        $this->cache = new CacheManager();
    }

    /**
     * 构建条件请求头
     *
     * @param string $source_id 源 ID
     * @return array
     */
    public function build_headers( string $source_id ): array {
        $cached = $this->get_cached_metadata( $source_id );
        $headers = [];

        if ( ! empty( $cached['etag'] ) ) {
            $headers['If-None-Match'] = $cached['etag'];
        }

        if ( ! empty( $cached['last_modified'] ) ) {
            $headers['If-Modified-Since'] = $cached['last_modified'];
        }

        return $headers;
    }

    /**
     * 处理响应
     *
     * @param string $source_id 源 ID
     * @param array  $response  响应数据
     * @param array  $headers   响应头
     * @return array|null 处理后的数据，304 时返回缓存数据
     */
    public function process_response( string $source_id, ?array $response, array $headers ): ?array {
        // 保存元数据
        $metadata = [];

        if ( ! empty( $headers['etag'] ) ) {
            $metadata['etag'] = $headers['etag'];
        }

        if ( ! empty( $headers['last-modified'] ) ) {
            $metadata['last_modified'] = $headers['last-modified'];
        }

        if ( ! empty( $metadata ) ) {
            $this->save_metadata( $source_id, $metadata );
        }

        // 如果有新数据，缓存并返回
        if ( null !== $response ) {
            $this->save_cached_data( $source_id, $response );
            return $response;
        }

        // 返回缓存数据
        return $this->get_cached_data( $source_id );
    }

    /**
     * 处理 304 响应
     *
     * @param string $source_id 源 ID
     * @return array|null 缓存的数据
     */
    public function handle_not_modified( string $source_id ): ?array {
        Logger::debug( '304 Not Modified', [ 'source' => $source_id ] );
        return $this->get_cached_data( $source_id );
    }

    /**
     * 获取缓存的元数据
     *
     * @param string $source_id 源 ID
     * @return array
     */
    private function get_cached_metadata( string $source_id ): array {
        $cached = $this->cache->get( self::CACHE_PREFIX . 'meta_' . $source_id );
        return is_array( $cached ) ? $cached : [];
    }

    /**
     * 保存元数据
     *
     * @param string $source_id 源 ID
     * @param array  $metadata  元数据
     */
    private function save_metadata( string $source_id, array $metadata ): void {
        $this->cache->set(
            self::CACHE_PREFIX . 'meta_' . $source_id,
            $metadata,
            WEEK_IN_SECONDS
        );
    }

    /**
     * 获取缓存的数据
     *
     * @param string $source_id 源 ID
     * @return array|null
     */
    private function get_cached_data( string $source_id ): ?array {
        $cached = $this->cache->get( self::CACHE_PREFIX . 'data_' . $source_id );
        return is_array( $cached ) ? $cached : null;
    }

    /**
     * 保存缓存数据
     *
     * @param string $source_id 源 ID
     * @param array  $data      数据
     */
    private function save_cached_data( string $source_id, array $data ): void {
        $this->cache->set(
            self::CACHE_PREFIX . 'data_' . $source_id,
            $data,
            DAY_IN_SECONDS
        );
    }
}
