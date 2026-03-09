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

    <div class="wpbridge-settings-panel">
        <!-- 调试模式 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '调试模式', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '启用后会记录详细的调试信息，仅在排查问题时启用。', 'wpbridge' ); ?></p>
            </div>
            <label class="wpbridge-toggle">
                <input type="checkbox" name="debug_mode" value="1" <?php checked( $settings['debug_mode'] ?? false ); ?>>
                <span class="wpbridge-toggle-track"></span>
            </label>
        </div>

        <!-- 缓存时间 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '缓存时间', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '更新检查结果的缓存时间，较长的缓存时间可以减少请求次数。', 'wpbridge' ); ?></p>
            </div>
            <select name="cache_ttl" class="wpbridge-form-select wpbridge-form-input-md">
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
        </div>

        <!-- 请求超时 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '请求超时', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( 'HTTP 请求的超时时间（5-60 秒），网络较慢时可适当增加。', 'wpbridge' ); ?></p>
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

        <!-- 降级策略 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '过期缓存兜底', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '当更新源不可用时，使用过期的缓存数据作为兜底。', 'wpbridge' ); ?></p>
            </div>
            <label class="wpbridge-toggle">
                <input type="checkbox" name="fallback_enabled" value="1" <?php checked( $settings['fallback_enabled'] ?? true ); ?>>
                <span class="wpbridge-toggle-track"></span>
            </label>
        </div>

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
    </div>

    <!-- Bridge Server 配置 -->
    <div class="wpbridge-settings-panel wpbridge-mt-6">
        <h3 class="wpbridge-panel-title">
            <span class="dashicons dashicons-cloud"></span>
            <?php esc_html_e( 'Bridge Server', 'wpbridge' ); ?>
            <?php if ( $is_feature_locked( 'bridge_server' ) ) : ?>
                <span class="wpbridge-lock-badge">
                    <span class="dashicons dashicons-lock"></span>
                    <?php esc_html_e( '需要 Pro', 'wpbridge' ); ?>
                </span>
            <?php endif; ?>
        </h3>

        <div class="<?php echo $is_feature_locked( 'bridge_server' ) ? 'wpbridge-feature-locked' : ''; ?>">
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '服务端地址', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( 'wpbridge-server 服务端 URL，用于商业插件下载代理。', 'wpbridge' ); ?></p>
            </div>
            <input type="url"
                   name="bridge_server_url"
                   value="<?php echo esc_attr( $settings['bridge_server_url'] ?? '' ); ?>"
                   placeholder="https://bridge.example.com"
                   class="wpbridge-form-input wpbridge-form-input-lg">
        </div>

        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( 'API Key', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '用于访问 Bridge Server 管理 API 的密钥。', 'wpbridge' ); ?></p>
            </div>
            <input type="password"
                   name="bridge_server_api_key"
                   value="<?php echo esc_attr( $settings['bridge_server_api_key'] ?? '' ); ?>"
                   placeholder="<?php esc_attr_e( '输入 API Key', 'wpbridge' ); ?>"
                   class="wpbridge-form-input wpbridge-form-input-lg">
        </div>

        <?php if ( ! empty( $settings['bridge_server_url'] ) ) : ?>
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '连接状态', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '测试与 Bridge Server 的连接。', 'wpbridge' ); ?></p>
            </div>
            <button type="button" class="wpbridge-btn wpbridge-btn-secondary" id="wpbridge-test-bridge-server">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( '测试连接', 'wpbridge' ); ?>
            </button>
        </div>
        <?php endif; ?>
        </div><!-- /.wpbridge-feature-locked -->
    </div>

    <div class="wpbridge-form-actions wpbridge-mt-6">
        <button type="submit" class="wpbridge-btn wpbridge-btn-primary">
            <span class="dashicons dashicons-saved"></span>
            <?php esc_html_e( '保存设置', 'wpbridge' ); ?>
        </button>
    </div>
</form>

<!-- 配置导入导出 -->
<div class="wpbridge-settings-panel wpbridge-mt-8">
    <h2 class="wpbridge-section-title wpbridge-mb-4">
        <span class="dashicons dashicons-database-export"></span>
        <?php esc_html_e( '配置导入导出', 'wpbridge' ); ?>
    </h2>

    <div class="wpbridge-settings-row">
        <div class="wpbridge-settings-info">
            <h3 class="wpbridge-settings-title"><?php esc_html_e( '导出配置', 'wpbridge' ); ?></h3>
            <p class="wpbridge-settings-desc"><?php esc_html_e( '将当前配置导出为 JSON 文件，用于备份或迁移到其他站点。', 'wpbridge' ); ?></p>
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
            <p class="wpbridge-settings-desc"><?php esc_html_e( '从 JSON 文件导入配置。可选择合并或覆盖现有配置。', 'wpbridge' ); ?></p>
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
