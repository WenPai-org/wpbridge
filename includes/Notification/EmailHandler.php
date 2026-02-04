<?php
/**
 * 邮件通知处理器
 *
 * @package WPBridge
 */

namespace WPBridge\Notification;

use WPBridge\Core\Settings;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 邮件通知处理器类
 */
class EmailHandler implements HandlerInterface {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 支持的通知类型
     *
     * @var array
     */
    private array $supported_types = [ 'update', 'error', 'recovery' ];

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * 获取处理器名称
     *
     * @return string
     */
    public function get_name(): string {
        return 'email';
    }

    /**
     * 是否启用
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $notification_settings = $this->settings->get( 'notifications', [] );
        return ! empty( $notification_settings['email']['enabled'] );
    }

    /**
     * 是否支持该通知类型
     *
     * @param string $type 通知类型
     * @return bool
     */
    public function supports_type( string $type ): bool {
        $notification_settings = $this->settings->get( 'notifications', [] );
        $enabled_types         = $notification_settings['email']['types'] ?? $this->supported_types;

        return in_array( $type, $enabled_types, true );
    }

    /**
     * 发送通知
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @throws \Exception 发送失败时抛出异常
     */
    public function send( string $subject, string $message, array $data = [] ): void {
        $notification_settings = $this->settings->get( 'notifications', [] );
        $recipients            = $notification_settings['email']['recipients'] ?? [];

        if ( empty( $recipients ) ) {
            // 默认发送给管理员
            $recipients = [ get_option( 'admin_email' ) ];
        }

        // 验证收件人邮箱格式
        $valid_recipients = array_filter( $recipients, function ( $email ) {
            return is_email( $email );
        } );

        if ( empty( $valid_recipients ) ) {
            throw new \Exception( __( '没有有效的收件人邮箱', 'wpbridge' ) );
        }

        // 构建 HTML 邮件
        $html_message = $this->build_html_message( $subject, $message, $data );

        // 使用 WordPress 默认发件人，避免 SPF/DKIM 问题
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $sent = wp_mail( $valid_recipients, $subject, $html_message, $headers );

        if ( ! $sent ) {
            throw new \Exception( __( '邮件发送失败', 'wpbridge' ) );
        }
    }

    /**
     * 构建 HTML 邮件内容
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @return string
     */
    private function build_html_message( string $subject, string $message, array $data ): string {
        $site_name = get_bloginfo( 'name' );
        $site_url  = get_site_url();

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . esc_html( $subject ) . '</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0073aa; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .data-table th, .data-table td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        .data-table th { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>WPBridge</h1>
        </div>
        <div class="content">
            <p>' . nl2br( esc_html( $message ) ) . '</p>';

        // 添加数据表格
        if ( ! empty( $data ) && ! isset( $data['test'] ) ) {
            $html .= '<table class="data-table">';
            foreach ( $data as $key => $value ) {
                if ( is_scalar( $value ) ) {
                    $html .= '<tr><th>' . esc_html( $key ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
                }
            }
            $html .= '</table>';
        }

        $html .= '
        </div>
        <div class="footer">
            <p>' . sprintf(
                /* translators: %s: site name */
                esc_html__( '此邮件由 %s 的 WPBridge 插件发送', 'wpbridge' ),
                esc_html( $site_name )
            ) . '</p>
            <p><a href="' . esc_url( $site_url ) . '">' . esc_html( $site_url ) . '</a></p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }
}
