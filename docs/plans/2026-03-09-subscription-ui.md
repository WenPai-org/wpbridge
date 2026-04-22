# 订阅状态 UI 与功能门控 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 在 WPBridge 后台界面展示订阅状态、对付费功能实施门控

**Architecture:** 在 main.php 准备 `$subscription` 变量供所有 tab 共享，各 tab 模板根据 `$subscription['plan']` 条件渲染禁用状态，CSS 提供统一的门控样式，JS 处理刷新按钮 AJAX。

**Tech Stack:** PHP 模板（WordPress admin）、CSS、vanilla JS（现有 admin.js 模式）

---

### Task 1: 准备订阅数据变量（main.php）

**Files:**
- Modify: `templates/admin/main.php:22-41`

**Step 1: 添加订阅数据获取**

在 main.php 的数据准备区，`$health_status` 循环之后（L41 后），添加：

```php
// 订阅状态（供所有 tab 使用）
$subscription = $bridge_manager->get_subscription();
$is_feature_locked = static function( string $feature ) use ( $subscription ): bool {
	return ! in_array( $feature, $subscription['features'] ?? [], true );
};
```

注意：`$bridge_manager` 在 vendors.php 中实例化（L21），但 main.php 中还没有。需要在 main.php 中也创建 BridgeManager 实例。检查 vendors.php 的实例化方式并在 main.php 中复制。

vendors.php L20-21:
```php
$remote_config  = RemoteConfig::get_instance();
$bridge_manager = new BridgeManager( $settings_obj, $remote_config );
```

在 main.php L27 后（`$settings = $settings_obj->get_all();` 之后）添加：

```php
use WPBridge\Core\RemoteConfig;
use WPBridge\Commercial\BridgeManager;
```

以及数据准备：
```php
$remote_config  = RemoteConfig::get_instance();
$bridge_manager = new BridgeManager( $settings_obj, $remote_config );
$subscription   = $bridge_manager->get_subscription();
$is_feature_locked = static function( string $feature ) use ( $subscription ): bool {
	return ! in_array( $feature, $subscription['features'] ?? [], true );
};
```

同时需要从 vendors.php 中删除重复的 BridgeManager 实例化，改用 main.php 传下来的 `$bridge_manager`。

**Step 2: 验证**

确认 vendors.php 中 `$bridge_manager` 变量仍可用（因为 include 共享作用域）。

**Step 3: Commit**

```bash
git add templates/admin/main.php templates/admin/tabs/vendors.php
git commit -m "refactor: move BridgeManager init to main.php, add subscription data for all tabs"
```

---

### Task 2: 门控样式（CSS）

**Files:**
- Modify: `assets/css/vendors.css`

**Step 1: 添加样式**

在 vendors.css 末尾追加：

```css
/* ── 订阅状态 ── */
.wpbridge-subscription-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 0;
	font-size: 13px;
	color: var(--wpbridge-gray-600);
}

.wpbridge-subscription-badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
	background: var(--wpbridge-primary-50);
	color: var(--wpbridge-primary-600);
}

.wpbridge-subscription-badge.is-free {
	background: var(--wpbridge-gray-100);
	color: var(--wpbridge-gray-500);
}

.wpbridge-refresh-subscription {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--wpbridge-gray-400);
	padding: 2px;
	line-height: 1;
	font-size: 16px;
	transition: color 0.2s;
}

.wpbridge-refresh-subscription:hover {
	color: var(--wpbridge-primary-600);
}

.wpbridge-refresh-subscription.is-loading .dashicons {
	animation: wpbridge-spin 1s linear infinite;
}

@keyframes wpbridge-spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}

/* ── 功能门控 ── */
.wpbridge-feature-locked {
	opacity: 0.5;
	pointer-events: none;
	user-select: none;
	position: relative;
}

.wpbridge-upgrade-notice {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	margin-bottom: 16px;
	background: var(--wpbridge-warning-50, #fffbeb);
	border: 1px solid var(--wpbridge-warning-200, #fde68a);
	border-radius: 8px;
	font-size: 13px;
	color: var(--wpbridge-warning-700, #b45309);
}

.wpbridge-upgrade-notice .dashicons {
	color: var(--wpbridge-warning-500, #f59e0b);
}

.wpbridge-lock-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 500;
	background: var(--wpbridge-gray-100);
	color: var(--wpbridge-gray-500);
}
```

**Step 2: 验证 CSS 变量存在**

检查 variables.css 确认 `--wpbridge-primary-50`、`--wpbridge-primary-600`、`--wpbridge-gray-*` 等变量已定义。若 warning 系列不存在则使用内联 fallback（已在上面用 fallback 值）。

**Step 3: Commit**

```bash
git add assets/css/vendors.css
git commit -m "style: add subscription status and feature gating CSS"
```

---

### Task 3: 薇晓朵卡片订阅状态 + Bridge API 按钮门控（vendors.php）

**Files:**
- Modify: `templates/admin/tabs/vendors.php:92-141`

**Step 1: 在卡片循环中添加订阅状态行**

在 preset card 的 `wpbridge-vendor-preset-desc` div 之后、`wpbridge-vendor-preset-actions` div 之前，添加条件渲染：

```php
<?php if ( $is_activated && ! empty( $preset['subscription_vendor'] ) ) :
	$plan_label = $subscription['label'] ?? $subscription['plan'] ?? 'free';
	$is_free    = ( $subscription['plan'] ?? 'free' ) === 'free';
?>
	<div class="wpbridge-subscription-status">
		<span><?php esc_html_e( '订阅', 'wpbridge' ); ?>:</span>
		<span class="wpbridge-subscription-badge <?php echo $is_free ? 'is-free' : ''; ?>">
			<?php echo esc_html( $plan_label ); ?>
		</span>
		<button type="button" class="wpbridge-refresh-subscription" title="<?php esc_attr_e( '刷新订阅状态', 'wpbridge' ); ?>">
			<span class="dashicons dashicons-update"></span>
		</button>
	</div>
<?php endif; ?>
```

**Step 2: Bridge API "添加连接"按钮门控**

找到 `$is_multi` 条件块中的"添加连接"按钮（L124-127），加门控：

```php
<?php elseif ( $is_multi ) : ?>
	<?php if ( $is_feature_locked( 'bridge_api' ) ) : ?>
		<button type="button" class="button button-primary wpbridge-add-bridge-vendor-btn" disabled>
			<span class="dashicons dashicons-lock"></span>
			<?php esc_html_e( '需要 Pro', 'wpbridge' ); ?>
		</button>
	<?php else : ?>
		<button type="button" class="button button-primary wpbridge-add-bridge-vendor-btn">
			<span class="dashicons dashicons-plus-alt2"></span>
			<?php esc_html_e( '添加连接', 'wpbridge' ); ?>
		</button>
	<?php endif; ?>
```

**Step 3: Commit**

```bash
git add templates/admin/tabs/vendors.php
git commit -m "feat: subscription status in vendor card + bridge API button gating"
```

---

### Task 4: Bridge Server 配置门控（settings.php）

**Files:**
- Modify: `templates/admin/tabs/settings.php:98-143`

**Step 1: 在 Bridge Server 区块添加门控**

在 Bridge Server 标题（L100-103）后面加"需要 Pro"标签：

```php
<h3 style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: var(--wpbridge-gray-700);">
	<span class="dashicons dashicons-cloud" style="margin-right: 4px;"></span>
	<?php esc_html_e( 'Bridge Server', 'wpbridge' ); ?>
	<?php if ( $is_feature_locked( 'bridge_server' ) ) : ?>
		<span class="wpbridge-lock-badge">
			<span class="dashicons dashicons-lock" style="font-size: 14px; width: 14px; height: 14px;"></span>
			<?php esc_html_e( '需要 Pro', 'wpbridge' ); ?>
		</span>
	<?php endif; ?>
</h3>
```

用 `wpbridge-feature-locked` class 包裹 Bridge Server 的设置行：

```php
<div class="<?php echo $is_feature_locked( 'bridge_server' ) ? 'wpbridge-feature-locked' : ''; ?>">
	<!-- 原有的 3 个 wpbridge-settings-row -->
</div>
```

**Step 2: Commit**

```bash
git add templates/admin/tabs/settings.php
git commit -m "feat: bridge server config gating for free users"
```

---

### Task 5: Bridge API Tab 门控（api.php）

**Files:**
- Modify: `templates/admin/tabs/api.php`

**Step 1: 在表单顶部添加升级提示**

在 `<form>` 标签之前：

```php
<?php if ( $is_feature_locked( 'bridge_api' ) ) : ?>
	<div class="wpbridge-upgrade-notice">
		<span class="dashicons dashicons-lock"></span>
		<?php esc_html_e( 'Bridge API 需要 Pro 及以上订阅才能使用。', 'wpbridge' ); ?>
	</div>
<?php endif; ?>
```

**Step 2: 表单和 API Keys 区块加门控 class**

用 `wpbridge-feature-locked` class 包裹 `<form>` 和 API Keys 管理区：

```php
<div class="<?php echo $is_feature_locked( 'bridge_api' ) ? 'wpbridge-feature-locked' : ''; ?>">
	<form ...>
	...
	</form>
	<!-- API Keys 管理 -->
	...
</div>
```

**Step 3: Commit**

```bash
git add templates/admin/tabs/api.php
git commit -m "feat: bridge API tab gating for free users"
```

---

### Task 6: 刷新按钮 JS（admin.js）

**Files:**
- Modify: `assets/js/admin.js`

**Step 1: 在 vendors 模块中添加刷新订阅事件**

找到 vendors 相关的事件绑定区域，添加：

```javascript
// 刷新订阅状态
$(document).on('click', '.wpbridge-refresh-subscription', function(e) {
	e.preventDefault();
	var $btn = $(this);
	if ($btn.hasClass('is-loading')) return;

	$btn.addClass('is-loading');

	$.ajax({
		url: wpbridge.ajaxUrl,
		method: 'POST',
		data: {
			action: 'wpbridge_refresh_subscription',
			_ajax_nonce: wpbridge.nonce
		},
		success: function(response) {
			if (response.success && response.data) {
				var label = response.data.label || response.data.plan || 'free';
				var isFree = response.data.plan === 'free';
				$btn.siblings('.wpbridge-subscription-badge')
					.text(label)
					.toggleClass('is-free', isFree);
				WPBridge.Toast.success(wpbridge.i18n.subscriptionRefreshed || '订阅状态已刷新');
			}
		},
		error: function() {
			WPBridge.Toast.error(wpbridge.i18n.subscriptionRefreshFailed || '刷新失败');
		},
		complete: function() {
			$btn.removeClass('is-loading');
		}
	});
});
```

**Step 2: 在预设激活成功回调中渲染订阅状态**

找到 `wpbridge_activate_preset` 的 success 回调，在成功后检查 `response.data.subscription`，若存在则在卡片中插入订阅状态行。

**Step 3: Commit**

```bash
git add assets/js/admin.js
git commit -m "feat: subscription refresh AJAX handler"
```

---

### Task 7: 验证与部署

**Step 1: 本地检查代码规范**

```bash
cd ~/Projects/wpbridge
# PHP 语法检查
php -l templates/admin/main.php
php -l templates/admin/tabs/vendors.php
php -l templates/admin/tabs/settings.php
php -l templates/admin/tabs/api.php
```

**Step 2: 部署到 wpcy.com 测试**

使用 push-feicode.sh 推送，在 wpcy.com 上验证：
1. 薇晓朵卡片激活后显示订阅等级标签
2. 刷新按钮可用
3. free 用户时 Bridge API Tab 变灰
4. free 用户时 Bridge Server 配置变灰
5. free 用户时 Bridge API 添加连接按钮禁用

**Step 3: Commit 验证结果**

确认无误后完成。
