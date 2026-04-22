# 商业插件桥接技术规范

> 创建日期: 2026-02-15
> 状态: 待评审
> 版本: v1.0.0-draft

---

## 1. 概述

### 1.1 目标

为购买了商业插件但授权过期/无法续费的用户提供替代更新源，实现：
- 商业插件自动检测
- 授权验证代理
- 更新包下载桥接
- 订阅管理

### 1.2 核心原则

- **GPL 合规**: 只桥接 GPL 授权的插件
- **透明代理**: 不修改插件代码，只代理网络请求
- **用户自主**: 用户明确选择启用桥接

---

## 2. 系统架构

### 2.1 整体架构

```
┌─────────────────────────────────────────────────────────────────┐
│                        用户 WordPress 站点                        │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │ CommercialDetector│  │  LicenseProxy   │  │  UpdateBridge   │  │
│  │     (已有)        │  │    (新增)       │  │   (已有扩展)    │  │
│  └────────┬─────────┘  └────────┬────────┘  └────────┬────────┘  │
└───────────┼─────────────────────┼─────────────────────┼──────────┘
            │                     │                     │
            ▼                     ▼                     ▼
┌─────────────────────────────────────────────────────────────────┐
│                     文派云桥服务端 (wenpai-bridge)                 │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │ Plugin Registry │  │ License Service │  │  CDN / Storage  │  │
│  │   插件注册表     │  │   授权服务       │  │   下载存储      │  │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 数据流

```
1. 检测流程:
   用户站点 → CommercialDetector → 识别商业插件 → 显示桥接选项

2. 授权流程:
   商业插件授权请求 → LicenseProxy 拦截 → 文派授权服务 → 返回有效授权

3. 更新流程:
   WordPress 更新检查 → UpdateBridge → wenpai-bridge API → 返回更新信息

4. 下载流程:
   用户点击更新 → 下载请求 → 文派 CDN → 返回插件包
```

---

## 3. 客户端组件设计

### 3.1 LicenseProxy (新增)

#### 3.1.1 职责
- 拦截商业插件的授权验证 HTTP 请求
- 识别授权系统类型 (EDD, Freemius, WC_AM 等)
- 转发到文派授权代理服务
- 转换响应格式以匹配原厂 API

#### 3.1.2 类设计

```php
<?php
declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Logger;
use WPBridge\Core\Settings;

class LicenseProxy {
    /**
     * 支持的授权系统配置
     */
    private const VENDORS = [
        'edd' => [
            'name'     => 'EDD Software Licensing',
            'patterns' => [
                '/edd-sl/',
                '/edd-api/',
                'action=activate_license',
                'action=check_license',
                'action=deactivate_license',
            ],
            'response_format' => 'edd',
        ],
        'freemius' => [
            'name'     => 'Freemius',
            'patterns' => [
                'api.freemius.com',
                'wp-json/freemius',
            ],
            'response_format' => 'freemius',
        ],
        'wc_am' => [
            'name'     => 'WooCommerce API Manager',
            'patterns' => [
                'wc-api/wc-am-api',
                'wc-api/am-software-api',
            ],
            'response_format' => 'wc_am',
        ],
        'envato' => [
            'name'     => 'Envato Market',
            'patterns' => [
                'api.envato.com',
            ],
            'response_format' => 'envato',
        ],
    ];

    private Settings $settings;
    private array $bridged_plugins = [];

    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->bridged_plugins = $this->settings->get('bridged_plugins', []);
    }

    /**
     * 初始化钩子
     */
    public function init(): void {
        if (!$this->is_enabled()) {
            return;
        }
        add_filter('pre_http_request', [$this, 'intercept_request'], 5, 3);
    }

    /**
     * 检查是否启用
     */
    private function is_enabled(): bool {
        return (bool) $this->settings->get('license_proxy_enabled', false);
    }

    /**
     * 拦截 HTTP 请求
     */
    public function intercept_request($preempt, array $args, string $url) {
        // 1. 检测授权系统
        $vendor = $this->detect_vendor($url);
        if ($vendor === null) {
            return $preempt;
        }

        // 2. 提取插件标识
        $plugin_slug = $this->extract_plugin_slug($url, $args, $vendor);
        if ($plugin_slug === null) {
            return $preempt;
        }

        // 3. 检查是否在桥接列表
        if (!$this->is_bridged($plugin_slug)) {
            return $preempt;
        }

        Logger::debug('License proxy intercepting', [
            'vendor' => $vendor,
            'plugin' => $plugin_slug,
            'url'    => $url,
        ]);

        // 4. 代理到文派服务
        return $this->proxy_request($vendor, $plugin_slug, $url, $args);
    }

    /**
     * 检测授权系统供应商
     */
    private function detect_vendor(string $url): ?string {
        foreach (self::VENDORS as $vendor_key => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (stripos($url, $pattern) !== false) {
                    return $vendor_key;
                }
            }
        }
        return null;
    }

    /**
     * 提取插件 slug
     */
    private function extract_plugin_slug(string $url, array $args, string $vendor): ?string {
        // 从 URL 参数提取
        $parsed = wp_parse_url($url);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            
            // EDD 格式
            if (isset($query['item_name'])) {
                return sanitize_title($query['item_name']);
            }
            if (isset($query['item_id'])) {
                return $this->resolve_item_id($query['item_id']);
            }
        }

        // 从 POST body 提取
        if (isset($args['body']) && is_array($args['body'])) {
            if (isset($args['body']['item_name'])) {
                return sanitize_title($args['body']['item_name']);
            }
            if (isset($args['body']['product_id'])) {
                return $this->resolve_item_id($args['body']['product_id']);
            }
        }

        // Freemius 格式: /v1/plugins/{id}/...
        if ($vendor === 'freemius' && preg_match('#/plugins/(\d+)/#', $url, $matches)) {
            return $this->resolve_freemius_id($matches[1]);
        }

        return null;
    }

    /**
     * 检查插件是否在桥接列表
     */
    private function is_bridged(string $plugin_slug): bool {
        return in_array($plugin_slug, $this->bridged_plugins, true);
    }

    /**
     * 代理请求到文派服务
     */
    private function proxy_request(string $vendor, string $plugin_slug, string $original_url, array $args): array {
        $proxy_url = 'https://updates.wenpai.net/api/v1/license/proxy';

        $response = wp_remote_post($proxy_url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-WPBridge-Key'  => $this->get_api_key(),
                'X-WPBridge-Site' => home_url(),
            ],
            'body' => wp_json_encode([
                'vendor'       => $vendor,
                'plugin_slug'  => $plugin_slug,
                'original_url' => $original_url,
                'action'       => $this->extract_action($original_url, $args),
                'site_url'     => home_url(),
            ]),
        ]);

        if (is_wp_error($response)) {
            Logger::error('License proxy failed', [
                'error' => $response->get_error_message(),
            ]);
            // 失败时不拦截，让原始请求继续
            return false;
        }

        // 转换响应格式
        return $this->transform_response($vendor, $response);
    }

    /**
     * 转换响应格式以匹配原厂 API
     */
    private function transform_response(string $vendor, array $response): array {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['success']) || !$body['success']) {
            return false; // 让原始请求继续
        }

        $license = $body['license'] ?? [];
        
        // 根据不同授权系统返回不同格式
        switch ($vendor) {
            case 'edd':
                return $this->format_edd_response($license);
            case 'freemius':
                return $this->format_freemius_response($license);
            case 'wc_am':
                return $this->format_wc_am_response($license);
            default:
                return $this->format_generic_response($license);
        }
    }

    /**
     * 格式化 EDD 响应
     */
    private function format_edd_response(array $license): array {
        $body = wp_json_encode([
            'success'          => true,
            'license'          => $license['status'] ?? 'valid',
            'item_name'        => $license['item_name'] ?? '',
            'expires'          => $license['expires'] ?? 'lifetime',
            'license_limit'    => $license['license_limit'] ?? 0,
            'site_count'       => $license['site_count'] ?? 1,
            'activations_left' => $license['activations_left'] ?? 'unlimited',
            'checksum'         => $license['checksum'] ?? '',
        ]);

        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => $body,
            'headers'  => ['content-type' => 'application/json'],
        ];
    }

    /**
     * 格式化 Freemius 响应
     */
    private function format_freemius_response(array $license): array {
        $body = wp_json_encode([
            'id'              => $license['id'] ?? 0,
            'plugin_id'       => $license['plugin_id'] ?? 0,
            'user_id'         => $license['user_id'] ?? 0,
            'plan_id'         => $license['plan_id'] ?? 0,
            'pricing_id'      => $license['pricing_id'] ?? 0,
            'quota'           => $license['license_limit'] ?? null,
            'activated'       => $license['site_count'] ?? 1,
            'activated_local' => 1,
            'expiration'      => $license['expires'] ?? null,
            'is_free_localhost' => false,
            'is_block_features' => false,
            'is_cancelled'    => false,
        ]);

        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => $body,
            'headers'  => ['content-type' => 'application/json'],
        ];
    }

    /**
     * 格式化 WC API Manager 响应
     */
    private function format_wc_am_response(array $license): array {
        $body = wp_json_encode([
            'success'           => true,
            'status_check'      => 'active',
            'activations'       => (string) ($license['site_count'] ?? 1),
            'activations_limit' => (string) ($license['license_limit'] ?? 'unlimited'),
        ]);

        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => $body,
            'headers'  => ['content-type' => 'application/json'],
        ];
    }

    /**
     * 获取 API Key
     */
    private function get_api_key(): string {
        return $this->settings->get('wenpai_api_key', '');
    }

    /**
     * 提取操作类型
     */
    private function extract_action(string $url, array $args): string {
        // 从 URL 提取
        if (preg_match('/action=(\w+)/', $url, $matches)) {
            return $matches[1];
        }

        // 从 body 提取
        if (isset($args['body']['edd_action'])) {
            return $args['body']['edd_action'];
        }
        if (isset($args['body']['wc-api'])) {
            return $args['body']['request'] ?? 'status';
        }

        return 'check_license';
    }
}
```

### 3.2 BridgeManager (新增)

#### 3.2.1 职责
- 管理桥接插件列表
- 提供桥接启用/禁用 UI
- 与服务端同步可桥接插件列表

```php
<?php
declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Settings;
use WPBridge\Core\RemoteConfig;

class BridgeManager {
    private Settings $settings;
    private RemoteConfig $remote_config;

    /**
     * 获取可桥接的商业插件列表（从服务端）
     */
    public function get_available_plugins(): array {
        return $this->remote_config->get('bridgeable_plugins', []);
    }

    /**
     * 获取已启用桥接的插件
     */
    public function get_bridged_plugins(): array {
        return $this->settings->get('bridged_plugins', []);
    }

    /**
     * 启用插件桥接
     */
    public function enable_bridge(string $plugin_slug): bool {
        // 检查是否在可桥接列表
        $available = $this->get_available_plugins();
        if (!isset($available[$plugin_slug])) {
            return false;
        }

        // 检查订阅限制
        if (!$this->check_subscription_limit()) {
            return false;
        }

        $bridged = $this->get_bridged_plugins();
        if (!in_array($plugin_slug, $bridged, true)) {
            $bridged[] = $plugin_slug;
            $this->settings->set('bridged_plugins', $bridged);
        }

        return true;
    }

    /**
     * 禁用插件桥接
     */
    public function disable_bridge(string $plugin_slug): bool {
        $bridged = $this->get_bridged_plugins();
        $bridged = array_diff($bridged, [$plugin_slug]);
        return $this->settings->set('bridged_plugins', array_values($bridged));
    }

    /**
     * 检查订阅限制
     */
    private function check_subscription_limit(): bool {
        $subscription = $this->get_subscription();
        if ($subscription['plan'] === 'agency') {
            return true; // 无限制
        }

        $current_count = count($this->get_bridged_plugins());
        $limit = $subscription['plugins_limit'] ?? 5;

        return $current_count < $limit;
    }
}
```

---

## 4. 服务端组件设计

### 4.1 License Service (wenpai-bridge 扩展)

#### 4.1.1 API 端点

```
POST /api/v1/license/proxy
  - 授权代理请求

GET /api/v1/license/status
  - 查询授权状态

POST /api/v1/license/activate
  - 激活站点

POST /api/v1/license/deactivate
  - 停用站点
```

#### 4.1.2 Go 实现

```go
// internal/license/service.go
package license

import (
    "context"
    "crypto/sha256"
    "encoding/hex"
    "errors"
    "time"
)

var (
    ErrInvalidAPIKey     = errors.New("invalid api key")
    ErrPluginNotBridged  = errors.New("plugin not in bridge list")
    ErrSubscriptionRequired = errors.New("subscription required")
    ErrSiteLimitExceeded = errors.New("site activation limit exceeded")
)

type Service struct {
    db           *Database
    subscriptions *SubscriptionService
    registry     *PluginRegistry
}

type ProxyRequest struct {
    Vendor      string `json:"vendor"`
    PluginSlug  string `json:"plugin_slug"`
    OriginalURL string `json:"original_url"`
    Action      string `json:"action"`
    SiteURL     string `json:"site_url"`
}

type LicenseResponse struct {
    Success bool        `json:"success"`
    License *LicenseInfo `json:"license,omitempty"`
    Error   string      `json:"error,omitempty"`
}

type LicenseInfo struct {
    Status          string   `json:"status"`
    Expires         string   `json:"expires"`
    LicenseLimit    int      `json:"license_limit"`
    SiteCount       int      `json:"site_count"`
    ActivationsLeft string   `json:"activations_left"`
    Features        []string `json:"features"`
    ItemName        string   `json:"item_name,omitempty"`
    Checksum        string   `json:"checksum,omitempty"`
}

func (s *Service) HandleProxy(ctx context.Context, apiKey string, req *ProxyRequest) (*LicenseResponse, error) {
    // 1. 验证 API Key
    subscription, err := s.subscriptions.GetByAPIKey(ctx, apiKey)
    if err != nil {
        return nil, ErrInvalidAPIKey
    }

    // 2. 检查订阅状态
    if !subscription.IsActive() {
        return nil, ErrSubscriptionRequired
    }

    // 3. 检查插件是否可桥接
    plugin, err := s.registry.GetPlugin(ctx, req.PluginSlug)
    if err != nil || !plugin.BridgeEnabled {
        return nil, ErrPluginNotBridged
    }

    // 4. 检查站点激活限制
    siteHash := s.hashSiteURL(req.SiteURL)
    if !s.checkSiteLimit(ctx, subscription, siteHash) {
        return nil, ErrSiteLimitExceeded
    }

    // 5. 记录/更新站点激活
    s.recordActivation(ctx, subscription.ID, req.SiteURL, siteHash)

    // 6. 构建响应
    license := &LicenseInfo{
        Status:          "valid",
        Expires:         subscription.ExpiresAt.Format("2006-01-02"),
        LicenseLimit:    subscription.SiteLimit,
        SiteCount:       s.getSiteCount(ctx, subscription.ID),
        ActivationsLeft: s.getActivationsLeft(subscription),
        Features:        []string{"updates"},
        ItemName:        plugin.Name,
        Checksum:        s.generateChecksum(subscription, plugin),
    }

    return &LicenseResponse{
        Success: true,
        License: license,
    }, nil
}

func (s *Service) hashSiteURL(url string) string {
    h := sha256.New()
    h.Write([]byte(url))
    return hex.EncodeToString(h.Sum(nil))
}

func (s *Service) checkSiteLimit(ctx context.Context, sub *Subscription, siteHash string) bool {
    // Agency 计划无限制
    if sub.Plan == "agency" {
        return true
    }

    // 检查是否已激活此站点
    exists, _ := s.db.SiteActivationExists(ctx, sub.ID, siteHash)
    if exists {
        return true
    }

    // 检查是否超过限制
    count := s.getSiteCount(ctx, sub.ID)
    return count < sub.SiteLimit
}

func (s *Service) recordActivation(ctx context.Context, subID int64, siteURL, siteHash string) {
    s.db.UpsertSiteActivation(ctx, &SiteActivation{
        SubscriptionID: subID,
        SiteURL:        siteURL,
        SiteHash:       siteHash,
        LastSeen:       time.Now(),
    })
}
```

### 4.2 数据库 Schema

```sql
-- 可桥接插件注册表
CREATE TABLE bridgeable_plugins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    vendor VARCHAR(50) NOT NULL,
    vendor_url VARCHAR(500),
    gpl_compatible BOOLEAN DEFAULT TRUE,
    bridge_enabled BOOLEAN DEFAULT TRUE,
    download_url VARCHAR(500),
    latest_version VARCHAR(50),
    min_wp_version VARCHAR(20),
    tested_wp_version VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vendor (vendor),
    INDEX idx_enabled (bridge_enabled)
);

-- 用户订阅
CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    plan ENUM('free', 'pro', 'agency') DEFAULT 'free',
    site_limit INT DEFAULT 1,
    plugins_limit INT DEFAULT 0,
    status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_api_key (api_key),
    INDEX idx_status (status)
);

-- 站点激活记录
CREATE TABLE site_activations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    site_url VARCHAR(500) NOT NULL,
    site_hash VARCHAR(64) NOT NULL,
    activated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sub_site (subscription_id, site_hash),
    INDEX idx_subscription (subscription_id),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
);

-- 授权请求日志
CREATE TABLE license_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED,
    plugin_slug VARCHAR(255) NOT NULL,
    vendor VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL,
    site_url VARCHAR(500),
    success BOOLEAN DEFAULT TRUE,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscription (subscription_id),
    INDEX idx_plugin (plugin_slug),
    INDEX idx_created (created_at)
);
```

---

## 5. 支持的商业插件

### 5.1 第一批支持 (P0)

| 插件 | Slug | 授权系统 | GPL | 状态 |
|------|------|----------|-----|------|
| Elementor Pro | elementor-pro | EDD | Yes | 待添加 |
| Yoast SEO Premium | wordpress-seo-premium | EDD | Yes | 待添加 |
| ACF Pro | advanced-custom-fields-pro | EDD | Yes | 待添加 |
| Gravity Forms | gravityforms | EDD | Yes | 待添加 |
| WPForms Pro | wpforms | EDD | Yes | 待添加 |

### 5.2 第二批支持 (P1)

| 插件 | Slug | 授权系统 | GPL | 状态 |
|------|------|----------|-----|------|
| Rank Math Pro | seo-by-rank-math-pro | Freemius | Yes | 待添加 |
| WP Rocket | wp-rocket | Custom | Yes | 待添加 |
| Perfmatters | perfmatters | EDD | Yes | 待添加 |
| FlyingPress | flavor | EDD | Yes | 待添加 |

### 5.3 不支持的插件

以下插件因非 GPL 或其他原因不支持：
- Envato 独占插件（非 GPL）
- 包含 SaaS 依赖的插件（如 Jetpack Premium）

---

## 6. 安全考虑

### 6.1 API Key 安全
- API Key 使用 AES-256 加密存储
- 传输使用 HTTPS
- 支持 Key 轮换

### 6.2 请求验证
- 验证请求来源站点
- 防止重放攻击（nonce）
- 限流保护

### 6.3 下载安全
- 所有下载包经过病毒扫描
- 提供 SHA256 校验和
- 支持签名验证

---

## 7. 商业模式

### 7.1 定价

| 计划 | 价格 | 站点数 | 插件数 | 功能 |
|------|------|--------|--------|------|
| Free | ¥0 | 1 | 0 | 检测、开源更新 |
| Pro | ¥199/年 | 3 | 5 | 商业插件桥接 |
| Agency | ¥999/年 | 无限 | 无限 | 全功能 + 优先支持 |

### 7.2 收入预测

假设：
- 第一年 1000 付费用户
- Pro:Agency = 7:3
- 续费率 60%

年收入 = 700 × 199 + 300 × 999 = ¥439,000

---

## 8. 实施计划

### Phase 1: 基础设施 (2周)
- [ ] LicenseProxy 客户端组件
- [ ] License Service 服务端
- [ ] 数据库 Schema
- [ ] 基础 API

### Phase 2: 插件支持 (2周)
- [ ] EDD 授权系统适配
- [ ] 第一批 5 个插件接入
- [ ] 下载 CDN 配置

### Phase 3: 订阅系统 (1周)
- [ ] 订阅管理 UI
- [ ] 支付集成
- [ ] API Key 管理

### Phase 4: 测试发布 (1周)
- [ ] 集成测试
- [ ] Beta 测试
- [ ] 文档完善
- [ ] 正式发布

---

## 9. 风险与缓解

### 9.1 法律风险
- **风险**: 原厂法律诉讼
- **缓解**: 只支持 GPL 插件，明确用户协议

### 9.2 技术风险
- **风险**: 原厂 API 变更
- **缓解**: 模块化设计，快速适配

### 9.3 运营风险
- **风险**: 原厂封禁
- **缓解**: 自建 CDN，多源备份

---

*最后更新: 2026-02-15*
