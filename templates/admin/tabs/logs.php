<?php
/**
 * 日志 Tab 内容
 *
 * @package WPBridge
 * @since 0.5.0
 * @var array $logs 日志列表
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wpbridge-logs-panel">
    <div class="wpbridge-logs-header">
        <h2 class="wpbridge-logs-title"><?php esc_html_e( '调试日志', 'wpbridge' ); ?></h2>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-clear-logs">
            <span class="dashicons dashicons-trash"></span>
            <?php esc_html_e( '清除日志', 'wpbridge' ); ?>
        </button>
    </div>

    <?php if ( empty( $logs ) ) : ?>
        <div class="wpbridge-logs-empty">
            <span class="dashicons dashicons-media-text" style="font-size: 32px; width: 32px; height: 32px; color: var(--wpbridge-gray-300);"></span>
            <p><?php esc_html_e( '暂无日志记录', 'wpbridge' ); ?></p>
            <p style="font-size: 12px; color: var(--wpbridge-gray-400);">
                <?php esc_html_e( '启用调试模式后，日志将显示在这里。', 'wpbridge' ); ?>
            </p>
        </div>
    <?php else : ?>
        <div class="wpbridge-logs-list">
            <?php foreach ( array_slice( $logs, 0, 100 ) as $log ) : ?>
                <div class="wpbridge-log-item">
                    <span class="wpbridge-log-level <?php echo esc_attr( $log['level'] ); ?>">
                        <?php echo esc_html( strtoupper( $log['level'] ) ); ?>
                    </span>
                    <span class="wpbridge-log-time">
                        <?php echo esc_html( $log['time'] ); ?>
                    </span>
                    <span class="wpbridge-log-message">
                        <?php echo esc_html( $log['message'] ); ?>
                        <?php if ( ! empty( $log['context'] ) ) : ?>
                            <code style="display: block; margin-top: 4px; font-size: 11px; color: var(--wpbridge-gray-500);">
                                <?php echo esc_html( wp_json_encode( $log['context'], JSON_UNESCAPED_UNICODE ) ); ?>
                            </code>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div style="margin-top: 16px; padding: 16px; background: var(--wpbridge-info-light); font-size: 13px;">
    <strong><?php esc_html_e( '提示', 'wpbridge' ); ?>:</strong>
    <?php esc_html_e( '日志仅在启用调试模式时记录。生产环境建议关闭调试模式以提高性能。', 'wpbridge' ); ?>
</div>
