<?php
/**
 * 更新源列表部分模板
 *
 * @package WPBridge
 * @since 1.2.0
 * @var SourceModel[] $sources 所有更新源（来自 SourceManager）
 * @var SourceRegistry $source_registry 源注册表
 * @var array $health_status 健康状态（来自 main.php）
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBridge\Core\SourceRegistry;
use WPBridge\UpdateSource\SourceType;

// 获取 SourceRegistry 中的供应商源
$_registry      = new SourceRegistry();
$_registry_all  = $_registry->get_all();
$vendor_sources = array_filter( $_registry_all, fn( $s ) => ( $s['type'] ?? '' ) === SourceRegistry::TYPE_VENDOR );

// 自定义源（非预设、非内联）
$custom_sources = array_filter( $sources, fn( $s ) => ! $s->is_preset && ! $s->is_inline );

// 预设源
$preset_sources = array_filter( $sources, fn( $s ) => $s->is_preset );

// 统计
$total_count   = count( $preset_sources ) + count( $custom_sources ) + count( $vendor_sources );
$enabled_count = 0;
foreach ( $preset_sources as $s ) {
	if ( $s->enabled ) {
		++$enabled_count;
	}
}
foreach ( $custom_sources as $s ) {
	if ( $s->enabled ) {
		++$enabled_count;
	}
}
foreach ( $vendor_sources as $vs ) {
	if ( ! empty( $vs['enabled'] ) ) {
		++$enabled_count;
	}
}

// 渲染单个源行的测试状态
$render_test_status = function ( string $source_id, bool $enabled ) use ( $health_status ) {
	if ( ! $enabled ) {
		return;
	}
	$status = $health_status[ $source_id ] ?? null;
	if ( $status && is_array( $status ) ) {
		$s       = $status['status'] ?? 'unknown';
		$classes = [
			'healthy'  => 'wpbridge-badge-success',
			'degraded' => 'wpbridge-badge-warning',
			'failed'   => 'wpbridge-badge-danger',
		];
		$labels  = [
			'healthy'  => __( '正常', 'wpbridge' ),
			'degraded' => __( '降级', 'wpbridge' ),
			'failed'   => __( '失败', 'wpbridge' ),
		];
		echo '<span class="wpbridge-badge ' . esc_attr( $classes[ $s ] ?? '' ) . '">' . esc_html( $labels[ $s ] ?? $s ) . '</span>';
		if ( ! empty( $status['response_time'] ) ) {
			echo '<span class="wpbridge-source-test-time">' . esc_html( $status['response_time'] ) . 'ms</span>';
		}
	}
};
?>

<div class="wpbridge-sources-section">
	<!-- 工具栏 -->
	<div class="wpbridge-toolbar">
		<div class="wpbridge-toolbar-left">
			<span class="wpbridge-toolbar-info">
				<?php
				printf(
					/* translators: 1: total count, 2: enabled count */
					esc_html__( '共 %1$d 个更新源，%2$d 个已启用', 'wpbridge' ),
					$total_count,
					$enabled_count
				);
				?>
			</span>
		</div>
		<div class="wpbridge-toolbar-right">
			<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-all-sources">
				<span class="dashicons dashicons-admin-site-alt3"></span>
				<?php esc_html_e( '测试全部', 'wpbridge' ); ?>
			</button>
			<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm" id="wpbridge-show-add-source">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( '添加更新源', 'wpbridge' ); ?>
			</button>
		</div>
	</div>

	<!-- 内联添加表单（默认隐藏） -->
	<div class="wpbridge-add-source-form" id="wpbridge-add-source-form" style="display: none;">
		<div class="wpbridge-add-source-card">
			<div class="wpbridge-add-source-header">
				<span class="dashicons dashicons-plus-alt2"></span>
				<span><?php esc_html_e( '添加更新源', 'wpbridge' ); ?></span>
			</div>
			<div class="wpbridge-add-source-body">
				<div class="wpbridge-add-source-row">
					<label class="wpbridge-add-source-label"><?php esc_html_e( '地址', 'wpbridge' ); ?> <span class="required">*</span></label>
					<div class="wpbridge-add-source-field">
						<input type="url" id="wpbridge-new-source-url" class="wpbridge-form-input"
								placeholder="<?php esc_attr_e( 'https://github.com/user/repo 或 JSON/ZIP 地址', 'wpbridge' ); ?>"
								autocomplete="off">
						<p class="wpbridge-form-help"><?php esc_html_e( '支持 GitHub/GitLab/Gitee/feiCode 仓库、JSON API、ZIP 地址、Bridge API 端点，系统自动识别类型', 'wpbridge' ); ?></p>
					</div>
				</div>
				<div class="wpbridge-add-source-row">
					<label class="wpbridge-add-source-label"><?php esc_html_e( '访问令牌', 'wpbridge' ); ?></label>
					<div class="wpbridge-add-source-field">
						<input type="password" id="wpbridge-new-source-token" class="wpbridge-form-input"
								placeholder="<?php esc_attr_e( '私有仓库填写，公开仓库留空', 'wpbridge' ); ?>"
								autocomplete="new-password">
					</div>
				</div>
			</div>
			<div class="wpbridge-add-source-footer">
				<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm" id="wpbridge-submit-source">
					<span class="dashicons dashicons-saved"></span>
					<?php esc_html_e( '添加', 'wpbridge' ); ?>
				</button>
				<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm" id="wpbridge-cancel-add-source">
					<?php esc_html_e( '取消', 'wpbridge' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- 源列表 -->
	<div class="wpbridge-source-list">
		<?php if ( $total_count === 0 ) : ?>
			<div class="wpbridge-empty">
				<span class="dashicons dashicons-cloud"></span>
				<h3><?php esc_html_e( '暂无更新源', 'wpbridge' ); ?></h3>
				<p><?php esc_html_e( '点击「添加更新源」连接私有仓库或自托管服务器，或在供应商 Tab 中激活预设供应商。', 'wpbridge' ); ?></p>
			</div>
		<?php else : ?>

			<?php // ── 预设源 ── ?>
			<?php if ( ! empty( $preset_sources ) ) : ?>
				<div class="wpbridge-source-group">
					<div class="wpbridge-source-group-header"><?php esc_html_e( '预设源', 'wpbridge' ); ?></div>
					<?php foreach ( $preset_sources as $source ) : ?>
						<div class="wpbridge-source-list-item <?php echo $source->enabled ? '' : 'wpbridge-source-disabled'; ?>" data-source-id="<?php echo esc_attr( $source->id ); ?>">
							<div class="wpbridge-source-list-info">
								<div class="wpbridge-source-list-name">
									<?php echo esc_html( $source->name ); ?>
									<span class="wpbridge-badge wpbridge-badge-type"><?php echo esc_html( SourceType::get_label( $source->type ) ); ?></span>
								</div>
								<div class="wpbridge-source-list-meta">
									<span class="wpbridge-source-list-url"><?php echo esc_html( $source->api_url ); ?></span>
									<span class="wpbridge-source-test-status"><?php $render_test_status( $source->id, $source->enabled ); ?></span>
								</div>
							</div>
							<div class="wpbridge-source-list-actions">
								<button type="button"
										class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-single-source"
										data-source-id="<?php echo esc_attr( $source->id ); ?>"
										<?php disabled( ! $source->enabled ); ?>
										title="<?php esc_attr_e( '测试连通性', 'wpbridge' ); ?>">
									<span class="dashicons dashicons-admin-site-alt3"></span>
								</button>
								<label class="wpbridge-toggle wpbridge-toggle-sm">
									<input type="checkbox" class="wpbridge-toggle-source"
											<?php checked( $source->enabled ); ?>
											data-source-id="<?php echo esc_attr( $source->id ); ?>">
									<span class="wpbridge-toggle-track"></span>
								</label>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php // ── 供应商源 ── ?>
			<?php if ( ! empty( $vendor_sources ) ) : ?>
				<div class="wpbridge-source-group">
					<div class="wpbridge-source-group-header"><?php esc_html_e( '供应商', 'wpbridge' ); ?></div>
					<?php foreach ( $vendor_sources as $vs ) : ?>
						<div class="wpbridge-source-list-item" data-source-id="<?php echo esc_attr( $vs['source_key'] ); ?>">
							<div class="wpbridge-source-list-info">
								<div class="wpbridge-source-list-name">
									<?php echo esc_html( $vs['name'] ?? '' ); ?>
									<span class="wpbridge-badge wpbridge-badge-vendor"><?php esc_html_e( '供应商', 'wpbridge' ); ?></span>
								</div>
								<div class="wpbridge-source-list-meta">
									<span class="wpbridge-source-list-url"><?php echo esc_html( $vs['api_url'] ?? '' ); ?></span>
								</div>
							</div>
							<div class="wpbridge-source-list-actions">
								<label class="wpbridge-toggle wpbridge-toggle-sm">
									<input type="checkbox" class="wpbridge-toggle-source"
											<?php checked( ! empty( $vs['enabled'] ) ); ?>
											data-source-id="<?php echo esc_attr( $vs['source_key'] ); ?>">
									<span class="wpbridge-toggle-track"></span>
								</label>
								<span class="wpbridge-source-list-hint"><?php esc_html_e( '由供应商管理', 'wpbridge' ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php // ── 自定义源 ── ?>
			<?php if ( ! empty( $custom_sources ) ) : ?>
				<div class="wpbridge-source-group">
					<div class="wpbridge-source-group-header"><?php esc_html_e( '自定义源', 'wpbridge' ); ?></div>
					<?php foreach ( $custom_sources as $source ) : ?>
						<div class="wpbridge-source-list-item <?php echo $source->enabled ? '' : 'wpbridge-source-disabled'; ?>" data-source-id="<?php echo esc_attr( $source->id ); ?>">
							<div class="wpbridge-source-list-info">
								<div class="wpbridge-source-list-name">
									<?php echo esc_html( $source->name ?: $source->id ); ?>
									<span class="wpbridge-badge wpbridge-badge-type"><?php echo esc_html( SourceType::get_label( $source->type ) ); ?></span>
								</div>
								<div class="wpbridge-source-list-meta">
									<span class="wpbridge-source-list-url"><?php echo esc_html( $source->api_url ); ?></span>
									<span class="wpbridge-source-test-status"><?php $render_test_status( $source->id, $source->enabled ); ?></span>
								</div>
							</div>
							<div class="wpbridge-source-list-actions">
								<button type="button"
										class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-single-source"
										data-source-id="<?php echo esc_attr( $source->id ); ?>"
										<?php disabled( ! $source->enabled ); ?>
										title="<?php esc_attr_e( '测试连通性', 'wpbridge' ); ?>">
									<span class="dashicons dashicons-admin-site-alt3"></span>
								</button>
								<label class="wpbridge-toggle wpbridge-toggle-sm">
									<input type="checkbox" class="wpbridge-toggle-source"
											<?php checked( $source->enabled ); ?>
											data-source-id="<?php echo esc_attr( $source->id ); ?>">
									<span class="wpbridge-toggle-track"></span>
								</label>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=edit&source=' . $source->id ) ); ?>"
									class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm"
									title="<?php esc_attr_e( '编辑', 'wpbridge' ); ?>">
									<span class="dashicons dashicons-edit"></span>
								</a>
								<button type="button" class="wpbridge-btn wpbridge-btn-danger wpbridge-btn-sm wpbridge-delete-source"
										data-source-id="<?php echo esc_attr( $source->id ); ?>"
										title="<?php esc_attr_e( '删除', 'wpbridge' ); ?>">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php endif; ?>
	</div>
</div>
