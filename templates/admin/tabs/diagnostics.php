<?php
/**
 * 诊断工具 Tab 内容
 *
 * @package WPBridge
 * @since 0.7.0
 * @var array $sources 源列表
 * @var array $stats   统计信息
 * @var array $settings 设置
 * @var array $health_status 健康状态
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\UpdateSource\SourceType;
?>

<!-- 诊断工具头部 -->
<div class="wpbridge-diagnostics-header">
    <div class="wpbridge-diagnostics-info">
        <h2><?php esc_html_e( '诊断工具', 'wpbridge' ); ?></h2>
        <p><?php esc_html_e( '检查更新源连通性、系统环境和配置状态', 'wpbridge' ); ?></p>
    </div>
    <div class="wpbridge-diagnostics-actions">
        <button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-run-all-diagnostics">
            <span class="dashicons dashicons-controls-play"></span>
            <?php esc_html_e( '运行全部诊断', 'wpbridge' ); ?>
        </button>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-export-diagnostics">
            <span class="dashicons dashicons-download"></span>
            <?php esc_html_e( '导出报告', 'wpbridge' ); ?>
        </button>
    </div>
</div>

<!-- 诊断结果概览 -->
<div class="wpbridge-diagnostics-summary" style="display: none;" aria-live="polite" aria-atomic="true">
    <div class="wpbridge-diagnostics-summary-item wpbridge-diagnostics-passed">
        <span class="dashicons dashicons-yes-alt"></span>
        <span class="wpbridge-diagnostics-count">0</span>
        <span><?php esc_html_e( '通过', 'wpbridge' ); ?></span>
    </div>
    <div class="wpbridge-diagnostics-summary-item wpbridge-diagnostics-warnings">
        <span class="dashicons dashicons-warning"></span>
        <span class="wpbridge-diagnostics-count">0</span>
        <span><?php esc_html_e( '警告', 'wpbridge' ); ?></span>
    </div>
    <div class="wpbridge-diagnostics-summary-item wpbridge-diagnostics-failed">
        <span class="dashicons dashicons-dismiss"></span>
        <span class="wpbridge-diagnostics-count">0</span>
        <span><?php esc_html_e( '失败', 'wpbridge' ); ?></span>
    </div>
</div>

<!-- 更新源连通性测试 -->
<div class="wpbridge-diagnostics-section">
    <div class="wpbridge-diagnostics-section-header">
        <h3>
            <span class="dashicons dashicons-cloud"></span>
            <?php esc_html_e( '更新源连通性', 'wpbridge' ); ?>
        </h3>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-all-sources">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( '测试全部', 'wpbridge' ); ?>
        </button>
    </div>

    <?php if ( empty( $sources ) ) : ?>
        <div class="wpbridge-diagnostics-empty">
            <span class="dashicons dashicons-info-outline"></span>
            <p><?php esc_html_e( '暂无更新源，请先添加更新源', 'wpbridge' ); ?></p>
        </div>
    <?php else : ?>
        <div class="wpbridge-source-tests">
            <?php foreach ( $sources as $source ) : ?>
                <div class="wpbridge-source-test-item" data-source-id="<?php echo esc_attr( $source->id ); ?>">
                    <div class="wpbridge-source-test-info">
                        <div class="wpbridge-source-test-name">
                            <?php echo esc_html( $source->name ?: $source->id ); ?>
                            <?php if ( $source->is_preset ) : ?>
                                <span class="wpbridge-badge wpbridge-badge-preset"><?php esc_html_e( '预置', 'wpbridge' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="wpbridge-source-test-url"><?php echo esc_html( $source->api_url ); ?></div>
                    </div>
                    <div class="wpbridge-source-test-meta">
                        <span class="wpbridge-badge wpbridge-badge-type <?php echo esc_attr( $source->type ); ?>">
                            <?php echo esc_html( SourceType::get_label( $source->type ) ); ?>
                        </span>
                        <span class="wpbridge-source-test-status">
                            <?php if ( ! $source->enabled ) : ?>
                                <span class="wpbridge-badge wpbridge-badge-disabled"><?php esc_html_e( '已禁用', 'wpbridge' ); ?></span>
                            <?php elseif ( isset( $health_status[ $source->id ] ) ) : ?>
                                <?php
                                $status = $health_status[ $source->id ];
                                $status_class = $status['status'] ?? 'unknown';
                                $status_labels = array(
                                    'healthy'  => __( '正常', 'wpbridge' ),
                                    'degraded' => __( '降级', 'wpbridge' ),
                                    'failed'   => __( '失败', 'wpbridge' ),
                                );
                                ?>
                                <span class="wpbridge-badge wpbridge-badge-status <?php echo esc_attr( $status_class ); ?>">
                                    <?php echo esc_html( $status_labels[ $status_class ] ?? $status_class ); ?>
                                </span>
                                <?php if ( ! empty( $status['response_time'] ) ) : ?>
                                    <span class="wpbridge-source-test-time"><?php echo esc_html( $status['response_time'] ); ?>ms</span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="wpbridge-badge wpbridge-badge-unknown"><?php esc_html_e( '未测试', 'wpbridge' ); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="wpbridge-source-test-actions">
                        <button type="button"
                                class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-single-source"
                                data-source-id="<?php echo esc_attr( $source->id ); ?>"
                                <?php disabled( ! $source->enabled ); ?>>
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <?php esc_html_e( '测试', 'wpbridge' ); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 系统环境检查 -->
<div class="wpbridge-diagnostics-section">
    <div class="wpbridge-diagnostics-section-header">
        <h3>
            <span class="dashicons dashicons-admin-tools"></span>
            <?php esc_html_e( '系统环境', 'wpbridge' ); ?>
        </h3>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-check-environment">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( '检查', 'wpbridge' ); ?>
        </button>
    </div>

    <div class="wpbridge-environment-checks">
        <?php
        // PHP 版本检查
        $php_version = PHP_VERSION;
        $php_ok = version_compare( $php_version, '7.4', '>=' );
        ?>
        <div class="wpbridge-check-item <?php echo $php_ok ? 'passed' : 'failed'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $php_ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( 'PHP 版本', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $php_version ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '要求 >= 7.4', 'wpbridge' ); ?></span>
        </div>

        <?php
        // WordPress 版本检查
        $wp_version = get_bloginfo( 'version' );
        $wp_ok = version_compare( $wp_version, '5.9', '>=' );
        ?>
        <div class="wpbridge-check-item <?php echo $wp_ok ? 'passed' : 'failed'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $wp_ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( 'WordPress 版本', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $wp_version ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '要求 >= 5.9', 'wpbridge' ); ?></span>
        </div>

        <?php
        // cURL 扩展检查
        $curl_ok = function_exists( 'curl_version' );
        $curl_version = $curl_ok ? curl_version()['version'] : __( '未安装', 'wpbridge' );
        ?>
        <div class="wpbridge-check-item <?php echo $curl_ok ? 'passed' : 'failed'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $curl_ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( 'cURL 扩展', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $curl_version ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '必需', 'wpbridge' ); ?></span>
        </div>

        <?php
        // OpenSSL 扩展检查
        $openssl_ok = extension_loaded( 'openssl' );
        $openssl_version = $openssl_ok ? OPENSSL_VERSION_TEXT : __( '未安装', 'wpbridge' );
        ?>
        <div class="wpbridge-check-item <?php echo $openssl_ok ? 'passed' : 'failed'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $openssl_ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( 'OpenSSL 扩展', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $openssl_version ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '必需', 'wpbridge' ); ?></span>
        </div>

        <?php
        // JSON 扩展检查
        $json_ok = function_exists( 'json_encode' );
        ?>
        <div class="wpbridge-check-item <?php echo $json_ok ? 'passed' : 'failed'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $json_ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( 'JSON 扩展', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo $json_ok ? esc_html__( '已安装', 'wpbridge' ) : esc_html__( '未安装', 'wpbridge' ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '必需', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 外部 HTTP 请求检查
        $http_ok = ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL;
        ?>
        <div class="wpbridge-check-item <?php echo $http_ok ? 'passed' : 'warning'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $http_ok ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '外部 HTTP 请求', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo $http_ok ? esc_html__( '允许', 'wpbridge' ) : esc_html__( '已阻止', 'wpbridge' ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '推荐允许', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 内存限制检查
        $memory_limit = ini_get( 'memory_limit' );
        $memory_bytes = wp_convert_hr_to_bytes( $memory_limit );
        $memory_ok = $memory_bytes >= 67108864; // 64MB
        ?>
        <div class="wpbridge-check-item <?php echo $memory_ok ? 'passed' : 'warning'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $memory_ok ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '内存限制', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $memory_limit ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '推荐 >= 64M', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 执行时间限制检查
        $max_execution = ini_get( 'max_execution_time' );
        $execution_ok = 0 === (int) $max_execution || $max_execution >= 30;
        ?>
        <div class="wpbridge-check-item <?php echo $execution_ok ? 'passed' : 'warning'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $execution_ok ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '最大执行时间', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $max_execution ); ?>s</span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '推荐 >= 30s', 'wpbridge' ); ?></span>
        </div>
    </div>
</div>

<!-- 配置检查 -->
<div class="wpbridge-diagnostics-section">
    <div class="wpbridge-diagnostics-section-header">
        <h3>
            <span class="dashicons dashicons-admin-settings"></span>
            <?php esc_html_e( '配置检查', 'wpbridge' ); ?>
        </h3>
    </div>

    <div class="wpbridge-config-checks">
        <?php
        // 调试模式检查
        $debug_mode = ! empty( $settings['debug_mode'] );
        ?>
        <div class="wpbridge-check-item <?php echo $debug_mode ? 'warning' : 'passed'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $debug_mode ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '调试模式', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo $debug_mode ? esc_html__( '已启用', 'wpbridge' ) : esc_html__( '已禁用', 'wpbridge' ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '生产环境建议禁用', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 缓存降级检查
        $fallback_enabled = ! empty( $settings['fallback_enabled'] );
        ?>
        <div class="wpbridge-check-item <?php echo $fallback_enabled ? 'passed' : 'warning'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $fallback_enabled ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '缓存降级', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo $fallback_enabled ? esc_html__( '已启用', 'wpbridge' ) : esc_html__( '已禁用', 'wpbridge' ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '建议启用', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 请求超时检查
        $request_timeout = $settings['request_timeout'] ?? 10;
        $timeout_ok = $request_timeout >= 5 && $request_timeout <= 30;
        ?>
        <div class="wpbridge-check-item <?php echo $timeout_ok ? 'passed' : 'warning'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $timeout_ok ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '请求超时', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $request_timeout ); ?>s</span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '建议 5-30 秒', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 缓存 TTL 检查
        $cache_ttl = $settings['cache_ttl'] ?? 43200;
        $cache_hours = $cache_ttl / 3600;
        ?>
        <div class="wpbridge-check-item passed">
            <span class="wpbridge-check-icon dashicons dashicons-yes-alt"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '缓存有效期', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $cache_hours ); ?> <?php esc_html_e( '小时', 'wpbridge' ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '当前设置', 'wpbridge' ); ?></span>
        </div>

        <?php
        // 启用的更新源数量检查
        $enabled_sources = $stats['enabled'] ?? 0;
        ?>
        <div class="wpbridge-check-item <?php echo $enabled_sources > 0 ? 'passed' : 'warning'; ?>">
            <span class="wpbridge-check-icon dashicons <?php echo $enabled_sources > 0 ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
            <div class="wpbridge-check-info">
                <span class="wpbridge-check-label"><?php esc_html_e( '启用的更新源', 'wpbridge' ); ?></span>
                <span class="wpbridge-check-value"><?php echo esc_html( $enabled_sources ); ?></span>
            </div>
            <span class="wpbridge-check-requirement"><?php esc_html_e( '至少需要 1 个', 'wpbridge' ); ?></span>
        </div>
    </div>
</div>

<!-- 诊断报告导出模态框 -->
<div id="wpbridge-export-modal" class="wpbridge-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="wpbridge-export-modal-title">
    <div class="wpbridge-modal-content">
        <div class="wpbridge-modal-header">
            <h3 id="wpbridge-export-modal-title"><?php esc_html_e( '诊断报告', 'wpbridge' ); ?></h3>
            <button type="button" class="wpbridge-modal-close" aria-label="<?php esc_attr_e( '关闭', 'wpbridge' ); ?>">&times;</button>
        </div>
        <div class="wpbridge-modal-body">
            <textarea id="wpbridge-diagnostics-report" class="wpbridge-diagnostics-textarea" readonly></textarea>
        </div>
        <div class="wpbridge-modal-footer">
            <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-copy-report">
                <span class="dashicons dashicons-admin-page"></span>
                <?php esc_html_e( '复制到剪贴板', 'wpbridge' ); ?>
            </button>
            <button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-download-report">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( '下载报告', 'wpbridge' ); ?>
            </button>
        </div>
    </div>
</div>
