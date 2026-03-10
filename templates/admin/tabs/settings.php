<?php
/**
 * 设置 Tab 内容
 *
 * @package WPBridge
 * @since 0.5.0
 * @var array $settings 设置
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<form method="post" class="wpbridge-settings-form">
    <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
    <input type="hidden" name="wpbridge_action" value="save_settings">
    <input type="hidden" name="debug_mode" id="wpbridge-debug-hidden" value="<?php echo ! empty( $settings['debug_mode'] ) ? '1' : '0'; ?>">

    <div class="wpbridge-settings-panel">
        <!-- 更新前备份 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '更新前备份', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '在更新插件/主题前自动创建备份，支持一键回滚。', 'wpbridge' ); ?></p>
            </div>
            <label class="wpbridge-toggle">
                <input type="checkbox" name="backup_enabled" value="1" <?php checked( $settings['backup_enabled'] ?? true ); ?>>
                <span class="wpbridge-toggle-track"></span>
            </label>
        </div>

        <!-- 缓存时间 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '缓存时间', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '更新检查结果的缓存时间。较长的缓存减少请求次数，较短的缓存更快发现更新。', 'wpbridge' ); ?></p>
            </div>
            <select name="cache_ttl" class="wpbridge-form-select wpbridge-form-input-md">
                <option value="3600" <?php selected( $settings['cache_ttl'] ?? 43200, 3600 ); ?>>
                    <?php esc_html_e( '1 小时', 'wpbridge' ); ?>
                </option>
                <option value="21600" <?php selected( $settings['cache_ttl'] ?? 43200, 21600 ); ?>>
                    <?php esc_html_e( '6 小时', 'wpbridge' ); ?>
                </option>
                <option value="43200" <?php selected( $settings['cache_ttl'] ?? 43200, 43200 ); ?>>
                    <?php esc_html_e( '12 小时（推荐）', 'wpbridge' ); ?>
                </option>
                <option value="86400" <?php selected( $settings['cache_ttl'] ?? 43200, 86400 ); ?>>
                    <?php esc_html_e( '24 小时', 'wpbridge' ); ?>
                </option>
            </select>
        </div>

        <!-- 请求超时 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '请求超时', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '连接更新源的超时时间。网络较慢时可适当增加。', 'wpbridge' ); ?></p>
            </div>
            <div class="wpbridge-inline-group">
                <input type="number"
                       name="request_timeout"
                       value="<?php echo esc_attr( $settings['request_timeout'] ?? 10 ); ?>"
                       min="5"
                       max="60"
                       class="wpbridge-form-input wpbridge-form-input-sm">
                <span class="wpbridge-text-muted"><?php esc_html_e( '秒', 'wpbridge' ); ?></span>
            </div>
        </div>
    </div>

    <div class="wpbridge-form-actions wpbridge-mt-4">
        <button type="submit" class="wpbridge-btn wpbridge-btn-primary">
            <span class="dashicons dashicons-saved"></span>
            <?php esc_html_e( '保存设置', 'wpbridge' ); ?>
        </button>
    </div>
</form>

<!-- 维护工具 -->
<div class="wpbridge-settings-panel wpbridge-mt-6">
    <h2 class="wpbridge-section-title wpbridge-mb-4">
        <span class="dashicons dashicons-admin-tools"></span>
        <?php esc_html_e( '维护工具', 'wpbridge' ); ?>
    </h2>

    <div class="wpbridge-settings-row">
        <div class="wpbridge-settings-info">
            <h3 class="wpbridge-settings-title"><?php esc_html_e( '清除全部缓存', 'wpbridge' ); ?></h3>
            <p class="wpbridge-settings-desc"><?php esc_html_e( '清除所有更新源的缓存数据，下次检查更新时将重新请求。更新不生效时可尝试此操作。', 'wpbridge' ); ?></p>
        </div>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-clear-cache">
            <span class="dashicons dashicons-trash"></span>
            <?php esc_html_e( '清除缓存', 'wpbridge' ); ?>
        </button>
    </div>

    <div class="wpbridge-settings-row">
        <div class="wpbridge-settings-info">
            <h3 class="wpbridge-settings-title"><?php esc_html_e( '调试模式', 'wpbridge' ); ?></h3>
            <p class="wpbridge-settings-desc"><?php esc_html_e( '记录详细的请求和响应日志，仅在排查问题时启用。开启后日志显示在下方。', 'wpbridge' ); ?></p>
        </div>
        <label class="wpbridge-toggle">
            <input type="checkbox" class="wpbridge-toggle-debug"
                   <?php checked( $settings['debug_mode'] ?? false ); ?>>
            <span class="wpbridge-toggle-track"></span>
        </label>
    </div>
</div>

<!-- 配置导入导出 -->
<div class="wpbridge-settings-panel wpbridge-mt-6">
    <h2 class="wpbridge-section-title wpbridge-mb-4">
        <span class="dashicons dashicons-database-export"></span>
        <?php esc_html_e( '配置导入导出', 'wpbridge' ); ?>
    </h2>

    <div class="wpbridge-settings-row">
        <div class="wpbridge-settings-info">
            <h3 class="wpbridge-settings-title"><?php esc_html_e( '导出配置', 'wpbridge' ); ?></h3>
            <p class="wpbridge-settings-desc"><?php esc_html_e( '导出为 JSON 文件，用于备份或迁移到其他站点。', 'wpbridge' ); ?></p>
        </div>
        <div class="wpbridge-inline-group">
            <label class="wpbridge-import-export-label">
                <input type="checkbox" id="wpbridge-export-secrets">
                <?php esc_html_e( '包含敏感信息', 'wpbridge' ); ?>
            </label>
            <button type="button" class="wpbridge-btn wpbridge-btn-secondary" id="wpbridge-export-config">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( '导出', 'wpbridge' ); ?>
            </button>
        </div>
    </div>

    <div class="wpbridge-settings-row">
        <div class="wpbridge-settings-info">
            <h3 class="wpbridge-settings-title"><?php esc_html_e( '导入配置', 'wpbridge' ); ?></h3>
            <p class="wpbridge-settings-desc"><?php esc_html_e( '从 JSON 文件导入。可选择合并或覆盖现有配置。', 'wpbridge' ); ?></p>
        </div>
        <div class="wpbridge-inline-group">
            <label class="wpbridge-import-export-label">
                <input type="checkbox" id="wpbridge-import-merge" checked>
                <?php esc_html_e( '合并配置', 'wpbridge' ); ?>
            </label>
            <input type="file" id="wpbridge-import-file" accept=".json" style="display: none;">
            <button type="button" class="wpbridge-btn wpbridge-btn-secondary" id="wpbridge-import-config">
                <span class="dashicons dashicons-upload"></span>
                <?php esc_html_e( '导入', 'wpbridge' ); ?>
            </button>
        </div>
    </div>
</div>
