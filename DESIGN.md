# WPBridge 技术设计文档

> 详细技术方案和架构设计

*创建日期: 2026-02-04*

---

## 0. 设计原则

### 0.1 核心原则

| 原则 | 说明 |
|------|------|
| **安全优先** | 所有外部输入必须校验，敏感数据加密存储 |
| **优雅降级** | 任何外部服务失败不应阻塞 WordPress 正常运行 |
| **模块解耦** | 更新源桥接、AI 桥接可独立启用/禁用 |
| **显式配置** | 不做隐式行为，所有桥接规则用户可见可控 |

### 0.2 安全边界

```
安全检查清单
│
├── 输入校验
│   ├── URL 白名单/格式校验
│   ├── 版本号格式校验
│   └── JSON 响应结构校验
│
├── 存储安全
│   ├── API Key/Token 加密存储（wp_options 加密）
│   ├── 敏感日志脱敏
│   └── 配置导出时隐藏密钥
│
├── 下载安全
│   ├── 下载包哈希校验（如提供）
│   ├── ZIP 文件结构检查
│   └── 文件大小限制
│
└── 请求安全
    ├── AI 拦截显式白名单
    ├── 请求超时限制
    └── 失败重试限制
```

### 0.3 缓存与降级策略

```php
// 缓存策略
class CacheStrategy {
    // 更新检查结果缓存
    const UPDATE_CHECK_TTL = 12 * HOUR_IN_SECONDS;

    // 源健康状态缓存
    const SOURCE_HEALTH_TTL = 1 * HOUR_IN_SECONDS;

    // 失败源冷却时间
    const FAILED_SOURCE_COOLDOWN = 30 * MINUTE_IN_SECONDS;

    // 请求合并窗口（防止重复请求）
    const REQUEST_MERGE_WINDOW = 5;  // seconds

    // 过期缓存可用时间（源不可用时返回旧数据）
    const STALE_CACHE_TTL = 7 * DAY_IN_SECONDS;
}

// 降级策略
class FallbackStrategy {
    // 源不可用时的行为
    const ON_SOURCE_FAIL = 'skip';  // skip | warn | block

    // 最大重试次数
    const MAX_RETRIES = 2;

    // 超时时间
    const REQUEST_TIMEOUT = 10;  // seconds

    // 中国网络优化：更短超时
    const REQUEST_TIMEOUT_CN = 5;  // seconds
}
```

### 0.4 性能优化策略

```php
/**
 * 性能优化核心策略
 *
 * 目标：减少 WordPress 后台加载时间
 * 方法：批量请求、智能缓存、请求合并
 */

// 1. 并行请求（使用 WordPress Requests API）
class ParallelRequestManager {
    /**
     * 批量检查多个更新源
     * 使用 Requests::request_multiple 并行请求
     */
    public function checkMultipleSources(array $sources): array {
        $requests = [];
        foreach ($sources as $source) {
            $requests[$source->id] = [
                'url'     => $source->getCheckUrl(),
                'type'    => \WpOrg\Requests\Requests::GET,
                'headers' => $source->getHeaders(),
            ];
        }

        // 并行请求，显著减少等待时间
        $responses = \WpOrg\Requests\Requests::request_multiple(
            $requests,
            ['timeout' => CacheStrategy::REQUEST_TIMEOUT]
        );

        return $this->processResponses($responses);
    }
}

// 2. 请求去重与合并窗口
class RequestDeduplicator {
    /**
     * 防止短时间内重复请求同一源
     * 使用 transient 锁机制
     */
    public function acquireLock(string $sourceId): bool {
        $lockKey = 'wpbridge_lock_' . $sourceId;

        if (get_transient($lockKey)) {
            return false;  // 已有请求在进行中
        }

        set_transient($lockKey, time(), CacheStrategy::REQUEST_MERGE_WINDOW);
        return true;
    }
}

// 3. 条件请求（ETag/Last-Modified）
class ConditionalRequest {
    /**
     * 使用 HTTP 条件请求减少数据传输
     */
    public function buildHeaders(string $sourceId): array {
        $cache = get_transient('wpbridge_cache_' . $sourceId);

        $headers = [];
        if ($cache && !empty($cache['etag'])) {
            $headers['If-None-Match'] = $cache['etag'];
        }
        if ($cache && !empty($cache['last_modified'])) {
            $headers['If-Modified-Since'] = $cache['last_modified'];
        }

        return $headers;
    }
}

// 4. 分组检查（同一厂商多插件）
class SourceGroup {
    /**
     * 允许同一更新源返回多个插件的版本信息
     * 减少 HTTP 请求数量
     *
     * JSON 格式示例：
     * {
     *   "plugins": {
     *     "plugin-a": {"version": "1.0.0", "download_url": "..."},
     *     "plugin-b": {"version": "2.0.0", "download_url": "..."}
     *   }
     * }
     */
    public string $groupId;
    public array $slugs = [];
    public string $apiUrl;
}

// 5. 缓存分层
class CacheManager {
    /**
     * 优先使用对象缓存（Redis/Memcached）
     * 降级到数据库 transient
     */
    public function get(string $key) {
        // 尝试对象缓存
        if (wp_using_ext_object_cache()) {
            $value = wp_cache_get($key, 'wpbridge');
            if ($value !== false) {
                return $value;
            }
        }

        // 降级到 transient
        return get_transient('wpbridge_' . $key);
    }

    public function set(string $key, $value, int $ttl): bool {
        if (wp_using_ext_object_cache()) {
            wp_cache_set($key, $value, 'wpbridge', $ttl);
        }

        return set_transient('wpbridge_' . $key, $value, $ttl);
    }
}

// 6. 后台任务（WP-Cron）
class BackgroundUpdater {
    /**
     * 使用 WP-Cron 在后台预先更新缓存
     * 前台页面只读取缓存，不发起请求
     */
    public function scheduleUpdate(): void {
        if (!wp_next_scheduled('wpbridge_update_sources')) {
            wp_schedule_event(time(), 'twicedaily', 'wpbridge_update_sources');
        }
    }

    public function runUpdate(): void {
        $sources = $this->getEnabledSources();
        $this->parallelManager->checkMultipleSources($sources);
    }
}
```

---

## 1. 更新源桥接技术方案

### 1.0 预置更新源

```php
/**
 * 预置更新源配置
 * 用户安装后可一键启用
 */
class PresetSources {
    // 文派开源更新源（默认预置）
    const WENPAI_OPEN = [
        'id'       => 'wenpai-open',
        'name'     => '文派开源更新源',
        'type'     => 'arkpress',
        'api_url'  => 'https://api.wenpai.net/v1',
        'enabled'  => true,  // 默认启用
        'priority' => 10,
    ];

    // ArkPress（文派自托管方案）
    const ARKPRESS = [
        'id'       => 'arkpress',
        'name'     => 'ArkPress',
        'type'     => 'arkpress',
        'api_url'  => '',  // 用户自定义
        'enabled'  => false,
        'priority' => 20,
    ];

    // AspireCloud
    const ASPIRECLOUD = [
        'id'       => 'aspirecloud',
        'name'     => 'AspireCloud',
        'type'     => 'aspirecloud',
        'api_url'  => 'https://api.aspirepress.org',
        'enabled'  => false,
        'priority' => 30,
    ];

    // FAIR Package Manager
    const FAIR = [
        'id'       => 'fair',
        'name'     => 'FAIR Package Manager',
        'type'     => 'fair',
        'api_url'  => 'https://api.fairpm.org',
        'enabled'  => false,
        'priority' => 40,
    ];
}
```

### 1.0.1 自托管方案支持

```php
/**
 * 支持的自托管更新服务器方案
 */
class SelfHostedHandlers {
    // ArkPress（文派开源，AspireCloud 中国分叉）
    // - 针对中国网络环境优化
    // - 与文派生态深度集成
    const ARKPRESS = [
        'handler'  => ArkPressHandler::class,
        'api_type' => 'aspirecloud_compatible',
        'features' => ['federation', 'cdn', 'mirror'],
    ];

    // AspireCloud
    // - FAIR 基础设施
    // - 联邦模式
    const ASPIRECLOUD = [
        'handler'  => AspireCloudHandler::class,
        'api_type' => 'aspirecloud',
        'features' => ['federation', 'cdn'],
    ];

    // UpdatePulse Server
    // - 授权管理
    // - VCS 集成
    const UPDATEPULSE = [
        'handler'  => UpdatePulseHandler::class,
        'api_type' => 'puc_compatible',
        'features' => ['license', 'vcs', 'cloud_storage'],
    ];

    // 通用 Plugin Update Checker 格式
    const PUC_GENERIC = [
        'handler'  => PUCHandler::class,
        'api_type' => 'puc',
        'features' => ['json_api'],
    ];
}
```

### 1.1 WordPress 更新机制

WordPress 使用以下钩子处理插件/主题更新：

```php
// 插件更新检查
add_filter('pre_set_site_transient_update_plugins', [$this, 'checkPluginUpdates']);
add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);

// 主题更新检查
add_filter('pre_set_site_transient_update_themes', [$this, 'checkThemeUpdates']);
add_filter('themes_api', [$this, 'themeInfo'], 10, 3);

// 下载包过滤
add_filter('upgrader_pre_download', [$this, 'filterDownload'], 10, 3);
```

### 1.2 支持的更新源类型

#### 源类型枚举（统一定义）

```php
/**
 * 更新源类型枚举
 * 所有源类型的统一定义，确保数据模型与处理器一致
 */
class SourceType {
    // === 基础类型（用户自定义源）===
    const JSON = 'json';           // 标准 JSON API（Plugin Update Checker 格式）
    const GITHUB = 'github';       // GitHub Releases
    const GITLAB = 'gitlab';       // GitLab Releases
    const GITEE = 'gitee';         // Gitee Releases（国内）
    const WENPAI_GIT = 'wenpai_git'; // 菲码源库
    const ZIP = 'zip';             // 直接 ZIP URL

    // === 自托管服务器类型（预置源使用）===
    const ARKPRESS = 'arkpress';       // ArkPress（文派自托管，AspireCloud 分叉）
    const ASPIRECLOUD = 'aspirecloud'; // AspireCloud
    const FAIR = 'fair';               // FAIR Package Manager
    const PUC = 'puc';                 // Plugin Update Checker 服务器

    // === 类型分组 ===
    const GIT_TYPES = [self::GITHUB, self::GITLAB, self::GITEE, self::WENPAI_GIT];
    const SERVER_TYPES = [self::ARKPRESS, self::ASPIRECLOUD, self::FAIR, self::PUC];
    const ALL_TYPES = [
        self::JSON, self::GITHUB, self::GITLAB, self::GITEE,
        self::WENPAI_GIT, self::ZIP, self::ARKPRESS,
        self::ASPIRECLOUD, self::FAIR, self::PUC,
    ];
}
```

#### 类型与处理器映射

| 类型 | 处理器 | 说明 |
|------|--------|------|
| `json` | `JsonHandler` | 标准 JSON API（Plugin Update Checker 格式）|
| `github` | `GitHubHandler` | GitHub Releases |
| `gitlab` | `GitLabHandler` | GitLab Releases |
| `gitee` | `GiteeHandler` | Gitee Releases（国内）|
| `wenpai_git` | `WenPaiGitHandler` | 菲码源库 |
| `zip` | `ZipHandler` | 直接 ZIP URL |
| `arkpress` | `ArkPressHandler` | ArkPress 自托管服务器 |
| `aspirecloud` | `AspireCloudHandler` | AspireCloud 服务器 |
| `fair` | `FairHandler` | FAIR Package Manager |
| `puc` | `PUCHandler` | Plugin Update Checker 服务器 |

### 1.3 JSON API 格式标准

采用与 [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) 兼容的格式：

**插件更新 JSON：**
```json
{
    "name": "插件名称",
    "slug": "plugin-slug",
    "version": "2.0.0",
    "download_url": "https://example.com/plugin-2.0.0.zip",
    "requires": "5.9",
    "tested": "6.4",
    "requires_php": "7.4",
    "last_updated": "2026-02-04",
    "sections": {
        "description": "插件描述",
        "changelog": "更新日志"
    }
}
```

**主题更新 JSON：**
```json
{
    "version": "2.0.0",
    "download_url": "https://example.com/theme-2.0.0.zip",
    "details_url": "https://example.com/theme-details"
}
```

### 1.4 Git 仓库支持

**GitHub/GitLab/菲码源库 统一处理：**

```php
// 统一接口 + 能力矩阵
interface SourceHandlerInterface {
    // 能力声明
    public function getCapabilities(): array;

    // 获取最新版本信息
    public function getLatestVersion(string $identifier): ?VersionInfo;

    // 获取下载 URL
    public function getDownloadUrl(string $identifier, string $version): ?string;

    // 验证认证信息
    public function validateAuth(?string $token): bool;
}

// 能力矩阵
class SourceCapabilities {
    const AUTH_NONE = 'none';
    const AUTH_TOKEN = 'token';
    const AUTH_BASIC = 'basic';
    const AUTH_OAUTH = 'oauth';

    const VERSION_RELEASE = 'release';   // GitHub/GitLab Releases
    const VERSION_TAG = 'tag';           // Git Tags
    const VERSION_JSON = 'json';         // JSON API 返回

    const DOWNLOAD_RELEASE = 'release';  // Release 附件
    const DOWNLOAD_ARCHIVE = 'archive';  // 仓库归档
    const DOWNLOAD_DIRECT = 'direct';    // 直接 URL
}

class GitSourceHandler implements SourceHandlerInterface {
    // 支持的 Git 平台
    private const PLATFORMS = [
        'github.com'      => GitHubHandler::class,
        'gitlab.com'      => GitLabHandler::class,
        'git.wenpai.net'  => WenPaiGitHandler::class,  // 菲码源库
    ];

    // 从 Release 获取更新信息
    public function getLatestRelease(string $repo, ?string $token = null): array;

    // 从 Tag 获取更新信息
    public function getLatestTag(string $repo, ?string $token = null): array;
}
```

---

## 2. 商业插件更新源方案

### 2.1 用户配置方式

用户可以通过以下方式配置更新源：

```
更新源配置
├── 自定义 JSON URL
│   └── 输入：https://example.com/plugin-info.json
│
├── Git 仓库地址
│   ├── GitHub: github.com/user/repo
│   ├── GitLab: gitlab.com/user/repo
│   └── 菲码: git.wenpai.net/user/repo
│
├── 直接 ZIP URL
│   └── 输入：https://example.com/plugin.zip
│   └── 需要手动指定版本号
│
└── 预置模板（可选）
    ├── Elementor Pro
    ├── ACF Pro
    └── 其他常见商业插件
```

### 2.2 更新源数据模型

```php
/**
 * 更新源数据模型
 * 注意：type 字段必须使用 SourceType 枚举中定义的值
 */
class UpdateSource {
    public string $id;           // 唯一标识
    public string $type;         // 源类型（见 SourceType 枚举）
    public string $slug;         // 插件/主题 slug
    public string $source_url;   // 更新源地址
    public string $item_type;    // plugin|theme
    public ?string $auth_token;  // 认证令牌（私有仓库）
    public ?string $branch;      // Git 分支（可选）
    public bool $enabled;        // 是否启用
    public int $priority;        // 优先级（数字越小优先级越高）
    public array $metadata;      // 额外元数据
}

// 数据库表结构
// wp_options: wpbridge_sources = [
//     [
//         'id' => 'source_abc123',
//         'type' => 'arkpress',  // 使用 SourceType 枚举值
//         'slug' => 'my-plugin',
//         'source_url' => 'https://api.wenpai.net/v1',
//         'item_type' => 'plugin',
//         'auth_token' => null,
//         'branch' => null,
//         'enabled' => true,
//         'priority' => 10,
//         'metadata' => [],
//     ],
// ]
```

### 2.3 管理界面

```
WPBridge 设置页面
├── 更新源列表
│   ├── 添加更新源
│   ├── 编辑更新源
│   ├── 删除更新源
│   └── 启用/禁用
│
├── 更新状态
│   ├── 最后检查时间
│   ├── 可用更新数量
│   └── 手动检查按钮
│
└── 高级设置
    ├── 检查频率
    ├── 代理设置
    └── 调试日志
```

---

## 3. AI 桥接方案

### 3.1 与 WPMind 的关系

```
AI 请求流程
│
├── 第三方插件发起 AI 请求
│   └── 例：AI Engine 调用 api.openai.com
│
├── WPBridge 拦截请求
│   └── 使用 pre_http_request 钩子
│
├── 转发给 WPMind
│   └── 调用 wpmind_chat() 或 WPMind\API\PublicAPI
│
└── WPMind 处理并返回
    └── 使用配置的国内 AI 服务商
```

### 3.2 依赖关系

```php
/**
 * AI 桥接设置数据模型
 * 存储在 wp_options: wpbridge_ai_settings
 */
class AISettings {
    // 是否启用 AI 桥接
    public bool $enabled = false;

    // 桥接模式
    // - 'disabled': 完全禁用
    // - 'passthrough': 透传到用户指定端点
    // - 'wpmind': 使用 WPMind
    public string $mode = 'disabled';

    // 白名单域名（用户可配置）
    public array $whitelist = [
        'api.openai.com',
        'api.anthropic.com',
    ];

    // 自定义端点（透传模式使用）
    public ?string $custom_endpoint = null;
}

// WPBridge AI 桥接初始化
class AIBridge {
    // AI 桥接模式常量
    const MODE_DISABLED = 'disabled';      // 完全禁用
    const MODE_PASSTHROUGH = 'passthrough'; // 透传到用户指定端点
    const MODE_WPMIND = 'wpmind';          // 使用 WPMind

    private AISettings $settings;

    public function __construct() {
        $this->settings = $this->loadSettings();

        if ($this->settings->mode === self::MODE_DISABLED) {
            return;
        }

        // 注册拦截器
        add_filter('pre_http_request', [$this, 'interceptAIRequest'], 1, 3);
    }

    /**
     * 加载用户配置的设置
     */
    private function loadSettings(): AISettings {
        $saved = get_option('wpbridge_ai_settings', []);
        $settings = new AISettings();

        if (!empty($saved['enabled'])) {
            $settings->enabled = (bool) $saved['enabled'];
        }

        if (!empty($saved['mode'])) {
            $settings->mode = $saved['mode'];
        }

        // 用户配置的白名单（合并默认值）
        if (!empty($saved['whitelist']) && is_array($saved['whitelist'])) {
            $settings->whitelist = array_unique(array_merge(
                $settings->whitelist,
                $saved['whitelist']
            ));
        }

        if (!empty($saved['custom_endpoint'])) {
            $settings->custom_endpoint = $saved['custom_endpoint'];
        }

        return $settings;
    }

    /**
     * 确定实际运行模式
     * 考虑用户配置和环境依赖
     */
    private function determineEffectiveMode(): string {
        if (!$this->settings->enabled) {
            return self::MODE_DISABLED;
        }

        $userMode = $this->settings->mode;

        // 用户选择 WPMind 但 WPMind 不可用时，降级处理
        if ($userMode === self::MODE_WPMIND) {
            if (function_exists('wpmind_is_available') && wpmind_is_available()) {
                return self::MODE_WPMIND;
            }
            // WPMind 不可用，检查是否有自定义端点可降级
            if (!empty($this->settings->custom_endpoint)) {
                return self::MODE_PASSTHROUGH;
            }
            return self::MODE_DISABLED;
        }

        // 用户选择透传模式
        if ($userMode === self::MODE_PASSTHROUGH) {
            if (!empty($this->settings->custom_endpoint)) {
                return self::MODE_PASSTHROUGH;
            }
            return self::MODE_DISABLED;
        }

        return self::MODE_DISABLED;
    }

    public function interceptAIRequest($preempt, $args, $url) {
        // 使用用户配置的白名单
        if (!$this->isWhitelistedDomain($url)) {
            return $preempt;
        }

        $effectiveMode = $this->determineEffectiveMode();

        switch ($effectiveMode) {
            case self::MODE_WPMIND:
                return $this->forwardToWPMind($args, $url);
            case self::MODE_PASSTHROUGH:
                return $this->forwardToCustomEndpoint($args, $url);
            default:
                return $preempt;
        }
    }

    /**
     * 检查域名是否在用户配置的白名单中
     */
    private function isWhitelistedDomain(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST);
        return in_array($host, $this->settings->whitelist, true);
    }
}
```

### 3.3 功能边界

| 功能 | WPBridge | WPMind |
|------|----------|--------|
| HTTP 请求拦截 | ✅ | ❌ |
| OpenAI 格式解析 | ✅ | ❌ |
| AI 服务调用 | ❌ | ✅ |
| Provider 管理 | ❌ | ✅ |
| 用量统计 | ❌ | ✅ |

---

## 4. 文派叶子 API 集成

### 4.1 架构设计

```
文派叶子 (WPCY)
    │
    ├── 官方源加速（内置）
    │   └── WordPress.org → WPMirror
    │
    └── 自定义源（通过 WPBridge API）
        │
        ├── 方式 1：安装 WPBridge 插件
        │   └── 本地处理
        │
        └── 方式 2：调用 WPBridge 云 API
            └── 无需安装插件
```

### 4.2 WPBridge API 设计

**REST API 端点：**

```
WPBridge API (云服务)
│
├── GET /api/v1/sources
│   └── 获取可用的更新源列表
│
├── GET /api/v1/check/{source_id}
│   └── 检查指定源的更新
│
├── GET /api/v1/plugins/{slug}/info
│   └── 获取插件信息
│
├── GET /api/v1/themes/{slug}/info
│   └── 获取主题信息
│
└── GET /api/v1/wenpai-git/{repo}/releases
    └── 获取菲码源库的 Release 信息
```

### 4.3 文派叶子集成示例

```php
// 文派叶子调用 WPBridge API
class WPCY_WPBridge_Integration {
    private const API_BASE = 'https://api.wpbridge.wenpai.net';

    // 获取菲码源库的插件更新
    public function getWenPaiGitUpdates(string $repo): array {
        $response = wp_remote_get(
            self::API_BASE . '/api/v1/wenpai-git/' . urlencode($repo) . '/releases'
        );

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
```

### 4.4 菲码源库集成

```
菲码源库 (git.wenpai.net)
    │
    ├── 免费插件/主题仓库
    │   └── 通过 WPBridge API 提供更新
    │
    └── 文派叶子用户
        ├── 无需安装 WPBridge
        └── 通过 API 获取更新信息
```

---

## 5. 定价策略建议

### 5.1 功能分层

| 功能 | 免费版 | 付费版 |
|------|--------|--------|
| 免费插件/主题更新源 | ✅ | ✅ |
| 自定义 JSON URL | ✅ | ✅ |
| GitHub/GitLab 公开仓库 | ✅ | ✅ |
| 菲码源库集成 | ✅ | ✅ |
| AI 桥接（需 WPMind） | ✅ | ✅ |
| 私有仓库支持 | ❌ | ✅ |
| 商业插件预置模板 | ❌ | ✅ |
| 多站点支持 | ❌ | ✅ |
| 优先技术支持 | ❌ | ✅ |

### 5.2 定价方案

**方案 A：一次性付费**
```
免费版：$0
├── 基础功能
└── 社区支持

专业版：$49/站点（一次性）
├── 全部功能
├── 1 年更新
└── 邮件支持

开发者版：$149（一次性）
├── 无限站点
├── 终身更新
└── 优先支持
```

**方案 B：订阅制**
```
免费版：$0
├── 基础功能
└── 社区支持

专业版：$29/年/站点
├── 全部功能
├── 持续更新
└── 邮件支持

代理商版：$99/年
├── 无限站点
├── 白标支持
└── 优先支持
```

### 5.3 我的建议

采用 **方案 A（一次性付费）**，理由：
1. 中国用户更接受一次性付费
2. 降低用户决策门槛
3. 减少续费管理成本
4. 与文派生态其他产品保持一致

---

## 6. 技术架构总览

```
WPBridge 架构
│
├── 插件层 (WordPress Plugin)
│   ├── Core/
│   │   ├── Plugin.php
│   │   └── Settings.php
│   │
│   ├── UpdateSource/
│   │   ├── SourceManager.php
│   │   ├── Handlers/
│   │   │   ├── JsonHandler.php
│   │   │   ├── GitHubHandler.php
│   │   │   ├── GitLabHandler.php
│   │   │   └── WenPaiGitHandler.php
│   │   └── Updater.php
│   │
│   ├── AIBridge/
│   │   ├── Interceptor.php
│   │   └── WPMindForwarder.php
│   │
│   └── Admin/
│       └── AdminPage.php
│
└── API 层 (云服务，可选)
    ├── /api/v1/sources
    ├── /api/v1/check
    └── /api/v1/wenpai-git
```

---

## 附录 A: 设计讨论记录

### A.1 更新源配置方式重构讨论 (2026-02-04)

#### 问题背景

当前设计采用"更新源优先"的配置方式：

```
更新源 → 匹配规则 (item_type + slug) → 插件/主题
```

存在以下问题：
1. 用户需要手动输入 slug，容易出错
2. 不直观，用户看不到"我有哪些插件可以配置"
3. 没有分开系统/插件/主题更新源
4. 对于商业插件用户，他们的心智模型是"我买了这个插件，想配置它的更新源"

#### 方案对比

**方案 A（当前）：更新源优先**

```
┌─────────────────────────────────┐
│ 更新源列表                        │
│ ├── 源1 (插件, slug: yoast-seo)  │
│ ├── 源2 (主题, slug: flavor)     │
│ └── 源3 (插件, slug: *)          │
└─────────────────────────────────┘
```

优点：
- 实现简单，统一模型
- 可预配置未安装的插件/主题
- 一个源可批量匹配多个项目

缺点：
- 需要手动输入 slug
- 不直观，无法看到已安装列表
- 分类不清晰

**方案 B（提议）：项目优先**

```
┌─────────────────────────────────┐
│ 系统更新源                        │
│ └── WordPress 核心               │
├─────────────────────────────────┤
│ 插件更新源                        │
│ ├── Yoast SEO [已安装] → 源配置   │
│ ├── WooCommerce [已安装] → 源配置 │
│ └── + 添加自定义插件              │
├─────────────────────────────────┤
│ 主题更新源                        │
│ ├── flavor [已安装] → 源配置      │
│ └── + 添加自定义主题              │
└─────────────────────────────────┘
```

优点：
- 直观，用户看到已安装的插件/主题列表
- 易用，点击选择，不需要手动输入 slug
- 清晰，系统/插件/主题分类明确
- 状态可见，哪些已配置自定义源一目了然

缺点：
- 实现复杂度增加
- 需要重构现有数据结构
- 批量配置场景需要额外考虑

#### 待讨论问题

1. **数据结构设计**：如何存储项目与更新源的关联？
2. **批量配置**：如何支持"所有未配置的插件使用默认源"？
3. **预配置**：如何支持为未安装的插件预配置更新源？
4. **迁移方案**：如何从方案 A 迁移到方案 B？
5. **UI 设计**：Tab 结构还是其他布局？

#### 讨论状态

- [x] Codex 评审方案 B 可行性
- [ ] 确定数据结构设计
- [ ] 确定 UI 设计方案
- [ ] 制定迁移计划
- [ ] 开始重构实现

#### Codex 评审结论 (2026-02-04)

**结论：方案 B 合理且更贴近用户心智模型，但必须保留"源复用/批量"能力。**

##### 关键发现

| 优先级 | 问题 |
|--------|------|
| 高 | 底层仍需"源复用 + 单源多项目"能力，否则会导致重复请求同一源 |
| 高 | 当前以 `slug` 为主键，但 WordPress 实际使用 `plugin_basename`，需改用文件路径 |
| 中 | 预配置未安装插件需要明确冲突处理机制 |
| 中 | 默认源/自定义源/禁用更新的优先级与回退策略需清晰 |
| 中 | 迁移时 `slug:*` 通配规则如何落入"默认源"需定义 |
| 低 | 大量插件时 UI 需分页/搜索/缓存 |

##### 建议的数据结构：三层架构

**a) 源注册表（沿用/增强现有结构）**

```php
// wpbridge_sources - 只描述"源本身"
[
    'id'         => 'src_xxx',
    'type'       => 'json|github|gitlab|arkpress',
    'source_url' => 'https://...',
    'auth_token' => '***',
    'branch'     => 'main',
    'enabled'    => true,
    'priority'   => 50,
    'metadata'   => [],
]
```

**b) 项目配置表（新的"项目优先"核心）**

```php
// wpbridge_item_sources - 项目与源的绑定
[
    'item_key'   => 'plugin:woocommerce/woocommerce.php', // 已安装用 plugin_basename
    'item_type'  => 'plugin|theme|core',
    'slug'       => 'woocommerce',                        // 兼容预配置
    'label'      => 'WooCommerce',
    'mode'       => 'default|custom|disabled',
    'source_ids' => ['src_x', 'src_y'],                   // 支持多源与回退
    'metadata'   => [
        'preconfigured' => false,
        'installed'     => true,
        'last_seen'     => 1738684800,
    ],
]
```

**c) 默认规则（批量配置的基础）**

```php
// wpbridge_defaults - 类型级默认源
[
    'core'   => 'wporg',           // wporg|mirror|source_id
    'plugin' => 'wporg',
    'theme'  => 'wporg',
]
```

##### 批量配置方案

1. **动态默认规则**：未显式配置的项目，运行时继承 `wpbridge_defaults`
2. **批量动作（UI）**：支持在列表进行"批量应用自定义源"

##### 预配置未安装插件

- UI 提供"添加自定义插件/主题"入口
- 存为 `metadata.preconfigured=true`
- 安装后匹配流程：
  1. 优先匹配 slug
  2. 若有 `plugin_uri` 元数据，进行二次确认
  3. 匹配成功则把 `item_key` 升级为插件文件路径
- 冲突时弹出"绑定确认"

##### UI 设计建议

- **Tab 结构**："核心 / 插件 / 主题"
- **插件/主题页**：`WP_List_Table` 风格
  - 列：名称、来源（默认/自定义/禁用）、源类型、状态、操作
  - 顶部：默认源选择 + 搜索 + 筛选
  - 批量操作：应用某源 / 切回默认 / 禁用更新
- **核心页**：单卡片 + 源选择 + 状态

##### 迁移方案（A → B）

1. 读取旧 `wpbridge_sources`
2. 对每条源：
   - `slug == "*"` → 写入 `wpbridge_defaults`
   - `slug != "*"` → 写入 `wpbridge_item_sources`
3. 保留旧数据 `wpbridge_sources_legacy` 供回滚
4. UI 提供"迁移报告 / 冲突列表"

##### 其他考虑

- **更新请求聚合**：请求仍应按"源"聚合发起
- **多源回退策略**：明确"优先级 + 失败回退"规则
- **禁用更新**：提供项目级禁用开关
- **多站点**：网络级默认 vs 站点级覆盖
- **日志与诊断**：新增"某插件最终使用的更新源"诊断视图

---

### A.2 详细数据模型草案 (2026-02-04)

> 由 Codex 生成，考虑 FAIR DID 标准兼容性

#### FAIR 项目参考

[FAIR](https://fair.pm/) (Federated And Independent Repositories) 是 Linux Foundation 支持的去中心化 WordPress 包管理项目：
- 使用 [W3C DID](https://www.w3.org/TR/did-1.1/) (Decentralized Identifier) 标准唯一标识插件/主题
- 支持 ED25519 加密签名验证
- 支持从多个独立源安装和更新

#### 表结构设计

##### 1. `wpbridge_sources`（源注册表）

注册可用更新源（WP.org、FAIR 源、私有仓库等），描述其能力与安全策略。

| 字段 | 类型 | 说明 | 约束/索引 |
|------|------|------|-----------|
| id | BIGINT UNSIGNED | 主键 | PK, auto_increment |
| source_key | VARCHAR(64) | 源唯一键（slug） | UNIQUE |
| name | VARCHAR(191) | 显示名 | |
| type | ENUM('wporg','fair','custom','git','s3','file','mirror') | 源类型 | INDEX |
| base_url | VARCHAR(512) | 源基础 URL | |
| api_url | VARCHAR(512) | API 地址 | |
| did | VARCHAR(191) | 源 DID（FAIR 兼容） | INDEX |
| public_key | TEXT | ED25519 公钥（Base64/Multibase） | |
| signature_scheme | ENUM('none','ed25519') | 签名方案 | |
| signature_required | TINYINT(1) | 是否强制签名 | |
| trust_level | SMALLINT UNSIGNED | 信任等级 0-100 | INDEX |
| enabled | TINYINT(1) | 是否启用 | INDEX |
| default_priority | INT | 默认优先级 | INDEX |
| auth_type | ENUM('none','basic','bearer','token','oauth2') | 认证类型 | |
| auth_secret_ref | VARCHAR(191) | 密钥引用（不存明文） | |
| headers_json | LONGTEXT | 附加请求头 JSON | |
| capabilities_json | LONGTEXT | 能力描述 JSON | |
| rate_limit_json | LONGTEXT | 限流策略 JSON | |
| cache_ttl | INT UNSIGNED | 缓存秒数 | |
| last_checked_at | DATETIME | 最后检测 | |
| created_at | DATETIME | 创建时间 | |
| updated_at | DATETIME | 更新时间 | |

##### 2. `wpbridge_item_sources`（项目配置表）

为每个项目配置其来源列表、优先级、签名策略与锁定规则。

| 字段 | 类型 | 说明 | 约束/索引 |
|------|------|------|-----------|
| id | BIGINT UNSIGNED | 主键 | PK |
| item_id | BIGINT UNSIGNED | 项目 ID | INDEX |
| item_slug | VARCHAR(191) | 项目标识（plugin_basename） | INDEX |
| item_type | ENUM('plugin','theme','mu-plugin','dropin') | 类型 | INDEX |
| item_did | VARCHAR(191) | 项目 DID（FAIR 兼容） | INDEX |
| source_id | BIGINT UNSIGNED | 关联源 | INDEX |
| source_priority | INT | 源优先级（越小越优先） | INDEX |
| pinned | TINYINT(1) | 是否固定该源 | |
| signature_required | TINYINT(1) | 对此项目是否强制签名 | |
| allow_unsigned | TINYINT(1) | 是否允许无签名 | |
| allow_prerelease | TINYINT(1) | 是否允许预发布 | |
| min_version | VARCHAR(32) | 允许最小版本 | |
| max_version | VARCHAR(32) | 允许最大版本 | |
| last_good_version | VARCHAR(32) | 最近成功版本 | |
| policy_json | LONGTEXT | 项目级策略 JSON | |
| created_at | DATETIME | 创建时间 | |
| updated_at | DATETIME | 更新时间 | |

约束：`UNIQUE (item_slug, source_id)`

##### 3. `wpbridge_defaults`（默认规则）

当项目无特定配置时的全局策略。

| 字段 | 类型 | 说明 | 约束/索引 |
|------|------|------|-----------|
| id | BIGINT UNSIGNED | 主键 | PK |
| scope | ENUM('global','plugin','theme') | 作用范围 | UNIQUE |
| default_source_order | LONGTEXT | 默认源优先序 JSON | |
| signature_required | TINYINT(1) | 默认签名要求 | |
| allow_unsigned | TINYINT(1) | 默认是否允许无签名 | |
| allow_prerelease | TINYINT(1) | 默认是否允许预发布 | |
| trust_floor | SMALLINT UNSIGNED | 最低信任阈值 | |
| fallback_to_wporg | TINYINT(1) | 未命中时回退 WP.org | |
| policy_json | LONGTEXT | 其他默认策略 JSON | |
| updated_at | DATETIME | 更新时间 | |

#### 迁移算法伪代码

```pseudo
function migrate_A_to_B():
  begin_transaction()

  // 1) 备份与版本标记
  backup_old_tables()
  create_new_tables_if_not_exists()

  // 2) 迁移 source 注册表
  for each old_source in A.sources:
      new_source = map_source(old_source)
      upsert wpbridge_sources(new_source)

  // 3) 构建项目索引（按 slug / DID）
  project_index = {}
  for each source_item in A.source_items:
      key = resolve_item_key(source_item)  // prefer DID, fallback slug
      if not project_index[key]:
          project_index[key] = new_project_record(source_item)
      project_index[key].sources.append(source_item.source_id)

  // 4) 写入 item_sources
  for each project in project_index:
      priorities = compute_priorities(project.sources, A.rules)
      for each src in project.sources:
          record = build_item_source(project, src, priorities[src])
          upsert wpbridge_item_sources(record)

  // 5) 迁移默认规则
  defaults = map_defaults(A.global_settings)
  upsert wpbridge_defaults(defaults)

  // 6) 校验
  if not validate_migration():
      rollback()
      return error

  commit()
  return ok
```

#### 冲突处理规则（按优先顺序）

1. **用户显式固定（pinned）优先**
2. **DID 匹配优先于 slug**
3. **签名验证成功优先**
4. **信任等级高优先**（trust_level）
5. **版本号高且非 prerelease 优先**
6. 若仍冲突，保留多个源并记录冲突标记

#### 回滚机制

- 迁移全程使用事务
- 迁移前备份旧表为 `_backup` 表
- 若校验失败：ROLLBACK + 删除新表 + 恢复旧表
- 使用 `migration_version`/`migration_state` 标记状态

#### 数据验证

- **数量校验**：item_sources 项目数 ≥ 旧方案唯一项目数
- **引用校验**：source_id 必须存在于 wpbridge_sources
- **配置校验**：每个项目至少有一个可用源
- **签名校验**：抽样验证带签名包的元数据

#### FAIR 兼容性建议

| 问题 | 建议 |
|------|------|
| 是否支持 DID？ | **是**，便于签名验证、可信溯源、多源协作 |
| 如何与 FAIR 互操作？ | 将 FAIR 视为 `type='fair'` 的源，支持 DID 解析 |
| 加密签名验证？ | **默认支持**，可配置为强制/推荐/可选 |

---

*最后更新: 2026-02-04*

---

## 附录 B: UX 设计改进计划

> 2026-02-04 设计评审：识别并修复反人类的设计

### B.1 问题：更新源配置流程过于复杂

**当前流程（反人类）：**
```
1. 用户想为某个插件设置自定义更新源
2. 必须先去"更新源"Tab 创建源配置（填写名称、URL、类型、认证...）
3. 再回到"项目"Tab，从下拉框选择刚才配置的源
4. 如果配置错误，还要来回切换修改
```

**问题分析：**
- 认知负担重：用户需要先理解"源"这个抽象概念
- 操作路径长：两个 Tab 来回切换
- 过度工程化：为了"复用"牺牲了简单性
- 不符合用户心智：用户想的是"给这个插件设置更新地址"

**改进方案：混合模式**

```
┌─────────────────────────────────────────────────────────────┐
│  简单模式（80% 用户）                                        │
│  ─────────────────────                                       │
│  项目列表 → 点击"设置更新源" → 直接输入 URL → 自动保存       │
│                                                              │
│  - 只需粘贴 URL                                              │
│  - 自动推断源类型（GitHub/GitLab/JSON/...）                  │
│  - 自动生成源名称                                            │
│  - 无需理解"源"概念                                          │
├─────────────────────────────────────────────────────────────┤
│  高级模式（20% 用户）                                        │
│  ─────────────────────                                       │
│  更新源 Tab → 创建可复用的源配置 → 项目中选择                │
│                                                              │
│  - 需要认证（API Key/Token）                                 │
│  - 需要签名验证                                              │
│  - 一个源被多个项目复用                                      │
│  - 企业内网统一配置                                          │
└─────────────────────────────────────────────────────────────┘
```

**实现要点：**
1. 项目列表每行添加"快速设置"按钮
2. 点击后弹出简化表单，只需输入 URL
3. 后端自动创建"内联源"（inline source），标记为项目专属
4. 高级用户仍可使用"更新源"Tab 创建共享源

### B.2 待排查的其他设计问题

| 问题 | 当前设计 | 问题描述 | 改进方向 |
|------|----------|----------|----------|
| **默认规则配置** | 拖拽排序 + 开关 | 不直观，用户不理解"优先级" | 简化为"首选源"单选 |
| **批量操作** | prompt() 输入源 Key | 需要记住源 Key | 改为下拉选择 |
| **源类型选择** | 下拉框选择类型 | 用户不知道选哪个 | 自动推断，隐藏技术细节 |
| **认证配置** | 单独的认证类型字段 | 复杂 | 智能检测，自动配置 |
| **测试连接** | 手动点击测试 | 容易忘记 | 保存时自动测试 |
| **错误提示** | Toast 通知 | 一闪而过 | 持久化显示，提供解决方案 |

### B.3 设计原则更新

在 0.1 核心原则中添加：

| 原则 | 说明 |
|------|------|
| **简单优先** | 80% 场景用最简单的方式完成，复杂功能隐藏在高级选项 |
| **零配置可用** | 开箱即用，预置源覆盖常见需求 |
| **渐进式复杂度** | 简单 → 中级 → 高级，用户按需深入 |
| **符合心智模型** | 用户想"设置更新地址"，不是"创建源配置" |

### B.4 实施优先级

| 优先级 | 改进项 | 工作量 |
|--------|--------|--------|
| P0 | 项目列表内联添加源 | 中 |
| P0 | 批量操作改为下拉选择 | 小 |
| P1 | 源类型自动推断 | 中 |
| P1 | 保存时自动测试连接 | 小 |
| P2 | 默认规则简化 | 中 |
| P2 | 错误提示改进 | 小 |

---

*最后更新: 2026-02-04*

### B.5 详细问题清单

#### 问题 1：源编辑器表单过于复杂

**当前表单字段：**
```
基本信息：
├── 名称 *（必填）      → 用户不知道填什么
├── 类型 *（必填）      → 用户不知道选哪个（JSON/GitHub/GitLab/...）
└── API URL *（必填）   → 这是唯一真正需要的

匹配规则：
├── 项目类型           → 为什么要选？应该自动推断
├── Slug               → 什么是 slug？普通用户不懂
└── 优先级             → 数字越小优先级越高？反直觉！

认证设置：
├── 认证令牌           → 大多数情况不需要
└── 启用状态           → 为什么要手动启用？
```

**改进方案：简化表单**
```
简单模式（默认）：
└── 更新地址 *         → 只需要这一个字段

高级选项（折叠）：
├── 名称               → 自动从 URL 生成，可修改
├── 访问密码           → 仅私有仓库需要
└── 更多设置...        → 类型、优先级等
```

#### 问题 2：优先级设计反直觉

**当前设计：**
- "数字越小优先级越高（0-100）"
- 0 = 最高优先级，100 = 最低优先级

**问题：**
- 违反常识（通常数字越大越重要）
- 用户需要记住这个反直觉的规则

**改进方案：**
```
方案 A：反转数字含义
- 100 = 最高优先级，0 = 最低优先级

方案 B：使用语义化选项（推荐）
- 首选源 / 备选源 / 最后选择
- 或：高 / 中 / 低

方案 C：拖拽排序
- 直接拖拽调整顺序，无需理解数字
```

#### 问题 3：术语不友好

| 当前术语 | 问题 | 改进建议 |
|----------|------|----------|
| Slug | 技术术语，普通用户不懂 | "插件/主题名称" 或直接隐藏 |
| API URL | 技术感太强 | "更新地址" |
| 认证令牌 | 不够直白 | "访问密码" 或 "API 密钥" |
| 优先级 | 抽象概念 | "首选程度" 或用拖拽排序 |
| 源类型 | 用户不关心 | 自动推断，隐藏技术细节 |

#### 问题 4：缺少智能推断

**当前流程：**
```
用户输入 URL: https://github.com/developer/plugin/releases/latest
然后还要手动选择：
- 类型：GitHub
- 项目类型：插件
- Slug：plugin
```

**改进方案：自动推断**
```
用户输入 URL: https://github.com/developer/plugin/releases/latest
系统自动识别：
- 类型：GitHub（从 URL 域名推断）
- 名称：developer/plugin（从 URL 路径提取）
- 项目类型：根据上下文推断
```

**URL 模式识别规则：**
```javascript
const patterns = {
  'github.com':     { type: 'github', extract: /github\.com\/([^\/]+\/[^\/]+)/ },
  'gitlab.com':     { type: 'gitlab', extract: /gitlab\.com\/([^\/]+\/[^\/]+)/ },
  'gitee.com':      { type: 'gitee',  extract: /gitee\.com\/([^\/]+\/[^\/]+)/ },
  'api.wordpress.org': { type: 'wporg' },
  'wpmirror.com':   { type: 'mirror' },
  '*.json':         { type: 'json' },
  'default':        { type: 'custom' }
};
```

#### 问题 5：批量操作使用 prompt()

**当前实现：**
```javascript
if (action === 'set_source') {
    var sourceKey = prompt('请输入源 Key:');
    // ...
}
```

**问题：**
- 用户需要记住源 Key
- 容易输入错误
- 体验差

**改进方案：**
```html
<!-- 使用模态框 + 下拉选择 -->
<div class="wpbridge-modal">
    <h3>选择更新源</h3>
    <select>
        <option value="wporg">WordPress.org</option>
        <option value="wenpai-mirror">文派镜像</option>
        <option value="custom-1">我的私有仓库</option>
    </select>
    <button>确定</button>
</div>
```

#### 问题 6：测试连接需要手动触发

**当前流程：**
1. 填写表单
2. 点击保存
3. 保存成功
4. 手动点击"测试"按钮
5. 发现 URL 错误
6. 重新编辑

**改进方案：**
```
1. 填写 URL
2. 自动实时验证（防抖 500ms）
3. 显示验证状态：✓ 可连接 / ✗ 无法连接
4. 点击保存（如果验证失败，显示警告但允许保存）
```

### B.6 改进实施路线图

**Phase 1：快速修复（1-2 天）**
- [ ] 批量操作改为下拉选择
- [ ] 优先级改为"高/中/低"
- [ ] 术语本地化（Slug → 名称）

**Phase 2：简化表单（3-5 天）**
- [ ] 源编辑器简化为单字段
- [ ] URL 自动推断类型和名称
- [ ] 高级选项折叠

**Phase 3：内联添加源（3-5 天）**
- [ ] 项目列表添加"快速设置"按钮
- [ ] 弹出简化表单
- [ ] 创建项目专属的内联源

**Phase 4：智能化（持续）**
- [ ] 保存时自动测试连接
- [ ] 错误提示改进
- [ ] 使用引导和帮助文档
