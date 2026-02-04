<?php
/**
 * 健康检查器
 *
 * @package WPBridge
 */

namespace WPBridge\Cache;

use WPBridge\UpdateSource\SourceModel;
use WPBridge\UpdateSource\Handlers\HealthStatus;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 健康检查器类
 */
class HealthChecker {

    /**
     * 缓存管理器
     *
     * @var CacheManager
     */
    private CacheManager $cache;

    /**
     * 健康状态缓存 TTL（1 小时）
     *
     * @var int
     */
    const HEALTH_CACHE_TTL = 3600;

    /**
     * 失败源冷却时间（30 分钟）
     *
     * @var int
     */
    const FAILED_COOLDOWN = 1800;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->cache = new CacheManager();
    }

    /**
     * 检查源健康状态
     *
     * @param SourceModel $source 源模型
     * @param bool        $force  是否强制检查
     * @return HealthStatus
     */
    public function check( SourceModel $source, bool $force = false ): HealthStatus {
        $cache_key = 'health_' . $source->id;

        // 检查缓存
        if ( ! $force ) {
            $cached = $this->cache->get( $cache_key );
            if ( false !== $cached && $cached instanceof HealthStatus ) {
                return $cached;
            }
        }

        // 检查是否在冷却期
        if ( ! $force && $this->is_in_cooldown( $source->id ) ) {
            return HealthStatus::failed( __( '源在冷却期内', 'wpbridge' ) );
        }

        // 执行健康检查
        $handler = $source->get_handler();

        if ( null === $handler ) {
            $status = HealthStatus::failed( __( '无法获取处理器', 'wpbridge' ) );
        } else {
            $status = $handler->test_connection();
        }

        // 缓存结果
        $ttl = $status->is_healthy() ? self::HEALTH_CACHE_TTL : self::FAILED_COOLDOWN;
        $this->cache->set( $cache_key, $status, $ttl );

        // 如果失败，设置冷却
        if ( ! $status->is_available() ) {
            $this->set_cooldown( $source->id );
        }

        Logger::debug( '健康检查完成', [
            'source' => $source->id,
            'status' => $status->status,
            'time'   => $status->response_time,
        ] );

        return $status;
    }

    /**
     * 批量检查源健康状态
     *
     * @param SourceModel[] $sources 源列表
     * @return array<string, HealthStatus>
     */
    public function check_all( array $sources ): array {
        $results = [];

        foreach ( $sources as $source ) {
            $results[ $source->id ] = $this->check( $source );
        }

        return $results;
    }

    /**
     * 获取源健康状态（仅从缓存）
     *
     * @param string $source_id 源 ID
     * @return HealthStatus|null
     */
    public function get_status( string $source_id ): ?HealthStatus {
        $cached = $this->cache->get( 'health_' . $source_id );

        if ( false !== $cached && $cached instanceof HealthStatus ) {
            return $cached;
        }

        return null;
    }

    /**
     * 检查源是否在冷却期
     *
     * @param string $source_id 源 ID
     * @return bool
     */
    public function is_in_cooldown( string $source_id ): bool {
        $cooldown = $this->cache->get( 'cooldown_' . $source_id );
        return false !== $cooldown;
    }

    /**
     * 设置源冷却
     *
     * @param string $source_id 源 ID
     */
    private function set_cooldown( string $source_id ): void {
        $this->cache->set( 'cooldown_' . $source_id, time(), self::FAILED_COOLDOWN );

        Logger::info( '源进入冷却期', [
            'source'   => $source_id,
            'duration' => self::FAILED_COOLDOWN,
        ] );
    }

    /**
     * 清除源冷却
     *
     * @param string $source_id 源 ID
     */
    public function clear_cooldown( string $source_id ): void {
        $this->cache->delete( 'cooldown_' . $source_id );
    }

    /**
     * 清除所有健康状态缓存
     */
    public function clear_all(): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_wpbridge_health_' ) . '%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_wpbridge_cooldown_' ) . '%'
            )
        );
    }
}
