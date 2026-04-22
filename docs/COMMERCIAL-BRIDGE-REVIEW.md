# 商业插件桥接方案技术评审报告

> 评审日期: 2026-02-15
> 评审对象: COMMERCIAL-BRIDGE-SPEC.md v1.0.0-draft

---

## 评审摘要

| 级别 | 数量 | 说明 |
|------|------|------|
| High | 5 | 必须修复才能上线 |
| Medium | 6 | 建议修复 |
| Low | 4 | 可选优化 |

---

## High 级别问题

### H1: 站点 URL 哈希可被伪造

**位置**: `LicenseProxy::proxy_request()`, `Service::hashSiteURL()`

**问题**: 使用 `home_url()` 作为站点标识，攻击者可以：
1. 在本地修改 `siteurl` 选项伪造任意站点
2. 多个站点使用同一 API Key 绕过站点限制

**建议**:
```php
// 使用多因素站点指纹
$site_fingerprint = hash('sha256', implode('|', [
    home_url(),
    DB_NAME,
    AUTH_KEY,  // wp-config.php 中的密钥
    php_uname('n'),  // 主机名
]));
```

---

### H2: API Key 明文传输到日志

**位置**: `LicenseProxy::proxy_request()`

**问题**:
```php
Logger::debug('License proxy intercepting', [
    'vendor' => $vendor,
    'plugin' => $plugin_slug,
    'url'    => $url,  // 可能包含 license_key 参数
]);
```

原始 URL 可能包含用户的原厂 license_key，会被记录到日志。

**建议**:
```php
// 过滤敏感参数
private function sanitize_url_for_log(string $url): string {
    return preg_replace(
        '/(license_key|license|key|password|secret)=[^&]+/i',
        '$1=[REDACTED]',
        $url
    );
}
```

---

### H3: 缺少请求签名验证

**位置**: 服务端 `HandleProxy()`

**问题**: 仅依赖 API Key 验证，缺少请求完整性校验，可能被中间人篡改。

**建议**:
```go
// 添加 HMAC 签名
func (s *Service) verifyRequestSignature(apiKey string, req *ProxyRequest, signature string) bool {
    mac := hmac.New(sha256.New, []byte(apiKey))
    mac.Write([]byte(req.PluginSlug + req.SiteURL + req.Action))
    expected := hex.EncodeToString(mac.Sum(nil))
    return hmac.Equal([]byte(signature), []byte(expected))
}
```

---

### H4: 响应格式硬编码不完整

**位置**: `format_edd_response()`, `format_freemius_response()`

**问题**:
1. EDD 响应缺少 `payment_id`, `customer_name`, `customer_email` 等字段
2. Freemius 响应缺少 `secret_key`, `public_key` 等字段
3. 某些插件会校验这些字段，导致授权失败

**建议**:
- 需要逆向分析每个插件的实际校验逻辑
- 建立插件响应格式数据库，动态匹配
- 添加响应格式版本控制

---

### H5: 缺少 GPL 合规验证机制

**位置**: 整体架构

**问题**:
1. 没有自动验证插件是否真的是 GPL 授权
2. 依赖人工维护 `gpl_compatible` 字段
3. 可能误桥接非 GPL 插件导致法律风险

**建议**:
```php
// 自动检测 GPL 兼容性
class GPLValidator {
    public function validate(string $plugin_path): bool {
        // 1. 检查 license.txt
        // 2. 检查插件头部 License 字段
        // 3. 检查 readme.txt
        // 4. 查询 WordPress.org API
    }
}
```

---

## Medium 级别问题

### M1: 缺少速率限制实现

**位置**: 服务端 API

**问题**: 文档提到"限流保护"但没有具体实现。

**建议**:
```go
// 使用令牌桶算法
type RateLimiter struct {
    limits map[string]*rate.Limiter  // 按 API Key
}

func (r *RateLimiter) Allow(apiKey string) bool {
    limiter := r.getLimiter(apiKey)
    return limiter.Allow()
}
```

---

### M2: 缺少重试和熔断机制

**位置**: `LicenseProxy::proxy_request()`

**问题**: 代理请求失败时直接返回 false，没有重试逻辑。

**建议**:
```php
private function proxy_request_with_retry(...): array {
    $max_retries = 3;
    $backoff = 1;

    for ($i = 0; $i < $max_retries; $i++) {
        $response = $this->do_proxy_request(...);
        if (!is_wp_error($response)) {
            return $response;
        }
        sleep($backoff);
        $backoff *= 2;
    }

    // 熔断：标记服务不可用
    $this->mark_service_unavailable();
    return false;
}
```

---

### M3: 数据库缺少软删除

**位置**: 数据库 Schema

**问题**: `site_activations` 使用 `ON DELETE CASCADE`，订阅删除时激活记录永久丢失。

**建议**:
```sql
ALTER TABLE site_activations ADD COLUMN deleted_at TIMESTAMP NULL;
ALTER TABLE subscriptions ADD COLUMN deleted_at TIMESTAMP NULL;
-- 使用软删除而非级联删除
```

---

### M4: 缺少审计日志

**位置**: 整体架构

**问题**: `license_requests` 表只记录基本信息，缺少：
- 请求 IP
- User-Agent
- 响应时间
- 完整请求/响应内容（用于调试）

**建议**: 扩展日志表结构，添加详细审计字段。

---

### M5: BridgeManager 缺少构造函数

**位置**: `BridgeManager` 类

**问题**:
```php
class BridgeManager {
    private Settings $settings;
    private RemoteConfig $remote_config;
    // 缺少 __construct() 初始化这些属性
```

---

### M6: 缺少插件版本兼容性检查

**位置**: 更新流程

**问题**: 桥接的插件版本可能与用户 WordPress/PHP 版本不兼容。

**建议**:
```php
public function check_compatibility(string $plugin_slug, string $version): array {
    $plugin = $this->registry->get($plugin_slug);
    $issues = [];

    if (version_compare(get_bloginfo('version'), $plugin->min_wp_version, '<')) {
        $issues[] = 'WordPress 版本过低';
    }
    if (version_compare(PHP_VERSION, $plugin->min_php_version, '<')) {
        $issues[] = 'PHP 版本过低';
    }

    return $issues;
}
```

---

## Low 级别问题

### L1: 硬编码代理 URL

**位置**: `LicenseProxy::proxy_request()`

**问题**:
```php
$proxy_url = 'https://updates.wenpai.net/api/v1/license/proxy';
```

**建议**: 使用配置项，支持自定义端点。

---

### L2: 缺少健康检查端点

**位置**: 服务端 API

**建议**: 添加 `GET /api/v1/health` 端点供客户端检测服务状态。

---

### L3: 响应缺少缓存控制

**位置**: 服务端响应

**建议**: 添加适当的 Cache-Control 头，减少重复请求。

---

### L4: 缺少国际化支持

**位置**: 错误消息

**问题**: Go 服务端错误消息是英文硬编码。

**建议**: 使用错误码，客户端根据错误码显示本地化消息。

---

## 商业风险评估

### 风险 1: 原厂法律行动 (高)

**分析**:
- Elementor、Yoast 等公司有法务团队
- 可能发送 DMCA 或律师函
- GPL 不保护商标，使用插件名称可能侵权

**缓解**:
1. 用户协议明确免责
2. 不使用原厂商标/Logo
3. 准备法律意见书

### 风险 2: 原厂技术对抗 (中)

**分析**:
- 原厂可能更新授权 API 格式
- 添加更复杂的校验机制
- 检测并封禁桥接请求

**缓解**:
1. 建立 API 变更监控
2. 快速响应机制
3. 多版本适配

### 风险 3: 用户信任问题 (中)

**分析**:
- 用户可能担心安全性
- 担心插件包被篡改
- 担心服务稳定性

**缓解**:
1. 透明的安全审计
2. 提供校验和验证
3. SLA 承诺

---

## 建议优先级

1. **立即修复**: H1, H2, H3 (安全相关)
2. **上线前修复**: H4, H5, M1, M2
3. **迭代优化**: M3-M6, L1-L4

---

## 结论

方案整体架构合理，但存在多个安全和实现细节问题需要解决。建议：

1. 先完成 High 级别问题修复
2. 从 1-2 个简单插件（如 ACF Pro）开始试点
3. 收集反馈后再扩展支持范围

---

*评审人: Claude Code*
*评审日期: 2026-02-15*
