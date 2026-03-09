<?php
/**
 * Bridge API Tab 内容
 *
 * @package WPBridge
 * @since 0.5.0
 * @var array $settings 设置
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$api_settings = $settings['api'] ?? [];
$api_enabled  = ! empty( $api_settings['enabled'] );
$require_auth = ! empty( $api_settings['require_auth'] );
$rate_limit   = $api_settings['rate_limit'] ?? 100;
$api_keys     = $api_settings['keys'] ?? [];
?>

<?php if ( $is_feature_locked( 'bridge_api' ) ) : ?>
    <div class="wpbridge-upgrade-notice">
        <span class="dashicons dashicons-lock"></span>
        <?php esc_html_e( 'Bridge API 需要 Pro 及以上订阅才能使用。', 'wpbridge' ); ?>
    </div>
<?php endif; ?>

<div class="<?php echo $is_feature_locked( 'bridge_api' ) ? 'wpbridge-feature-locked' : ''; ?>">
<form method="post" class="wpbridge-api-form">
    <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
    <input type="hidden" name="wpbridge_action" value="save_api_settings">

    <!-- API 状态面板 -->
    <div class="wpbridge-stats-panel wpbridge-mb-4">
        <div class="wpbridge-stat-card">
            <div class="wpbridge-stat-card-header">
                <span class="dashicons dashicons-rest-api"></span>
                <?php esc_html_e( 'API 状态', 'wpbridge' ); ?>
            </div>
            <div class="wpbridge-stat-value <?php echo $api_enabled ? 'success' : ''; ?>">
                <?php echo $api_enabled ? esc_html__( '已启用', 'wpbridge' ) : esc_html__( '已禁用', 'wpbridge' ); ?>
            </div>
        </div>
        <div class="wpbridge-stat-card">
            <div class="wpbridge-stat-card-header">
                <span class="dashicons dashicons-admin-network"></span>
                <?php esc_html_e( 'API 端点', 'wpbridge' ); ?>
            </div>
            <div class="wpbridge-text-mono">
                <?php echo esc_html( rest_url( 'bridge/v1/' ) ); ?>
            </div>
        </div>
        <div class="wpbridge-stat-card">
            <div class="wpbridge-stat-card-header">
                <span class="dashicons dashicons-admin-users"></span>
                <?php esc_html_e( 'API Keys', 'wpbridge' ); ?>
            </div>
            <div class="wpbridge-stat-value"><?php echo count( $api_keys ); ?></div>
        </div>
    </div>

    <div class="wpbridge-settings-panel">
        <!-- 启用 API -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '启用 Bridge API', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '允许通过 REST API 远程访问 WPBridge 功能。', 'wpbridge' ); ?></p>
            </div>
            <label class="wpbridge-toggle">
                <input type="checkbox" name="api_enabled" value="1" <?php checked( $api_enabled ); ?>>
                <span class="wpbridge-toggle-track"></span>
            </label>
        </div>

        <!-- 需要认证 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '需要 API Key 认证', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '启用后，所有 API 请求都需要提供有效的 API Key。', 'wpbridge' ); ?></p>
            </div>
            <label class="wpbridge-toggle">
                <input type="checkbox" name="require_auth" value="1" <?php checked( $require_auth ); ?>>
                <span class="wpbridge-toggle-track"></span>
            </label>
        </div>

        <!-- 速率限制 -->
        <div class="wpbridge-settings-row">
            <div class="wpbridge-settings-info">
                <h3 class="wpbridge-settings-title"><?php esc_html_e( '速率限制', 'wpbridge' ); ?></h3>
                <p class="wpbridge-settings-desc"><?php esc_html_e( '每个 IP 每分钟允许的最大请求数。', 'wpbridge' ); ?></p>
            </div>
            <div class="wpbridge-inline-group">
                <input type="number"
                       name="rate_limit"
                       value="<?php echo esc_attr( $rate_limit ); ?>"
                       min="10"
                       max="10000"
                       class="wpbridge-form-input wpbridge-form-input-sm">
                <span class="wpbridge-text-muted"><?php esc_html_e( '次/分钟', 'wpbridge' ); ?></span>
            </div>
        </div>
    </div>

    <div class="wpbridge-form-actions wpbridge-mt-6">
        <button type="submit" class="wpbridge-btn wpbridge-btn-primary">
            <span class="dashicons dashicons-saved"></span>
            <?php esc_html_e( '保存设置', 'wpbridge' ); ?>
        </button>
    </div>
</form>

<!-- API Keys 管理 -->
<div class="wpbridge-settings-panel wpbridge-mt-6">
    <div class="wpbridge-sources-header wpbridge-mb-4">
        <h2 class="wpbridge-sources-title"><?php esc_html_e( 'API Keys', 'wpbridge' ); ?></h2>
        <button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-generate-api-key">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( '生成新 Key', 'wpbridge' ); ?>
        </button>
    </div>

    <?php if ( empty( $api_keys ) ) : ?>
        <div class="wpbridge-empty">
            <span class="dashicons dashicons-admin-network"></span>
            <h3 class="wpbridge-empty-title"><?php esc_html_e( '暂无 API Key', 'wpbridge' ); ?></h3>
            <p class="wpbridge-empty-desc"><?php esc_html_e( '生成 API Key 以允许远程访问。', 'wpbridge' ); ?></p>
        </div>
    <?php else : ?>
        <div class="wpbridge-api-keys-list">
            <?php foreach ( $api_keys as $key_data ) : ?>
                <div class="wpbridge-settings-row" data-key-id="<?php echo esc_attr( $key_data['id'] ?? '' ); ?>">
                    <div class="wpbridge-settings-info">
                        <h3 class="wpbridge-settings-title">
                            <?php echo esc_html( $key_data['name'] ?? __( '未命名', 'wpbridge' ) ); ?>
                            <?php if ( ! empty( $key_data['key_prefix'] ) ) : ?>
                                <code class="wpbridge-key-prefix">
                                    <?php echo esc_html( $key_data['key_prefix'] ); ?>
                                </code>
                            <?php endif; ?>
                        </h3>
                        <p class="wpbridge-settings-desc">
                            <?php
                            $created_at = $key_data['created_at'] ?? '';
                            if ( ! empty( $created_at ) ) {
                                // 支持 MySQL 格式和 Unix 时间戳
                                $timestamp = is_numeric( $created_at ) ? $created_at : strtotime( $created_at );
                                /* translators: %s: date */
                                printf(
                                    esc_html__( '创建于 %s', 'wpbridge' ),
                                    esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) )
                                );
                            }
                            $last_used = $key_data['last_used'] ?? null;
                            if ( ! empty( $last_used ) ) {
                                $last_timestamp = is_numeric( $last_used ) ? $last_used : strtotime( $last_used );
                                /* translators: %s: relative time */
                                printf(
                                    ' &middot; ' . esc_html__( '最后使用 %s', 'wpbridge' ),
                                    esc_html( human_time_diff( $last_timestamp, time() ) . __( '前', 'wpbridge' ) )
                                );
                            }
                            ?>
                        </p>
                    </div>
                    <button type="button"
                            class="wpbridge-btn wpbridge-btn-danger wpbridge-btn-sm wpbridge-revoke-api-key"
                            data-key-id="<?php echo esc_attr( $key_data['id'] ?? '' ); ?>">
                        <span class="dashicons dashicons-no"></span>
                        <?php esc_html_e( '撤销', 'wpbridge' ); ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- API 文档 -->
<div class="wpbridge-settings-panel wpbridge-mt-6">
    <h2 class="wpbridge-sources-title wpbridge-mb-4"><?php esc_html_e( 'API 文档', 'wpbridge' ); ?></h2>

    <div class="wpbridge-api-docs">
        <p><strong><?php esc_html_e( '认证方式', 'wpbridge' ); ?></strong></p>
        <p><?php esc_html_e( '在请求头中添加 API Key：', 'wpbridge' ); ?></p>
        <code class="wpbridge-code-block">X-WPBridge-API-Key: your_api_key</code>

        <p class="wpbridge-mt-4"><strong><?php esc_html_e( '可用端点', 'wpbridge' ); ?></strong></p>
        <ul class="wpbridge-api-endpoints">
            <li><code>GET /wp-json/bridge/v1/status</code> - <?php esc_html_e( 'API 状态', 'wpbridge' ); ?></li>
            <li><code>GET /wp-json/bridge/v1/sources</code> - <?php esc_html_e( '获取更新源列表', 'wpbridge' ); ?></li>
            <li><code>GET /wp-json/bridge/v1/check/{source_id}</code> - <?php esc_html_e( '检查更新源状态', 'wpbridge' ); ?></li>
            <li><code>GET /wp-json/bridge/v1/plugins/{slug}/info</code> - <?php esc_html_e( '获取插件信息', 'wpbridge' ); ?></li>
            <li><code>GET /wp-json/bridge/v1/themes/{slug}/info</code> - <?php esc_html_e( '获取主题信息', 'wpbridge' ); ?></li>
        </ul>
    </div>
</div>
</div><!-- /.wpbridge-feature-locked -->
