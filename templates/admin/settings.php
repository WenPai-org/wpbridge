<?php
/**
 * 设置页面模板
 *
 * @package WPBridge
 * @var array $settings 设置
 * @var array $logs     日志
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap wpbridge-wrap">
    <h1><?php esc_html_e( 'WPBridge 设置', 'wpbridge' ); ?></h1>

    <?php settings_errors( 'wpbridge' ); ?>

    <form method="post">
        <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
        <input type="hidden" name="wpbridge_action" value="save_settings">

        <h2><?php esc_html_e( '常规设置', 'wpbridge' ); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( '调试模式', 'wpbridge' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="debug_mode"
                               value="1"
                               <?php checked( $settings['debug_mode'] ?? false ); ?>>
                        <?php esc_html_e( '启用调试日志', 'wpbridge' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( '启用后会记录详细的调试信息，仅在排查问题时启用。', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-cache-ttl"><?php esc_html_e( '缓存时间', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <select id="wpbridge-cache-ttl" name="cache_ttl">
                        <option value="3600" <?php selected( $settings['cache_ttl'] ?? 43200, 3600 ); ?>>
                            <?php esc_html_e( '1 小时', 'wpbridge' ); ?>
                        </option>
                        <option value="21600" <?php selected( $settings['cache_ttl'] ?? 43200, 21600 ); ?>>
                            <?php esc_html_e( '6 小时', 'wpbridge' ); ?>
                        </option>
                        <option value="43200" <?php selected( $settings['cache_ttl'] ?? 43200, 43200 ); ?>>
                            <?php esc_html_e( '12 小时', 'wpbridge' ); ?>
                        </option>
                        <option value="86400" <?php selected( $settings['cache_ttl'] ?? 43200, 86400 ); ?>>
                            <?php esc_html_e( '24 小时', 'wpbridge' ); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e( '更新检查结果的缓存时间', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-timeout"><?php esc_html_e( '请求超时', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpbridge-timeout"
                           name="request_timeout"
                           value="<?php echo esc_attr( $settings['request_timeout'] ?? 10 ); ?>"
                           min="5"
                           max="60"
                           class="small-text">
                    <?php esc_html_e( '秒', 'wpbridge' ); ?>
                    <p class="description">
                        <?php esc_html_e( 'HTTP 请求的超时时间（5-60 秒）', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( '降级策略', 'wpbridge' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="fallback_enabled"
                               value="1"
                               <?php checked( $settings['fallback_enabled'] ?? true ); ?>>
                        <?php esc_html_e( '启用过期缓存兜底', 'wpbridge' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( '当更新源不可用时，使用过期的缓存数据。', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button button-primary" value="<?php esc_attr_e( '保存设置', 'wpbridge' ); ?>">
        </p>
    </form>

    <hr>

    <h2><?php esc_html_e( '调试日志', 'wpbridge' ); ?></h2>

    <?php if ( empty( $logs ) ) : ?>
        <p><?php esc_html_e( '暂无日志', 'wpbridge' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;"><?php esc_html_e( '时间', 'wpbridge' ); ?></th>
                    <th style="width: 80px;"><?php esc_html_e( '级别', 'wpbridge' ); ?></th>
                    <th><?php esc_html_e( '消息', 'wpbridge' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( array_slice( $logs, 0, 50 ) as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log['time'] ); ?></td>
                        <td>
                            <span class="wpbridge-log-level wpbridge-log-<?php echo esc_attr( $log['level'] ); ?>">
                                <?php echo esc_html( strtoupper( $log['level'] ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo esc_html( $log['message'] ); ?>
                            <?php if ( ! empty( $log['context'] ) ) : ?>
                                <code><?php echo esc_html( wp_json_encode( $log['context'] ) ); ?></code>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
