# WPBridge 增值方案规划

> 创建日期: 2026-02-15
> 状态: 规划中

---

## 概述

本文档规划 WPBridge 的三个核心增值方向：
1. 商业插件桥接（付费核心）
2. 企业级更新管控
3. 多站点同步管理

---

## 方案一：商业插件桥接（付费核心）

### 1.1 定位

为购买了商业插件但授权过期/无法续费的用户提供替代更新源。

### 1.2 架构

```
用户站点 (wpbridge)                    文派云桥服务端
┌─────────────────┐                   ┌─────────────────────┐
│ CommercialDetector │ ──检测──→      │ plugin-registry     │
│ (已有)            │                 │ (已有 wenpai-bridge)│
├─────────────────┤                   ├─────────────────────┤
│ LicenseProxy    │ ──验证──→        │ /license/verify     │
│ (新增)          │                   │ (新增)              │
├─────────────────┤                   ├─────────────────────┤
│ UpdateBridge    │ ──更新──→        │ /plugins/{slug}/info│
│ (已有 JsonHandler)│                 │ (已有)              │
└─────────────────┘                   └─────────────────────┘
```

### 1.3 核心功能

| 功能 | 说明 | 实现难度 | 状态 |
|------|------|----------|------|
| 商业插件检测 | CommercialDetector，支持 15+ 插件 | - | ✅ 已完成 |
| 授权代理 | 拦截原厂授权请求，转发到文派验证 | 中 | 待开发 |
| 更新桥接 | JsonHandler + wenpai-bridge | - | ✅ 已完成 |
| 下载代理 | 从文派 CDN 下载，避免原厂限制 | 低 | 待开发 |

### 1.4 新增组件：LicenseProxy

```php
<?php
namespace WPBridge\Commercial;

class LicenseProxy {
    /**
     * 支持的授权系统
     */
    private array $supported_vendors = [
        'edd'      => [
            'name'     => 'EDD Software Licensing',
            'patterns' => [
                'api.example.com/edd-sl',
                'example.com/edd-api',
            ],
            'actions'  => ['activate_license', 'deactivate_license', 'check_license'],
        ],
        'freemius' => [
            'name'     => 'Freemius',
            'patterns' => [
                'api.freemius.com',
            ],
            'actions'  => ['activate', 'deactivate', 'ping'],
        ],
        'envato'   => [
            'name'     => 'Envato Market',
            'patterns' => [
                'api.envato.com',
                'envato.developer.com',
            ],
            'actions'  => ['verify-purchase'],
        ],
        'wc_am'    => [
            'name'     => 'WooCommerce API Manager',
            'patterns' => [
                'wc-api/wc-am-api',
                'wc-api/am-software-api',
            ],
            'actions'  => ['activation', 'deactivation', 'status'],
        ],
    ];

    /**
     * 初始化钩子
     */
    public function init(): void {
        add_filter('pre_http_request', [$this, 'intercept_license_check'], 10, 3);
    }

    /**
     * 拦截授权验证请求
     */
    public function intercept_license_check($preempt, array $args, string $url) {
        // 1. 检测是否是已知商业插件的授权 API
        $vendor = $this->detect_vendor($url);
        if (!$vendor) {
            return $preempt;
        }

        // 2. 检查该插件是否在桥接列表中
        $plugin_slug = $this->extract_plugin_slug($url, $args);
        if (!$this->is_bridged_plugin($plugin_slug)) {
            return $preempt;
        }

        // 3. 转发到文派授权代理
        return $this->proxy_to_wenpai($vendor, $plugin_slug, $args);
    }

    /**
     * 检测授权系统供应商
     */
    private function detect_vendor(string $url): ?string {
        foreach ($this->supported_vendors as $vendor_key => $vendor_config) {
            foreach ($vendor_config['patterns'] as $pattern) {
                if (strpos($url, $pattern) !== false) {
                    return $vendor_key;
                }
            }
        }
        return null;
    }

    /**
     * 转发到文派授权代理
     */
    private function proxy_to_wenpai(string $vendor, string $plugin_slug, array $args): array {
        $wenpai_url = 'https://updates.wenpai.net/api/v1/license/proxy';

        $response = wp_remote_post($wenpai_url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-WPBridge-Key'   => $this->get_api_key(),
                'X-WPBridge-Site'  => home_url(),
            ],
            'body' => wp_json_encode([
                'vendor'      => $vendor,
                'plugin_slug' => $plugin_slug,
                'action'      => $this->extract_action($args),
                'site_url'    => home_url(),
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // 转换响应格式以匹配原厂 API
        return $this->transform_response($vendor, $response);
    }

    /**
     * 检查插件是否在桥接列表中
     */
    private function is_bridged_plugin(string $plugin_slug): bool {
        $bridged_plugins = get_option('wpbridge_bridged_plugins', []);
        return in_array($plugin_slug, $bridged_plugins, true);
    }
}
```

### 1.5 服务端扩展 (wenpai-bridge)

```go
// internal/license/proxy.go

package license

import (
    "encoding/json"
    "net/http"
)

type ProxyRequest struct {
    Vendor     string `json:"vendor"`
    PluginSlug string `json:"plugin_slug"`
    Action     string `json:"action"`
    SiteURL    string `json:"site_url"`
}

type ProxyResponse struct {
    Success bool        `json:"success"`
    License LicenseInfo `json:"license,omitempty"`
    Error   string      `json:"error,omitempty"`
}

type LicenseInfo struct {
    Status       string `json:"status"`        // valid, expired, disabled
    Expires      string `json:"expires"`       // 2027-01-01
    LicenseLimit int    `json:"license_limit"` // 站点数限制
    SiteCount    int    `json:"site_count"`    // 已激活站点数
    Features     []string `json:"features"`    // updates, support, addons
}

// HandleLicenseProxy 处理授权代理请求
func (h *Handler) HandleLicenseProxy(w http.ResponseWriter, r *http.Request) {
    var req ProxyRequest
    if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
        respondError(w, "invalid request", http.StatusBadRequest)
        return
    }

    // 1. 验证 API Key
    apiKey := r.Header.Get("X-WPBridge-Key")
    if !h.validateAPIKey(apiKey) {
        respondError(w, "invalid api key", http.StatusUnauthorized)
        return
    }

    // 2. 检查插件是否在桥接列表
    plugin, err := h.registry.GetPlugin(req.PluginSlug)
    if err != nil || !plugin.BridgeEnabled {
        respondError(w, "plugin not bridged", http.StatusNotFound)
        return
    }

    // 3. 检查用户订阅状态
    subscription, err := h.subscriptions.GetByAPIKey(apiKey)
    if err != nil || !subscription.IsActive() {
        respondError(w, "subscription required", http.StatusPaymentRequired)
        return
    }

    // 4. 检查站点激活限制
    if !h.checkSiteLimit(subscription, req.SiteURL) {
        respondError(w, "site limit exceeded", http.StatusForbidden)
        return
    }

    // 5. 返回授权信息
    license := LicenseInfo{
        Status:       "valid",
        Expires:      subscription.ExpiresAt.Format("2006-01-02"),
        LicenseLimit: subscription.SiteLimit,
        SiteCount:    h.getSiteCount(subscription.ID),
        Features:     []string{"updates"},
    }

    respondJSON(w, ProxyResponse{
        Success: true,
        License: license,
    })
}
```

### 1.6 数据库设计

```sql
-- 桥接插件表
CREATE TABLE wpbridge_bridged_plugins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    vendor VARCHAR(50) NOT NULL,           -- edd, freemius, envato, wc_am
    original_api_url VARCHAR(500),
    bridge_enabled BOOLEAN DEFAULT TRUE,
    download_source VARCHAR(500),          -- 文派 CDN 地址
    last_version VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vendor (vendor),
    INDEX idx_enabled (bridge_enabled)
);

-- 用户订阅表
CREATE TABLE wpbridge_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    plan ENUM('free', 'pro', 'agency') DEFAULT 'free',
    site_limit INT DEFAULT 1,
    plugins_limit INT DEFAULT 0,           -- 0 = unlimited for agency
    status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
);

-- 站点激活记录表
CREATE TABLE wpbridge_site_activations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    site_url VARCHAR(500) NOT NULL,
    site_hash VARCHAR(64) NOT NULL,        -- SHA256(site_url)
    activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME,
    UNIQUE KEY uk_sub_site (subscription_id, site_hash),
    INDEX idx_subscription (subscription_id)
);
```

### 1.7 商业模式

| 层级 | 价格 | 功能 |
|------|------|------|
| Free | 免费 | 开源插件更新、商业插件检测 |
| Pro | ¥199/年 | 商业插件更新桥接（5个插件，3个站点） |
| Agency | ¥999/年 | 无限插件 + 无限站点 + 授权代理 + 优先支持 |

### 1.8 支持的商业插件（初期）

| 插件 | 授权系统 | 优先级 |
|------|----------|--------|
| Elementor Pro | EDD | 高 |
| Yoast SEO Premium | EDD | 高 |
| ACF Pro | EDD | 高 |
| Gravity Forms | EDD | 高 |
| WP Rocket | Custom | 中 |
| Rank Math Pro | Freemius | 中 |
| WPForms Pro | EDD | 中 |

### 1.9 风险与合规

#### GPL 合规
- 只桥接 GPL 授权的插件更新
- 不破解非 GPL 插件（如 Envato 独占插件）
- 明确声明"替代更新源"而非"破解授权"

#### 法律风险
- 可能被原厂封禁 API
- 需要备用下载源（文派 CDN）
- 用户协议明确免责条款

#### 技术风险
- 原厂 API 变更需要及时适配
- 需要持续维护插件兼容性
- 下载包需要安全扫描

---

## 方案二：企业级更新管控

### 2.1 定位

为企业/代理商提供 WordPress 更新的集中管控能力。

### 2.2 核心功能

| 功能 | 说明 | 优先级 | 状态 |
|------|------|--------|------|
| 版本锁定 | 锁定到指定版本，阻止自动更新 | 高 | 已有基础 |
| 更新审批 | 更新前需管理员审批 | 高 | 待开发 |
| 回滚机制 | 更新失败自动回滚 | 高 | 待开发 |
| 更新日志 | 聚合显示所有插件 changelog | 中 | 待开发 |
| 安全扫描 | 更新前检查 VirusTotal | 中 | 待开发 |

### 2.3 新增组件：UpdateApproval

```php
<?php
namespace WPBridge\Enterprise;

class UpdateApproval {
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_AUTO     = 'auto_approved';

    /**
     * 审批规则
     */
    private array $rules = [];

    /**
     * 初始化
     */
    public function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filter_updates'], 100);
        add_filter('pre_set_site_transient_update_themes', [$this, 'filter_theme_updates'], 100);
        add_action('admin_menu', [$this, 'add_approval_menu']);
        add_action('wp_ajax_wpbridge_approve_update', [$this, 'ajax_approve']);
        add_action('wp_ajax_wpbridge_reject_update', [$this, 'ajax_reject']);
    }

    /**
     * 过滤更新，创建审批请求
     */
    public function filter_updates($transient) {
        if (empty($transient->response)) {
            return $transient;
        }

        foreach ($transient->response as $file => $update) {
            $slug = dirname($file);

            // 检查是否需要审批
            if ($this->requires_approval($slug, $update)) {
                // 检查是否已审批
                if (!$this->is_approved($slug, $update->new_version)) {
                    // 创建审批请求
                    $this->create_approval_request($file, $update);
                    // 从更新列表移除
                    unset($transient->response[$file]);
                    // 添加到待审批列表
                    $transient->no_update[$file] = $update;
                }
            }
        }

        return $transient;
    }

    /**
     * 检查是否需要审批
     */
    private function requires_approval(string $slug, object $update): bool {
        // 规则 1: 主版本更新需要审批
        if ($this->is_major_update($update)) {
            return true;
        }

        // 规则 2: 指定插件需要审批
        $require_approval_list = get_option('wpbridge_require_approval', []);
        if (in_array($slug, $require_approval_list, true)) {
            return true;
        }

        // 规则 3: 全局审批模式
        $global_mode = get_option('wpbridge_approval_mode', 'none');
        if ($global_mode === 'all') {
            return true;
        }

        return false;
    }

    /**
     * 创建审批请求
     */
    private function create_approval_request(string $file, object $update): int {
        global $wpdb;

        $table = $wpdb->prefix . 'wpbridge_approvals';

        // 检查是否已存在
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE plugin_file = %s AND new_version = %s AND status = %s",
            $file,
            $update->new_version,
            self::STATUS_PENDING
        ));

        if ($existing) {
            return (int) $existing;
        }

        // 获取 changelog
        $changelog = $this->fetch_changelog($update);

        $wpdb->insert($table, [
            'plugin_file'     => $file,
            'plugin_name'     => $this->get_plugin_name($file),
            'current_version' => $this->get_current_version($file),
            'new_version'     => $update->new_version,
            'changelog'       => $changelog,
            'status'          => self::STATUS_PENDING,
            'created_at'      => current_time('mysql'),
        ]);

        // 发送通知
        $this->notify_admins($file, $update);

        return $wpdb->insert_id;
    }

    /**
     * 审批更新
     */
    public function approve(int $approval_id, int $user_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpbridge_approvals';

        return (bool) $wpdb->update(
            $table,
            [
                'status'      => self::STATUS_APPROVED,
                'approved_by' => $user_id,
                'approved_at' => current_time('mysql'),
            ],
            ['id' => $approval_id]
        );
    }

    /**
     * 拒绝更新
     */
    public function reject(int $approval_id, int $user_id, string $reason = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpbridge_approvals';

        return (bool) $wpdb->update(
            $table,
            [
                'status'        => self::STATUS_REJECTED,
                'approved_by'   => $user_id,
                'approved_at'   => current_time('mysql'),
                'reject_reason' => $reason,
            ],
            ['id' => $approval_id]
        );
    }
}
```

### 2.4 新增组件：BackupManager（扩展）

```php
<?php
namespace WPBridge\Enterprise;

class BackupManager {
    const BACKUP_DIR = 'wpbridge-backups';
    const MAX_BACKUPS = 3;

    /**
     * 更新前自动备份
     */
    public function pre_update_backup(string $plugin_file): ?string {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

        if (!is_dir($plugin_dir)) {
            return null;
        }

        $backup_path = $this->create_backup($plugin_file);

        if ($backup_path) {
            $this->store_backup_meta($plugin_file, $backup_path);
            $this->cleanup_old_backups($plugin_file);
        }

        return $backup_path;
    }

    /**
     * 创建备份
     */
    private function create_backup(string $plugin_file): ?string {
        $plugin_slug = dirname($plugin_file);
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
        $version = $this->get_plugin_version($plugin_file);

        $backup_dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR;
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
            // 添加 .htaccess 保护
            file_put_contents($backup_dir . '/.htaccess', 'deny from all');
        }

        $backup_filename = sprintf(
            '%s-%s-%s.zip',
            $plugin_slug,
            $version,
            date('Ymd-His')
        );
        $backup_path = $backup_dir . '/' . $backup_filename;

        // 创建 ZIP
        $zip = new \ZipArchive();
        if ($zip->open($backup_path, \ZipArchive::CREATE) !== true) {
            return null;
        }

        $this->add_dir_to_zip($zip, $plugin_dir, $plugin_slug);
        $zip->close();

        return $backup_path;
    }

    /**
     * 一键回滚
     */
    public function rollback(string $plugin_file, ?string $version = null): bool {
        $backup = $this->get_backup($plugin_file, $version);

        if (!$backup || !file_exists($backup['path'])) {
            return false;
        }

        // 停用插件
        deactivate_plugins($plugin_file);

        // 删除当前版本
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        $this->delete_directory($plugin_dir);

        // 解压备份
        $zip = new \ZipArchive();
        if ($zip->open($backup['path']) !== true) {
            return false;
        }
        $zip->extractTo(WP_PLUGIN_DIR);
        $zip->close();

        // 重新激活插件
        activate_plugin($plugin_file);

        // 记录回滚
        $this->log_rollback($plugin_file, $backup['version']);

        return true;
    }

    /**
     * 获取备份列表
     */
    public function get_backups(string $plugin_file): array {
        $backups = get_option('wpbridge_backups', []);
        $plugin_slug = dirname($plugin_file);

        return $backups[$plugin_slug] ?? [];
    }

    /**
     * 清理旧备份
     */
    private function cleanup_old_backups(string $plugin_file): void {
        $backups = $this->get_backups($plugin_file);

        if (count($backups) <= self::MAX_BACKUPS) {
            return;
        }

        // 按时间排序，删除最旧的
        usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

        $to_delete = array_slice($backups, self::MAX_BACKUPS);
        foreach ($to_delete as $backup) {
            if (file_exists($backup['path'])) {
                unlink($backup['path']);
            }
        }

        // 更新记录
        $plugin_slug = dirname($plugin_file);
        $all_backups = get_option('wpbridge_backups', []);
        $all_backups[$plugin_slug] = array_slice($backups, 0, self::MAX_BACKUPS);
        update_option('wpbridge_backups', $all_backups);
    }
}
```

### 2.5 数据库表

```sql
-- 审批请求表
CREATE TABLE wp_wpbridge_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plugin_file VARCHAR(255) NOT NULL,
    plugin_name VARCHAR(255),
    current_version VARCHAR(50),
    new_version VARCHAR(50) NOT NULL,
    changelog TEXT,
    status ENUM('pending', 'approved', 'rejected', 'auto_approved') DEFAULT 'pending',
    approved_by BIGINT UNSIGNED,
    approved_at DATETIME,
    reject_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_plugin (plugin_file),
    INDEX idx_created (created_at)
);
```

### 2.6 商业模式

| 层级 | 价格 | 功能 |
|------|------|------|
| Free | 免费 | 版本锁定（3个插件） |
| Pro | ¥299/年 | 无限锁定 + 回滚 + 更新日志 |
| Enterprise | ¥1999/年 | 审批流程 + 安全扫描 + API + 多用户 |

---

## 方案三：多站点同步管理

### 3.1 定位

为管理多个 WordPress 站点的代理商/企业提供统一配置管理。

### 3.2 架构

```
┌─────────────────────────────────────────────────────────┐
│                    WPBridge Hub (中心)                   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │
│  │ 配置管理    │  │ 站点监控    │  │ 批量操作    │     │
│  └─────────────┘  └─────────────┘  └─────────────┘     │
└─────────────────────────────────────────────────────────┘
         │                │                │
         ▼                ▼                ▼
┌─────────────┐  ┌─────────────┐  ┌─────────────┐
│  站点 A     │  │  站点 B     │  │  站点 C     │
│  wpbridge   │  │  wpbridge   │  │  wpbridge   │
│  (Agent)    │  │  (Agent)    │  │  (Agent)    │
└─────────────┘  └─────────────┘  └─────────────┘
```

### 3.3 核心功能

| 功能 | 说明 | 优先级 |
|------|------|--------|
| 配置同步 | 中心配置自动同步到所有站点 | 高 |
| 站点监控 | 实时监控所有站点更新状态 | 高 |
| 批量更新 | 一键更新所有站点的指定插件 | 中 |
| 分组管理 | 按客户/项目分组管理站点 | 中 |
| 报告生成 | 生成更新状态报告 | 低 |

### 3.4 实现方式

推荐 SaaS 中心化方案，在 wenpai.net 上提供 Hub 服务。

### 3.5 商业模式

| 层级 | 价格 | 功能 |
|------|------|------|
| Free | 免费 | 单站点使用 |
| Pro | ¥499/年 | 最多 10 个站点同步 |
| Agency | ¥1999/年 | 最多 100 个站点 + 白标 |
| Enterprise | ¥9999/年 | 无限站点 + 自托管 Hub + API |

---

## 方案对比

| 维度 | 商业插件桥接 | 企业级管控 | 多站点同步 |
|------|-------------|-----------|-----------|
| 开发难度 | 中 | 中 | 高 |
| 市场需求 | 高（刚需） | 中 | 中 |
| 付费意愿 | 高 | 中 | 高（代理商） |
| 法律风险 | 中 | 低 | 低 |
| 竞品情况 | 少 | 多 | 少 |
| 与现有代码复用 | 高 | 高 | 中 |

---

## 建议优先级

1. **先完成 v0.9.0 路线图**（版本锁定 + 回滚）- 企业级管控的基础
2. **商业插件桥接** - 差异化竞争点，与 wenpai-bridge 协同
3. **多站点同步** - 作为 Pro/Agency 版本的高级功能

---

*最后更新: 2026-02-15*
