<?php
/**
 * 通知处理器接口
 *
 * @package WPBridge
 */

namespace WPBridge\Notification;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 通知处理器接口
 */
interface HandlerInterface {

    /**
     * 发送通知
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @throws \Exception 发送失败时抛出异常
     */
    public function send( string $subject, string $message, array $data = [] ): void;

    /**
     * 是否启用
     *
     * @return bool
     */
    public function is_enabled(): bool;

    /**
     * 是否支持该通知类型
     *
     * @param string $type 通知类型
     * @return bool
     */
    public function supports_type( string $type ): bool;

    /**
     * 获取处理器名称
     *
     * @return string
     */
    public function get_name(): string;
}
