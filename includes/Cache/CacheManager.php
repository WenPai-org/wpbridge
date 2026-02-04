<?php
/**
 * 缓存管理器
 *
 * @package WPBridge
 */

namespace WPBridge\Cache;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 缓存管理器类
 */
class CacheManager {

    /**
     * 缓存组名
     *
     * @var string
     */
    const CACHE_GROUP = 'wpbridge';

    /**
     * 默认 TTL（12 小时）
     *
     * @var int
     */
    const DEFAULT_TTL = 43200;

    /**
     * 获取缓存
     *
     * @param string $key 缓存键
     * @return mixed|false
     */
    public function get( string $key ) {
        // 优先使用对象缓存
        if ( wp_using_ext_object_cache() ) {
            $value = wp_cache_get( $key, self::CACHE_GROUP );
            if ( false !== $value ) {
                return $value;
            }
        }

        // 降级到 transient
        return get_transient( 'wpbridge_' . $key );
    }

    /**
     * 设置缓存
     *
     * @param string $key   缓存键
     * @param mixed  $value 缓存值
     * @param int    $ttl   过期时间（秒）
     * @return bool
     */
    public function set( string $key, $value, int $ttl = self::DEFAULT_TTL ): bool {
        // 使用对象缓存
        if ( wp_using_ext_object_cache() ) {
            wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
        }

        // 同时存储到 transient
        return set_transient( 'wpbridge_' . $key, $value, $ttl );
    }

    /**
     * 删除缓存
     *
     * @param string $key 缓存键
     * @return bool
     */
    public function delete( string $key ): bool {
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( $key, self::CACHE_GROUP );
        }

        return delete_transient( 'wpbridge_' . $key );
    }

    /**
     * 获取带过期缓存兜底的值
     *
     * @param string   $key      缓存键
     * @param callable $callback 获取新值的回调
     * @param int      $ttl      正常 TTL
     * @param int      $stale_ttl 过期缓存可用时间
     * @return mixed
     */
    public function get_with_fallback( string $key, callable $callback, int $ttl = self::DEFAULT_TTL, int $stale_ttl = 604800 ) {
        // 尝试获取正常缓存
        $value = $this->get( $key );

        if ( false !== $value ) {
            return $value;
        }

        // 尝试获取新值
        try {
            $new_value = $callback();

            if ( null !== $new_value && false !== $new_value ) {
                $this->set( $key, $new_value, $ttl );

                // 同时存储一份过期缓存备份
                $this->set( $key . '_stale', $new_value, $stale_ttl );

                return $new_value;
            }
        } catch ( \Exception $e ) {
            // 获取新值失败，尝试使用过期缓存
        }

        // 尝试使用过期缓存
        $stale_value = $this->get( $key . '_stale' );

        if ( false !== $stale_value ) {
            return $stale_value;
        }

        return false;
    }

    /**
     * 清除所有缓存
     */
    public function flush(): void {
        global $wpdb;

        // 清除 transient（使用 prepare 防止 SQL 注入）
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_wpbridge_' ) . '%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_timeout_wpbridge_' ) . '%'
            )
        );

        // 清除对象缓存组（不使用 flush 避免影响其他插件）
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( 'wpbridge', 'wpbridge' );
        }
    }

    /**
     * 获取缓存统计
     *
     * @return array
     */
    public function get_stats(): array {
        global $wpdb;

        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpbridge_%'"
        );

        return [
            'transient_count'    => (int) $count,
            'object_cache'       => wp_using_ext_object_cache(),
            'object_cache_type'  => $this->get_object_cache_type(),
        ];
    }

    /**
     * 获取对象缓存类型
     *
     * @return string
     */
    private function get_object_cache_type(): string {
        if ( ! wp_using_ext_object_cache() ) {
            return 'none';
        }

        global $wp_object_cache;

        if ( isset( $wp_object_cache->redis ) || class_exists( 'Redis' ) ) {
            return 'redis';
        }

        if ( isset( $wp_object_cache->mc ) || class_exists( 'Memcached' ) ) {
            return 'memcached';
        }

        return 'unknown';
    }
}
