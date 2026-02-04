<?php
/**
 * Webhook 通知处理器
 *
 * @package WPBridge
 */

namespace WPBridge\Notification;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Security\Validator;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Webhook 通知处理器类
 */
class WebhookHandler implements HandlerInterface {

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
        return 'webhook';
    }

    /**
     * 是否启用
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $notification_settings = $this->settings->get( 'notifications', [] );
        return ! empty( $notification_settings['webhook']['enabled'] ) &&
               ! empty( $notification_settings['webhook']['url'] );
    }

    /**
     * 是否支持该通知类型
     *
     * @param string $type 通知类型
     * @return bool
     */
    public function supports_type( string $type ): bool {
        $notification_settings = $this->settings->get( 'notifications', [] );
        $enabled_types         = $notification_settings['webhook']['types'] ?? $this->supported_types;

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
        $webhook_url           = $notification_settings['webhook']['url'] ?? '';
        $webhook_secret        = $notification_settings['webhook']['secret'] ?? '';
        $webhook_format        = $notification_settings['webhook']['format'] ?? 'json';

        if ( empty( $webhook_url ) ) {
            throw new \Exception( __( 'Webhook URL 未配置', 'wpbridge' ) );
        }

        // SSRF 防护：验证 URL 安全性
        if ( ! Validator::is_valid_url( $webhook_url ) ) {
            throw new \Exception( __( 'Webhook URL 不安全，禁止访问内网地址', 'wpbridge' ) );
        }

        // 构建 payload
        $payload = $this->build_payload( $subject, $message, $data, $webhook_format );

        // 构建请求头
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'WPBridge/' . WPBRIDGE_VERSION,
        ];

        // 添加签名
        if ( ! empty( $webhook_secret ) ) {
            $signature              = $this->generate_signature( $payload, $webhook_secret );
            $headers['X-WPBridge-Signature'] = $signature;
        }

        // 发送请求
        $response = wp_remote_post( $webhook_url, [
            'headers' => $headers,
            'body'    => $payload,
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            throw new \Exception(
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Webhook 返回错误状态码: %d', 'wpbridge' ),
                    $status_code
                )
            );
        }

        Logger::debug( 'Webhook 发送成功', [
            'url'         => $webhook_url,
            'status_code' => $status_code,
        ] );
    }

    /**
     * 构建 payload
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @param string $format  格式
     * @return string
     */
    private function build_payload( string $subject, string $message, array $data, string $format ): string {
        $base_payload = [
            'event'     => $data['type'] ?? 'notification',
            'subject'   => $subject,
            'message'   => $message,
            'timestamp' => current_time( 'c' ),
            'site'      => [
                'name' => get_bloginfo( 'name' ),
                'url'  => get_site_url(),
            ],
            'data'      => $data,
        ];

        switch ( $format ) {
            case 'slack':
                return $this->format_slack( $subject, $message, $data );

            case 'discord':
                return $this->format_discord( $subject, $message, $data );

            case 'teams':
                return $this->format_teams( $subject, $message, $data );

            default:
                return wp_json_encode( $base_payload );
        }
    }

    /**
     * Slack 格式
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @return string
     */
    private function format_slack( string $subject, string $message, array $data ): string {
        $payload = [
            'text'        => $subject,
            'attachments' => [
                [
                    'color'  => $this->get_color_for_type( $data['type'] ?? 'info' ),
                    'text'   => $message,
                    'footer' => 'WPBridge | ' . get_site_url(),
                    'ts'     => time(),
                ],
            ],
        ];

        return wp_json_encode( $payload );
    }

    /**
     * Discord 格式
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @return string
     */
    private function format_discord( string $subject, string $message, array $data ): string {
        $payload = [
            'embeds' => [
                [
                    'title'       => $subject,
                    'description' => $message,
                    'color'       => $this->get_color_int_for_type( $data['type'] ?? 'info' ),
                    'footer'      => [
                        'text' => 'WPBridge | ' . get_site_url(),
                    ],
                    'timestamp'   => gmdate( 'c' ),
                ],
            ],
        ];

        return wp_json_encode( $payload );
    }

    /**
     * Microsoft Teams 格式
     *
     * @param string $subject 主题
     * @param string $message 消息
     * @param array  $data    附加数据
     * @return string
     */
    private function format_teams( string $subject, string $message, array $data ): string {
        $payload = [
            '@type'      => 'MessageCard',
            '@context'   => 'http://schema.org/extensions',
            'themeColor' => $this->get_color_hex_for_type( $data['type'] ?? 'info' ),
            'summary'    => $subject,
            'sections'   => [
                [
                    'activityTitle' => $subject,
                    'text'          => $message,
                ],
            ],
        ];

        return wp_json_encode( $payload );
    }

    /**
     * 生成签名
     *
     * @param string $payload 负载
     * @param string $secret  密钥
     * @return string
     */
    private function generate_signature( string $payload, string $secret ): string {
        return 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
    }

    /**
     * 获取类型对应的颜色（Slack 格式）
     *
     * @param string $type 类型
     * @return string
     */
    private function get_color_for_type( string $type ): string {
        $colors = [
            'update'   => 'good',
            'error'    => 'danger',
            'recovery' => 'good',
            'warning'  => 'warning',
        ];

        return $colors[ $type ] ?? '#0073aa';
    }

    /**
     * 获取类型对应的颜色（Discord 整数格式）
     *
     * @param string $type 类型
     * @return int
     */
    private function get_color_int_for_type( string $type ): int {
        $colors = [
            'update'   => 0x00aa00,
            'error'    => 0xaa0000,
            'recovery' => 0x00aa00,
            'warning'  => 0xaaaa00,
        ];

        return $colors[ $type ] ?? 0x0073aa;
    }

    /**
     * 获取类型对应的颜色（十六进制格式）
     *
     * @param string $type 类型
     * @return string
     */
    private function get_color_hex_for_type( string $type ): string {
        $colors = [
            'update'   => '00aa00',
            'error'    => 'aa0000',
            'recovery' => '00aa00',
            'warning'  => 'aaaa00',
        ];

        return $colors[ $type ] ?? '0073aa';
    }
}
