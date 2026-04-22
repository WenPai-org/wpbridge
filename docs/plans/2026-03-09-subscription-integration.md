# 商城订阅产品集成 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 将薇晓朵商城 3 个订阅产品 (全站通/Pro/Pro企业版) 集成到 WPBridge，通过 WC AM API 验证购买状态并解锁对应功能。

**Architecture:** 新增 `SubscriptionManager` 类，在用户激活薇晓朵商城预设后调用 `wc_am_product_list()` 获取已购产品，通过 product_id → plan 硬编码映射确定订阅等级。`BridgeManager::get_subscription()` 改为委托给 `SubscriptionManager`。功能门控通过 `is_feature_enabled()` 在各模块入口检查。

**Tech Stack:** PHP 7.4+, WordPress 5.9+, WooCommerce API Manager (Kestrel)

---

### Task 1: 创建 SubscriptionManager 核心类

**Files:**
- Create: `includes/Commercial/SubscriptionManager.php`

**Step 1: 创建 SubscriptionManager 类**

```php
<?php
/**
 * 订阅管理器
 *
 * 通过 WC AM API 验证用户购买状态，管理订阅等级和功能门控
 *
 * @package WPBridge
 * @since 1.1.0
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Commercial\Vendors\VendorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubscriptionManager {

	/**
	 * 订阅供应商 ID（薇晓朵商城）
	 */
	private const SUBSCRIPTION_VENDOR_ID = 'weixiaoduo-store';

	/**
	 * 订阅缓存 transient key
	 */
	private const CACHE_KEY = 'wpbridge_subscription';

	/**
	 * 缓存时长（秒）
	 */
	private const CACHE_TTL = 3600;

	/**
	 * 产品 → 订阅等级映射
	 *
	 * 等级优先级: all_access > pro_enterprise > pro > free
	 */
	private const PRODUCT_PLANS = [
		41705 => [
			'plan'            => 'all_access',
			'label'           => '全站通',
			'priority'        => 100,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => PHP_INT_MAX,
			'features'        => [ 'bridge_api', 'bridge_server', 'license_proxy', 'priority_support' ],
		],
		41706 => [
			'plan'            => 'pro',
			'label'           => 'Pro',
			'priority'        => 10,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => 50,
			'features'        => [ 'bridge_api', 'bridge_server' ],
		],
		41707 => [
			'plan'            => 'pro_enterprise',
			'label'           => 'Pro 企业版',
			'priority'        => 50,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => 500,
			'features'        => [ 'bridge_api', 'bridge_server', 'priority_support' ],
		],
	];

	/**
	 * Free 计划默认值
	 */
	private const FREE_PLAN = [
		'plan'            => 'free',
		'label'           => '免费版',
		'priority'        => 0,
		'plugins_limit'   => 0,
		'daily_downloads' => 0,
		'features'        => [],
		'product_id'      => 0,
	];

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var VendorManager
	 */
	private VendorManager $vendor_manager;

	public function __construct( Settings $settings, VendorManager $vendor_manager ) {
		$this->settings       = $settings;
		$this->vendor_manager = $vendor_manager;
	}

	/**
	 * 获取当前订阅信息
	 *
	 * @param bool $force_refresh 强制刷新（跳过缓存）
	 * @return array
	 */
	public function get_subscription( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$subscription = $this->resolve_subscription();

		set_transient( self::CACHE_KEY, $subscription, self::CACHE_TTL );

		return $subscription;
	}

	/**
	 * 从商城 API 解析当前订阅等级
	 *
	 * @return array
	 */
	private function resolve_subscription(): array {
		$vendor = $this->vendor_manager->get_vendor( self::SUBSCRIPTION_VENDOR_ID );

		if ( null === $vendor ) {
			return self::FREE_PLAN;
		}

		if ( ! method_exists( $vendor, 'wc_am_product_list' ) ) {
			return self::FREE_PLAN;
		}

		$response = $vendor->wc_am_product_list();

		if ( empty( $response['success'] ) ) {
			Logger::warning( 'Subscription check failed', [
				'vendor'   => self::SUBSCRIPTION_VENDOR_ID,
				'response' => $response,
			] );
			return self::FREE_PLAN;
		}

		$product_ids = $this->extract_product_ids( $response );

		if ( empty( $product_ids ) ) {
			return self::FREE_PLAN;
		}

		return $this->resolve_plan( $product_ids );
	}

	/**
	 * 从 WC AM product_list 响应中提取 product_id 列表
	 *
	 * 适配 Kestrel API Manager 的嵌套响应格式
	 *
	 * @param array $response WC AM API 响应
	 * @return int[]
	 */
	private function extract_product_ids( array $response ): array {
		$product_ids = [];
		$product_list = $response['data']['product_list'] ?? [];

		// Kestrel 格式: product_list.non_wc_subs_resources
		$resources = $product_list['non_wc_subs_resources']
			?? $product_list['wc_subs_resources']
			?? $product_list;

		if ( ! is_array( $resources ) ) {
			return [];
		}

		foreach ( $resources as $resource ) {
			if ( isset( $resource['product_id'] ) ) {
				$product_ids[] = (int) $resource['product_id'];
			}
		}

		return $product_ids;
	}

	/**
	 * 根据 product_id 列表确定最高订阅等级
	 *
	 * @param int[] $product_ids 已购产品 ID 列表
	 * @return array
	 */
	private function resolve_plan( array $product_ids ): array {
		/**
		 * 允许扩展产品映射
		 *
		 * @param array $plans product_id => plan config
		 */
		$plans = apply_filters( 'wpbridge_subscription_product_plans', self::PRODUCT_PLANS );

		$best_plan = self::FREE_PLAN;

		foreach ( $product_ids as $product_id ) {
			if ( ! isset( $plans[ $product_id ] ) ) {
				continue;
			}

			$plan = $plans[ $product_id ];
			$plan['product_id'] = $product_id;

			if ( $plan['priority'] > $best_plan['priority'] ) {
				$best_plan = $plan;
			}
		}

		$best_plan['status']     = 'active';
		$best_plan['checked_at'] = time();

		return $best_plan;
	}

	/**
	 * 检查是否启用了指定功能
	 *
	 * @param string $feature 功能标识 (bridge_api, bridge_server, license_proxy, priority_support)
	 * @return bool
	 */
	public function is_feature_enabled( string $feature ): bool {
		$subscription = $this->get_subscription();
		return in_array( $feature, $subscription['features'] ?? [], true );
	}

	/**
	 * 获取当前计划名称
	 *
	 * @return string
	 */
	public function get_plan(): string {
		$subscription = $this->get_subscription();
		return $subscription['plan'] ?? 'free';
	}

	/**
	 * 获取当前计划限制
	 *
	 * @return array
	 */
	public function get_limits(): array {
		$subscription = $this->get_subscription();
		return [
			'plugins_limit'   => $subscription['plugins_limit'] ?? 0,
			'daily_downloads' => $subscription['daily_downloads'] ?? 0,
		];
	}

	/**
	 * 是否为付费用户
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return $this->get_plan() !== 'free';
	}

	/**
	 * 清除订阅缓存
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * 获取订阅供应商 ID
	 *
	 * @return string
	 */
	public static function get_subscription_vendor_id(): string {
		return self::SUBSCRIPTION_VENDOR_ID;
	}
}
```

**Step 2: 运行语法检查**

Run: `cd ~/Projects/wpbridge && php -l includes/Commercial/SubscriptionManager.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
cd ~/Projects/wpbridge
git add includes/Commercial/SubscriptionManager.php
git commit -m "feat: add SubscriptionManager for commercial product integration

Maps 薇晓朵商城 product_ids (41705/41706/41707) to subscription tiers
(all_access/pro/pro_enterprise). Reads WC AM product_list via existing
WooCommerceVendor, caches 1 hour, supports filter hook extension."
```

---

### Task 2: 改造 BridgeManager 使用 SubscriptionManager

**Files:**
- Modify: `includes/Commercial/BridgeManager.php`

**Step 1: 添加 SubscriptionManager 依赖**

在 `BridgeManager` 类顶部添加 use 和属性:

```php
// 在 existing use 语句后添加:
use WPBridge\Commercial\SubscriptionManager;
```

添加属性（在 `$bridge_client` 属性后）:

```php
	/**
	 * 订阅管理器
	 *
	 * @var SubscriptionManager|null
	 */
	private ?SubscriptionManager $subscription_manager = null;
```

**Step 2: 在构造函数末尾初始化 SubscriptionManager**

在 `__construct()` 方法的 `$this->init_vendors();` 之后添加:

```php
		// 初始化订阅管理器
		$this->subscription_manager = new SubscriptionManager( $settings, $this->vendor_manager );
```

**Step 3: 重写 get_subscription()**

替换整个 `get_subscription()` 方法:

```php
	/**
	 * 获取订阅信息
	 *
	 * 委托给 SubscriptionManager，从商城 WC AM API 获取真实订阅状态
	 *
	 * @param bool $force_refresh 强制刷新缓存
	 * @return array
	 */
	public function get_subscription( bool $force_refresh = false ): array {
		if ( null === $this->subscription_manager ) {
			return [
				'plan'          => 'free',
				'plugins_limit' => 0,
				'daily_downloads' => 0,
				'features'      => [],
				'status'        => 'active',
			];
		}

		return $this->subscription_manager->get_subscription( $force_refresh );
	}
```

**Step 4: 更新 check_subscription_limit()**

替换 `check_subscription_limit()` 方法:

```php
	/**
	 * 检查订阅限制
	 *
	 * @return array
	 */
	private function check_subscription_limit(): array {
		$subscription = $this->get_subscription();

		$plugins_limit = $subscription['plugins_limit'] ?? 0;

		// 无限制
		if ( $plugins_limit === PHP_INT_MAX ) {
			return [ 'allowed' => true ];
		}

		$current_count = count( $this->get_bridged_plugins() );

		if ( $current_count >= $plugins_limit ) {
			$plan_label = $subscription['label'] ?? $subscription['plan'] ?? 'free';
			return [
				'allowed' => false,
				'message' => sprintf(
					/* translators: 1: current plan label, 2: plugin limit */
					__( '当前 %1$s 计划最多桥接 %2$d 个插件，请升级订阅', 'wpbridge' ),
					$plan_label,
					$plugins_limit
				),
			];
		}

		return [ 'allowed' => true ];
	}
```

**Step 5: 添加 get_subscription_manager() getter**

在 `get_vendor_manager()` 方法后添加:

```php
	/**
	 * 获取订阅管理器
	 *
	 * @return SubscriptionManager|null
	 */
	public function get_subscription_manager(): ?SubscriptionManager {
		return $this->subscription_manager;
	}
```

**Step 6: 更新 get_stats()**

替换 `get_stats()` 方法中的 `can_add_more` 逻辑:

```php
	/**
	 * 获取桥接状态统计
	 *
	 * @return array
	 */
	public function get_stats(): array {
		$subscription = $this->get_subscription();
		$bridged      = $this->get_bridged_plugins();
		$available    = $this->get_available_plugins();

		$plugins_limit = $subscription['plugins_limit'] ?? 0;

		return [
			'bridged_count'   => count( $bridged ),
			'available_count' => count( $available ),
			'plan'            => $subscription['plan'] ?? 'free',
			'plan_label'      => $subscription['label'] ?? '免费版',
			'plugins_limit'   => $plugins_limit,
			'plugins_used'    => count( $bridged ),
			'can_add_more'    => $plugins_limit === PHP_INT_MAX || count( $bridged ) < $plugins_limit,
			'features'        => $subscription['features'] ?? [],
			'is_paid'         => ( $subscription['plan'] ?? 'free' ) !== 'free',
		];
	}
```

**Step 7: 运行语法检查**

Run: `cd ~/Projects/wpbridge && php -l includes/Commercial/BridgeManager.php`
Expected: `No syntax errors detected`

**Step 8: Commit**

```bash
cd ~/Projects/wpbridge
git add includes/Commercial/BridgeManager.php
git commit -m "refactor: BridgeManager delegates subscription to SubscriptionManager

get_subscription() now reads real purchase data from WC AM API instead of
returning hardcoded defaults. check_subscription_limit() uses actual plan
limits. get_stats() includes plan_label, features, is_paid."
```

---

### Task 3: 预设激活时触发订阅检测

**Files:**
- Modify: `includes/Admin/VendorAdmin.php`
- Modify: `includes/Commercial/Vendors/PresetRegistry.php`

**Step 1: 在 PresetRegistry 中标记订阅供应商**

在 `weixiaoduo-store` 预设配置中添加 `subscription_vendor` 字段:

```php
			'weixiaoduo-store'   => [
				'name'                => '薇晓朵商城',
				'description'         => '薇晓朵 WordPress 商业插件商城，购买后输入邮箱和授权密钥即可自动接收更新。',
				'type'                => 'wc_am',
				'auth_mode'           => 'wc_am',
				'api_url'             => 'https://mall.weixiaoduo.com',
				'status'              => 'available',
				'icon'                => 'dashicons-cart',
				'auth_fields'         => [ 'email', 'license_key' ],
				'is_preset'           => true,
				'removable'           => false,
				'subscription_vendor' => true,
			],
```

**Step 2: 在 VendorAdmin::ajax_activate_preset() 中添加订阅刷新**

在 `ajax_activate_preset()` 方法中，`$result['product_count'] = $product_count;` 之前添加订阅检测逻辑:

```php
		// 如果是订阅供应商，刷新订阅状态
		if ( ! empty( $preset['subscription_vendor'] ) ) {
			$sub_manager = $this->get_bridge_manager()->get_subscription_manager();
			if ( $sub_manager ) {
				$sub_manager->clear_cache();
				$subscription = $sub_manager->get_subscription( true );
				$result['subscription'] = [
					'plan'  => $subscription['plan'] ?? 'free',
					'label' => $subscription['label'] ?? '免费版',
				];
			}
		}
```

**Step 3: 在 VendorAdmin::ajax_deactivate_preset() 中清除订阅缓存**

在 `ajax_deactivate_preset()` 方法中，`$result = $this->get_bridge_manager()->remove_vendor( $preset_id );` 之后添加:

```php
		// 如果是订阅供应商，清除订阅缓存
		if ( ! empty( $preset['subscription_vendor'] ) ) {
			$sub_manager = $this->get_bridge_manager()->get_subscription_manager();
			if ( $sub_manager ) {
				$sub_manager->clear_cache();
			}
		}
```

**Step 4: 运行语法检查**

Run: `cd ~/Projects/wpbridge && php -l includes/Admin/VendorAdmin.php && php -l includes/Commercial/Vendors/PresetRegistry.php`
Expected: `No syntax errors detected` (x2)

**Step 5: Commit**

```bash
cd ~/Projects/wpbridge
git add includes/Admin/VendorAdmin.php includes/Commercial/Vendors/PresetRegistry.php
git commit -m "feat: trigger subscription detection on preset activation

When user activates 薇晓朵商城 preset, subscription cache is cleared and
re-fetched. Response includes plan info. Deactivation also clears cache.
PresetRegistry marks weixiaoduo-store as subscription_vendor."
```

---

### Task 4: 添加订阅状态 AJAX 端点

**Files:**
- Modify: `includes/Admin/VendorAdmin.php`

**Step 1: 注册 AJAX action**

在 `init_hooks()` 方法中 `// Bridge Server AJAX 处理` 之前添加:

```php
		// 订阅管理 AJAX 处理
		add_action( 'wp_ajax_wpbridge_get_subscription', [ $this, 'ajax_get_subscription' ] );
		add_action( 'wp_ajax_wpbridge_refresh_subscription', [ $this, 'ajax_refresh_subscription' ] );
```

**Step 2: 实现 ajax_get_subscription()**

在 `ajax_test_bridge_server()` 方法之前添加:

```php
	/**
	 * AJAX: 获取订阅状态
	 *
	 * @return void
	 */
	public function ajax_get_subscription(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$sub_manager = $this->get_bridge_manager()->get_subscription_manager();

		if ( ! $sub_manager ) {
			wp_send_json_error( [ 'message' => __( '订阅管理器未初始化', 'wpbridge' ) ] );
		}

		$subscription = $sub_manager->get_subscription();

		wp_send_json_success( [
			'subscription' => $subscription,
			'is_paid'      => $sub_manager->is_paid(),
		] );
	}

	/**
	 * AJAX: 刷新订阅状态
	 *
	 * @return void
	 */
	public function ajax_refresh_subscription(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$sub_manager = $this->get_bridge_manager()->get_subscription_manager();

		if ( ! $sub_manager ) {
			wp_send_json_error( [ 'message' => __( '订阅管理器未初始化', 'wpbridge' ) ] );
		}

		$sub_manager->clear_cache();
		$subscription = $sub_manager->get_subscription( true );

		wp_send_json_success( [
			'subscription' => $subscription,
			'is_paid'      => $sub_manager->is_paid(),
			'message'      => sprintf(
				/* translators: %s: plan label */
				__( '订阅状态已刷新：%s', 'wpbridge' ),
				$subscription['label'] ?? '免费版'
			),
		] );
	}
```

**Step 3: 在 get_vendor_data() 中添加订阅数据**

在 `get_vendor_data()` 的返回数组中添加 subscription 字段:

```php
		$sub_manager  = $this->get_bridge_manager()->get_subscription_manager();
		$subscription = $sub_manager ? $sub_manager->get_subscription() : null;

		return [
			'vendors'        => $vendors,
			'presets'        => $presets,
			'bridge_count'   => $bridge_count,
			'custom'         => $this->settings->get( 'custom_plugins', [] ),
			'all_plugins'    => $this->get_bridge_manager()->get_all_available_plugins(),
			'stats'          => $this->get_bridge_manager()->get_stats(),
			'subscription'   => $subscription,
			'vendor_types'   => [
				'woocommerce' => __( 'WooCommerce 商店', 'wpbridge' ),
				'wc_am'       => __( 'WC API Manager', 'wpbridge' ),
				'bridge_api'  => __( 'Bridge API', 'wpbridge' ),
			],
			'field_labels'       => PresetRegistry::get_auth_field_labels(),
			'field_placeholders' => PresetRegistry::get_auth_field_placeholders(),
			'status_labels'      => PresetRegistry::get_status_labels(),
		];
```

**Step 4: 运行语法检查**

Run: `cd ~/Projects/wpbridge && php -l includes/Admin/VendorAdmin.php`
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
cd ~/Projects/wpbridge
git add includes/Admin/VendorAdmin.php
git commit -m "feat: add subscription AJAX endpoints

wpbridge_get_subscription: read cached subscription state
wpbridge_refresh_subscription: force refresh from WC AM API
get_vendor_data() now includes subscription info for admin templates."
```

---

### Task 5: ShellCheck & 端到端验证

**Files:**
- All modified files

**Step 1: PHP 语法检查所有改动文件**

Run:
```bash
cd ~/Projects/wpbridge
php -l includes/Commercial/SubscriptionManager.php
php -l includes/Commercial/BridgeManager.php
php -l includes/Admin/VendorAdmin.php
php -l includes/Commercial/Vendors/PresetRegistry.php
```
Expected: All `No syntax errors detected`

**Step 2: 检查 PHPCS (如果可用)**

Run:
```bash
cd ~/Projects/wpbridge
if command -v phpcs &>/dev/null; then
  phpcs --standard=WordPress-Core --extensions=php \
    includes/Commercial/SubscriptionManager.php \
    includes/Commercial/BridgeManager.php \
    includes/Admin/VendorAdmin.php \
    includes/Commercial/Vendors/PresetRegistry.php
fi
```

**Step 3: 确认 autoload 兼容性**

检查插件是否使用 PSR-4 autoload 或手动 require:

Run: `grep -r 'SubscriptionManager' ~/Projects/wpbridge/includes/ --include='*.php'`

如果使用手动 require，需要在 `wpbridge.php` 或 autoloader 中添加。如果是 PSR-4 namespace autoload（`WPBridge\Commercial\SubscriptionManager` → `includes/Commercial/SubscriptionManager.php`），则无需额外操作。

**Step 4: 最终 Commit（如有修复）**

```bash
cd ~/Projects/wpbridge
git add -A
git commit -m "fix: address phpcs and autoload issues for subscription integration"
```
