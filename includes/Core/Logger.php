<?php
/**
 * 日志系统
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 日志类
 */
class Logger {

    /**
     * 日志级别
     */
    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /**
     * 选项名称
     */
    const OPTION_LOGS = 'wpbridge_logs';

    /**
     * 最大日志条数
     */
    const MAX_LOGS = 100;

    /**
     * 设置实例
     *
     * @var Settings|null
     */
    private static ?Settings $settings = null;

    /**
     * 设置 Settings 实例
     *
     * @param Settings $settings
     */
    public static function set_settings( Settings $settings ): void {
        self::$settings = $settings;
    }

    /**
     * 记录调试日志
     *
     * @param string $message 消息
     * @param array  $context 上下文
     */
    public static function debug( string $message, array $context = [] ): void {
        self::log( self::LEVEL_DEBUG, $message, $context );
    }

    /**
     * 记录信息日志
     *
     * @param string $message 消息
     * @param array  $context 上下文
     */
    public static function info( string $message, array $context = [] ): void {
        self::log( self::LEVEL_INFO, $message, $context );
    }

    /**
     * 记录警告日志
     *
     * @param string $message 消息
     * @param array  $context 上下文
     */
    public static function warning( string $message, array $context = [] ): void {
        self::log( self::LEVEL_WARNING, $message, $context );
    }

    /**
     * 记录错误日志
     *
     * @param string $message 消息
     * @param array  $context 上下文
     */
    public static function error( string $message, array $context = [] ): void {
        self::log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * 记录日志
     *
     * @param string $level   日志级别
     * @param string $message 消息
     * @param array  $context 上下文
     */
    public static function log( string $level, string $message, array $context = [] ): void {
        // 检查是否启用调试模式（错误日志始终记录）
        if ( $level !== self::LEVEL_ERROR ) {
            if ( null === self::$settings ) {
                self::$settings = new Settings();
            }
            if ( ! self::$settings->is_debug() ) {
                return;
            }
        }

        $logs = get_option( self::OPTION_LOGS, [] );

        // 添加新日志
        $logs[] = [
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => $message,
            'context' => self::sanitize_context( $context ),
        ];

        // 限制日志数量
        if ( count( $logs ) > self::MAX_LOGS ) {
            $logs = array_slice( $logs, -self::MAX_LOGS );
        }

        update_option( self::OPTION_LOGS, $logs, false );
    }

    /**
     * 清理上下文数据（脱敏）
     *
     * @param array $context 上下文
     * @return array
     */
    private static function sanitize_context( array $context ): array {
        $sensitive_keys = [ 'auth_token', 'api_key', 'password', 'secret' ];

        foreach ( $context as $key => $value ) {
            if ( in_array( strtolower( $key ), $sensitive_keys, true ) ) {
                $context[ $key ] = '***REDACTED***';
            } elseif ( is_array( $value ) ) {
                $context[ $key ] = self::sanitize_context( $value );
            }
        }

        return $context;
    }

    /**
     * 获取所有日志
     *
     * @param string|null $level 过滤级别
     * @return array
     */
    public static function get_logs( ?string $level = null ): array {
        $logs = get_option( self::OPTION_LOGS, [] );

        if ( null !== $level ) {
            $logs = array_filter( $logs, function( $log ) use ( $level ) {
                return $log['level'] === $level;
            } );
        }

        // 按时间倒序
        return array_reverse( $logs );
    }

    /**
     * 清除所有日志
     */
    public static function clear(): void {
        delete_option( self::OPTION_LOGS );
    }
}
