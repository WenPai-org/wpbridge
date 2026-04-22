# 商城订阅产品集成设计

> 日期: 2026-03-09
> 状态: 已批准

## 概述

将薇晓朵商城的 3 个订阅产品集成到 WPBridge 插件端，通过 WC AM API 验证用户购买状态，解锁对应功能模块。

## 产品映射

| product_id | 产品名 | 价格 | 等级 |
|-----------|--------|------|------|
| 41705 | 全站通 | ¥6899/年 | all_access |
| 41706 | Pro | ¥698/年 | pro |
| 41707 | Pro 企业版 | ¥2999/年 | pro_enterprise |

## 验证流程

```
用户激活薇晓朵商城预设 (输入 email + Master API Key)
    ↓
WooCommerceVendor::wc_am_product_list()
    ↓ 返回已购产品列表
SubscriptionManager::resolve_plan(product_ids)
    ↓ product_id 映射到订阅等级，多产品取最高
BridgeManager::get_subscription() ← 读取 SubscriptionManager
    ↓
功能门控生效
```

## 新增组件

### SubscriptionManager (`includes/Commercial/SubscriptionManager.php`)

职责:
- 硬编码 product_id → plan 映射（通过 filter hook 可扩展）
- 从已激活的薇晓朵商城 vendor 读取产品列表
- 解析最高订阅等级
- 缓存订阅状态（transient，1小时）
- 提供 `get_plan()` / `get_limits()` / `is_feature_enabled()`

### 产品映射配置

```php
private const PRODUCT_PLANS = [
    41705 => [
        'plan'            => 'all_access',
        'label'           => '全站通',
        'plugins_limit'   => PHP_INT_MAX,
        'daily_downloads' => PHP_INT_MAX,
        'features'        => [ 'bridge_api', 'bridge_server', 'license_proxy', 'priority_support' ],
    ],
    41706 => [
        'plan'            => 'pro',
        'label'           => 'Pro',
        'plugins_limit'   => PHP_INT_MAX,
        'daily_downloads' => 50,
        'features'        => [ 'bridge_api', 'bridge_server' ],
    ],
    41707 => [
        'plan'            => 'pro_enterprise',
        'label'           => 'Pro 企业版',
        'plugins_limit'   => PHP_INT_MAX,
        'daily_downloads' => 500,
        'features'        => [ 'bridge_api', 'bridge_server', 'priority_support' ],
    ],
];
```

## 改动现有文件

| 文件 | 改动 |
|------|------|
| `BridgeManager::get_subscription()` | 改为调用 SubscriptionManager |
| `BridgeManager::check_subscription_limit()` | 使用真实订阅数据 |
| `VendorAdmin::ajax_activate_preset()` | 激活后触发订阅检测 |
| `PresetRegistry` | 薇晓朵预设加 subscription_vendor 标记 |
| `Plugin.php` | 初始化 SubscriptionManager |

## 功能门控

| 功能 | Free | Pro | Pro 企业版 | 全站通 |
|------|------|-----|-----------|--------|
| 自定义更新源 | 无限 | 无限 | 无限 | 无限 |
| Bridge API 模块 | 禁用 | 解锁 | 解锁 | 解锁 |
| Bridge Server 模块 | 禁用 | 解锁 | 解锁 | 解锁 |
| License Proxy | 禁用 | 禁用 | 禁用 | 解锁 |
| 每日下载次数 | 基础 | 50 | 500 | 无限 |
| 商业插件桥接数 | 0 | 无限 | 无限 | 无限 |

## 不做的事 (YAGNI)

- 下载计数（服务端职责）
- 本地离线验证
- 订阅过期定时任务（admin 页面访问时检查）
- UI 升级引导页（产品上线后再加）
