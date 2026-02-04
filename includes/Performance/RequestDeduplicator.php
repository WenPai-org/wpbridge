<?php
/**
 * 请求去重器
 *
 * @package WPBridge
 */

namespace WPBridge\Performance;

use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 请求去重器类
 * 防止短时间内重复请求同一源
 */
class RequestDeduplicator {

    /**
     * 合并窗口时间（秒）
     *
     * @var int
     */
    const MERGE_WINDOW = 5;

    /**
     * 锁前缀
     *
     * @var string
     */
    const LOCK_PREFIX = 'wpbridge_lock_';

    /**
     * 尝试获取锁
     *
     * @param string $source_id 源 ID
     * @return bool 是否成功获取锁
     */
    public function acquire_lock( string $source_id ): bool {
        $lock_key = self::LOCK_PREFIX . $source_id;

        // 检查是否已有锁
        if ( get_transient( $lock_key ) ) {
            Logger::debug( '请求被去重', [ 'source' => $source_id ] );
            return false;
        }

        // 设置锁
        set_transient( $lock_key, time(), self::MERGE_WINDOW );
        return true;
    }

    /**
     * 释放锁
     *
     * @param string $source_id 源 ID
     */
    public function release_lock( string $source_id ): void {
        delete_transient( self::LOCK_PREFIX . $source_id );
    }

    /**
     * 检查是否有锁
     *
     * @param string $source_id 源 ID
     * @return bool
     */
    public function has_lock( string $source_id ): bool {
        return (bool) get_transient( self::LOCK_PREFIX . $source_id );
    }

    /**
     * 等待锁释放并获取结果
     *
     * @param string   $source_id 源 ID
     * @param callable $callback  获取结果的回调
     * @param int      $max_wait  最大等待时间（秒）
     * @return mixed
     */
    public function wait_and_get( string $source_id, callable $callback, int $max_wait = 10 ) {
        $start = time();

        while ( $this->has_lock( $source_id ) ) {
            if ( ( time() - $start ) >= $max_wait ) {
                Logger::warning( '等待锁超时', [ 'source' => $source_id ] );
                break;
            }
            usleep( 100000 ); // 100ms
        }

        return $callback();
    }

    /**
     * 带锁执行操作
     *
     * @param string   $source_id 源 ID
     * @param callable $callback  操作回调
     * @return mixed
     */
    public function execute_with_lock( string $source_id, callable $callback ) {
        if ( ! $this->acquire_lock( $source_id ) ) {
            // 已有请求在进行中，等待结果
            return $this->wait_and_get( $source_id, $callback );
        }

        try {
            $result = $callback();
            return $result;
        } finally {
            $this->release_lock( $source_id );
        }
    }
}
