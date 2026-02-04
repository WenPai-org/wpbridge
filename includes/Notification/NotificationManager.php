<?php
/**
 * 通知管理器
 *
 * @package WPBridge
 */

namespace WPBridge\Notification;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 通知管理器类
 */
class NotificationManager {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 通知处理器
     *
     * @var array
     */
    private array $handlers = [];

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
        $this->init_handlers();
        $this->init_hooks();
    }

    /**
     * 初始化处理器
     */
    private function init_handlers(): void {
        $this->handlers = [
            'email'   => new EmailHandler( $this->settings ),
            'webhook' => new WebhookHandler( $this->settings ),
        ];

        // 允许第三方扩展通知处理器
        $this->handlers = apply_filters(
            'wpbridge_notification_handlers',
            $this->handlers,
            $this->settings
        );
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        add_action( 'wpbridge_update_available', [ $this, 'on_update_available' ], 10, 2 );
        add_action( 'wpbridge_source_error', [ $this, 'on_source_error' ], 10, 2 );
        add_action( 'wpbridge_source_recovered', [ $this, 'on_source_recovered' ], 10, 1 );
    }

    /**
     * 发送通知
     *
     * @param string $type    通知类型
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     */
    public function send( string $type, string $subject, string $message, array $data = [] ): void {
        // 速率限制检查：防止通知轰炸
        $rate_limit_key = 'wpbridge_notification_' . md5( $type . $subject );
        if ( get_transient( $rate_limit_key ) ) {
            Logger::debug( '通知被速率限制', [ 'type' => $type, 'subject' => $subject ] );
            return;
        }

        // 设置 5 分钟冷却时间
        set_transient( $rate_limit_key, true, 5 * MINUTE_IN_SECONDS );

        foreach ( $this->handlers as $name => $handler ) {
            if ( $handler->is_enabled() && $handler->supports_type( $type ) ) {
                try {
                    $handler->send( $subject, $message, $data );
                    Logger::debug( '通知发送成功', [
                        'handler' => $name,
                        'type'    => $type,
                    ] );
                } catch ( \Exception $e ) {
                    Logger::error( '通知发送失败', [
                        'handler' => $name,
                        'error'   => $e->getMessage(),
                    ] );
                }
            }
        }
    }

    /**
     * 更新可用时触发
     *
     * @param string $slug    插件/主题 slug
     * @param array  $update  更新信息
     */
    public function on_update_available( string $slug, array $update ): void {
        $subject = sprintf(
            /* translators: %s: plugin/theme name */
            __( '[WPBridge] %s 有新版本可用', 'wpbridge' ),
            $update['name'] ?? $slug
        );

        $message = sprintf(
            /* translators: 1: name, 2: current version, 3: new version */
            __( '%1$s 有新版本可用。当前版本: %2$s，新版本: %3$s', 'wpbridge' ),
            $update['name'] ?? $slug,
            $update['current_version'] ?? 'unknown',
            $update['new_version'] ?? 'unknown'
        );

        $this->send( 'update', $subject, $message, $update );
    }

    /**
     * 源错误时触发
     *
     * @param string $source_id 源 ID
     * @param string $error     错误信息
     */
    public function on_source_error( string $source_id, string $error ): void {
        $subject = sprintf(
            /* translators: %s: source ID */
            __( '[WPBridge] 更新源 %s 出现错误', 'wpbridge' ),
            $source_id
        );

        $message = sprintf(
            /* translators: 1: source ID, 2: error message */
            __( '更新源 %1$s 出现错误: %2$s', 'wpbridge' ),
            $source_id,
            $error
        );

        $this->send( 'error', $subject, $message, [
            'source_id' => $source_id,
            'error'     => $error,
        ] );
    }

    /**
     * 源恢复时触发
     *
     * @param string $source_id 源 ID
     */
    public function on_source_recovered( string $source_id ): void {
        $subject = sprintf(
            /* translators: %s: source ID */
            __( '[WPBridge] 更新源 %s 已恢复', 'wpbridge' ),
            $source_id
        );

        $message = sprintf(
            /* translators: %s: source ID */
            __( '更新源 %s 已恢复正常', 'wpbridge' ),
            $source_id
        );

        $this->send( 'recovery', $subject, $message, [
            'source_id' => $source_id,
        ] );
    }

    /**
     * 获取处理器
     *
     * @param string $name 处理器名称
     * @return HandlerInterface|null
     */
    public function get_handler( string $name ): ?HandlerInterface {
        return $this->handlers[ $name ] ?? null;
    }

    /**
     * 获取所有处理器
     *
     * @return array
     */
    public function get_handlers(): array {
        return $this->handlers;
    }

    /**
     * 测试通知
     *
     * @param string $handler_name 处理器名称
     * @return bool
     */
    public function test( string $handler_name ): bool {
        $handler = $this->get_handler( $handler_name );

        if ( null === $handler ) {
            return false;
        }

        try {
            $handler->send(
                __( '[WPBridge] 测试通知', 'wpbridge' ),
                __( '这是一条测试通知，如果您收到此消息，说明通知配置正确。', 'wpbridge' ),
                [ 'test' => true ]
            );
            return true;
        } catch ( \Exception $e ) {
            Logger::error( '测试通知失败', [
                'handler' => $handler_name,
                'error'   => $e->getMessage(),
            ] );
            return false;
        }
    }
}
