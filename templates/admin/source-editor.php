<?php
/**
 * 更新源编辑模板
 *
 * @package WPBridge
 * @since 0.5.0
 * @var \WPBridge\UpdateSource\SourceModel|null $source 源模型
 * @var array $types 类型列表
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_edit = null !== $source;
$title   = $is_edit ? __( '编辑更新源', 'wpbridge' ) : __( '添加更新源', 'wpbridge' );

// 当前类型判断
$current_type   = $source->type ?? '';
$git_types      = [ 'github', 'gitlab', 'gitee' ];
$is_git_type    = in_array( $current_type, $git_types, true );
$is_vendor      = $current_type === 'vendor';
$is_bridge      = $current_type === 'bridge_server' || ( ! $is_git_type && $current_type === 'bridge_server' );

// 类型描述和占位符配置
$type_hints = [
	'git'            => [
		'desc'        => __( '从 GitHub、GitLab 或 Gitee 仓库的 Release 获取更新，粘贴仓库地址即可自动识别平台。', 'wpbridge' ),
		'placeholder' => 'https://github.com/user/repo',
	],
	'json'           => [
		'desc'        => __( '标准 JSON API 接口，兼容 WordPress 插件更新检查格式。可用 {slug} 占位符匹配不同插件。', 'wpbridge' ),
		'placeholder' => 'https://example.com/updates/{slug}.json',
	],
	'zip'            => [
		'desc'        => __( '直接指向 ZIP 安装包的下载地址，适合手动托管的插件或主题。', 'wpbridge' ),
		'placeholder' => 'https://example.com/my-plugin-v1.2.3.zip',
	],
	'wenpai_git'     => [
		'desc'        => __( '菲码源库 (feiCode) 的 Release 更新，填写仓库地址。', 'wpbridge' ),
		'placeholder' => 'https://feicode.com/user/repo',
	],
	'puc'            => [
		'desc'        => __( '兼容 Plugin Update Checker 的自建更新服务器。', 'wpbridge' ),
		'placeholder' => 'https://example.com/puc/v5/check',
	],
	'bridge_server'  => [
		'desc'        => __( '连接到另一个启用了 Bridge API 的 WordPress 站点，接收其分发的插件和主题更新。', 'wpbridge' ),
		'placeholder' => 'https://hub-site.com/wp-json/bridge/v1/',
	],
];
?>

<!-- 标题栏 -->
<header class="wpbridge-header">
    <div class="wpbridge-header-left">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge' ) ); ?>" class="wpbridge-back-link">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </a>
        <h1 class="wpbridge-title"><?php echo esc_html( $title ); ?></h1>
    </div>
</header>

<div class="wrap wpbridge-wrap">

    <!-- 主内容区 -->
    <div class="wpbridge-content">
        <div class="wpbridge-tabs-card">
            <div class="wpbridge-tab-pane wpbridge-tab-pane-active" style="padding: 24px;">
                <?php settings_errors( 'wpbridge' ); ?>

                <?php if ( $is_vendor ) : ?>
                    <div class="wpbridge-info-box wpbridge-mb-4">
                        <p><strong><?php esc_html_e( '此更新源由供应商管理', 'wpbridge' ); ?></strong></p>
                        <p><?php esc_html_e( '供应商更新源的配置由商城连接自动维护，无法手动编辑。如需调整，请前往「商城」Tab 管理供应商连接。', 'wpbridge' ); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" class="wpbridge-editor-form">
                    <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
                    <input type="hidden" name="wpbridge_action" value="save_source">
                    <input type="hidden" name="source_id" value="<?php echo esc_attr( $source->id ?? '' ); ?>">

                    <!-- 类型 + 地址 -->
                    <div class="wpbridge-form-section">
                        <h2 class="wpbridge-form-section-title"><?php esc_html_e( '更新源', 'wpbridge' ); ?></h2>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label">
                                <?php esc_html_e( '类型', 'wpbridge' ); ?>
                                <span class="required">*</span>
                            </label>
                            <div>
                                <select name="type" id="wpbridge-source-type" class="wpbridge-form-select" <?php echo $is_vendor ? 'disabled' : ''; ?>>
                                    <option value="git" <?php selected( $is_git_type || ( empty( $current_type ) && ! $is_vendor ) ); ?>>
                                        <?php esc_html_e( 'Git 仓库', 'wpbridge' ); ?>
                                    </option>
                                    <option value="json" <?php selected( $current_type, 'json' ); ?>>
                                        <?php esc_html_e( 'JSON API', 'wpbridge' ); ?>
                                    </option>
                                    <option value="zip" <?php selected( $current_type, 'zip' ); ?>>
                                        <?php esc_html_e( 'ZIP 下载地址', 'wpbridge' ); ?>
                                    </option>
                                    <option value="wenpai_git" <?php selected( $current_type, 'wenpai_git' ); ?>>
                                        <?php esc_html_e( '菲码源库 (feiCode)', 'wpbridge' ); ?>
                                    </option>
                                    <option value="puc" <?php selected( $current_type, 'puc' ); ?>>
                                        <?php esc_html_e( 'PUC Server', 'wpbridge' ); ?>
                                    </option>
                                    <option value="bridge_server" <?php selected( $current_type, 'bridge_server' ); ?>>
                                        <?php esc_html_e( 'Bridge API (Hub 站点)', 'wpbridge' ); ?>
                                    </option>
                                    <?php if ( $is_vendor ) : ?>
                                    <option value="vendor" selected>
                                        <?php esc_html_e( '供应商', 'wpbridge' ); ?>
                                    </option>
                                    <?php endif; ?>
                                </select>
                                <p class="wpbridge-form-help" id="wpbridge-type-desc"></p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label">
                                <?php esc_html_e( '地址', 'wpbridge' ); ?>
                                <span class="required">*</span>
                            </label>
                            <div>
                                <input type="url"
                                       name="api_url"
                                       id="wpbridge-source-url"
                                       value="<?php echo esc_url( $source->api_url ?? '' ); ?>"
                                       class="wpbridge-form-input"
                                       style="max-width: 100%;"
                                       required>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label">
                                <?php esc_html_e( '名称', 'wpbridge' ); ?>
                            </label>
                            <div>
                                <input type="text"
                                       name="name"
                                       value="<?php echo esc_attr( $source->name ?? '' ); ?>"
                                       class="wpbridge-form-input"
                                       placeholder="<?php esc_attr_e( '留空自动生成', 'wpbridge' ); ?>">
                                <p class="wpbridge-form-help"><?php esc_html_e( '留空时从地址自动提取名称。', 'wpbridge' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- 匹配与认证 -->
                    <div class="wpbridge-form-section">
                        <h2 class="wpbridge-form-section-title"><?php esc_html_e( '匹配与认证', 'wpbridge' ); ?></h2>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '匹配目标', 'wpbridge' ); ?></label>
                            <div>
                                <?php
                                $current_slug      = $source->slug ?? '';
                                $current_item_type = $source->item_type ?? 'plugin';
                                $match_mode        = empty( $current_slug ) ? 'auto' : 'manual';
                                ?>
                                <select name="match_mode" id="wpbridge-match-mode" class="wpbridge-form-select">
                                    <option value="auto" <?php selected( $match_mode, 'auto' ); ?>>
                                        <?php esc_html_e( '智能匹配 — 从地址自动识别插件或主题', 'wpbridge' ); ?>
                                    </option>
                                    <option value="manual" <?php selected( $match_mode, 'manual' ); ?>>
                                        <?php esc_html_e( '手动指定', 'wpbridge' ); ?>
                                    </option>
                                </select>
                                <div id="wpbridge-manual-match" style="<?php echo $match_mode === 'auto' ? 'display:none;' : ''; ?> margin-top: 8px;">
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <select name="item_type" class="wpbridge-form-select" style="width: auto;">
                                            <option value="plugin" <?php selected( $current_item_type, 'plugin' ); ?>>
                                                <?php esc_html_e( '插件', 'wpbridge' ); ?>
                                            </option>
                                            <option value="theme" <?php selected( $current_item_type, 'theme' ); ?>>
                                                <?php esc_html_e( '主题', 'wpbridge' ); ?>
                                            </option>
                                        </select>
                                        <input type="text"
                                               name="slug"
                                               value="<?php echo esc_attr( $current_slug ); ?>"
                                               class="wpbridge-form-input"
                                               placeholder="<?php esc_attr_e( 'my-plugin（留空匹配所有）', 'wpbridge' ); ?>"
                                               style="flex: 1;">
                                    </div>
                                </div>
                                <p class="wpbridge-form-help">
                                    <?php esc_html_e( '智能匹配会从仓库名或 URL 自动提取插件/主题标识。', 'wpbridge' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '访问令牌', 'wpbridge' ); ?></label>
                            <div>
                                <input type="password"
                                       name="auth_token"
                                       value="<?php echo esc_attr( ! empty( $source->auth_token ) ? '********' : '' ); ?>"
                                       class="wpbridge-form-input"
                                       autocomplete="new-password"
                                       placeholder="<?php echo esc_attr( ! empty( $source->auth_token ) ? __( '已设置（留空保持不变）', 'wpbridge' ) : __( '私有仓库填写，公开仓库留空', 'wpbridge' ) ); ?>">
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '优先级', 'wpbridge' ); ?></label>
                            <div>
                                <?php
                                $current_priority = $source->priority ?? 50;
                                if ( $current_priority <= 20 ) {
                                    $priority_level = 'primary';
                                } elseif ( $current_priority <= 60 ) {
                                    $priority_level = 'secondary';
                                } else {
                                    $priority_level = 'fallback';
                                }
                                ?>
                                <select name="priority_level" class="wpbridge-form-select">
                                    <option value="primary" <?php selected( $priority_level, 'primary' ); ?>>
                                        <?php esc_html_e( '首选 — 优先使用此源', 'wpbridge' ); ?>
                                    </option>
                                    <option value="secondary" <?php selected( $priority_level, 'secondary' ); ?>>
                                        <?php esc_html_e( '备选 — 首选不可用时使用', 'wpbridge' ); ?>
                                    </option>
                                    <option value="fallback" <?php selected( $priority_level, 'fallback' ); ?>>
                                        <?php esc_html_e( '兜底 — 其他源都不可用时使用', 'wpbridge' ); ?>
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '启用', 'wpbridge' ); ?></label>
                            <div>
                                <label class="wpbridge-toggle">
                                    <input type="checkbox" name="enabled" value="1" <?php checked( $source->enabled ?? true ); ?>>
                                    <span class="wpbridge-toggle-track"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 操作按钮 -->
                    <div class="wpbridge-form-actions">
                        <button type="submit" class="wpbridge-btn wpbridge-btn-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php echo esc_html( $is_edit ? __( '保存更改', 'wpbridge' ) : __( '添加更新源', 'wpbridge' ) ); ?>
                        </button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge' ) ); ?>" class="wpbridge-btn wpbridge-btn-secondary">
                            <?php esc_html_e( '取消', 'wpbridge' ); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var typeHints = <?php echo wp_json_encode( array_map( function( $h ) {
        return [ 'desc' => $h['desc'], 'placeholder' => $h['placeholder'] ];
    }, $type_hints ) ); ?>;

    var typeSelect = document.getElementById('wpbridge-source-type');
    var descEl     = document.getElementById('wpbridge-type-desc');
    var urlInput   = document.getElementById('wpbridge-source-url');
    var matchMode  = document.getElementById('wpbridge-match-mode');
    var manualBox  = document.getElementById('wpbridge-manual-match');

    function updateHint() {
        var val  = typeSelect.value;
        var hint = typeHints[val];
        if (hint) {
            descEl.textContent      = hint.desc;
            urlInput.placeholder    = hint.placeholder;
        }
    }

    typeSelect.addEventListener('change', updateHint);
    updateHint();

    matchMode.addEventListener('change', function() {
        manualBox.style.display = this.value === 'manual' ? '' : 'none';
    });
})();
</script>
