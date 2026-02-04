# WPBridge 业务流程与架构设计

> 详细的业务流程图和系统架构

*创建日期: 2026-02-04*

---

## 1. 核心业务流程

### 1.1 更新源桥接流程

```
┌─────────────────────────────────────────────────────────────────┐
│                    WordPress 更新检查流程                         │
└─────────────────────────────────────────────────────────────────┘

WordPress 核心                    WPBridge                      外部源
    │                               │                             │
    │  1. 触发更新检查               │                             │
    │  (wp_update_plugins)          │                             │
    │──────────────────────────────>│                             │
    │                               │                             │
    │                               │  2. 检查是否有自定义源        │
    │                               │  (查询 wpbridge_sources)     │
    │                               │                             │
    │                               │  3. 遍历匹配的源             │
    │                               │──────────────────────────────>
    │                               │                             │
    │                               │  4. 获取版本信息             │
    │                               │<──────────────────────────────
    │                               │                             │
    │                               │  5. 缓存结果                 │
    │                               │  (transient)                │
    │                               │                             │
    │  6. 返回更新信息               │                             │
    │<──────────────────────────────│                             │
    │                               │                             │
    │  7. 显示更新通知               │                             │
    │                               │                             │
```

### 1.2 更新下载流程

```
┌─────────────────────────────────────────────────────────────────┐
│                    插件/主题下载流程                              │
└─────────────────────────────────────────────────────────────────┘

用户点击更新                      WPBridge                      外部源
    │                               │                             │
    │  1. 触发下载                   │                             │
    │  (upgrader_pre_download)      │                             │
    │──────────────────────────────>│                             │
    │                               │                             │
    │                               │  2. 检查是否需要桥接         │
    │                               │  (匹配 slug)                │
    │                               │                             │
    │                               │  3. 获取下载 URL            │
    │                               │  (可能需要认证)             │
    │                               │──────────────────────────────>
    │                               │                             │
    │                               │  4. 下载 ZIP 包             │
    │                               │<──────────────────────────────
    │                               │                             │
    │                               │  5. 安全检查                 │
    │                               │  (哈希/大小/结构)           │
    │                               │                             │
    │  6. 返回本地文件路径           │                             │
    │<──────────────────────────────│                             │
    │                               │                             │
    │  7. WordPress 安装更新         │                             │
    │                               │                             │
```

### 1.3 AI 桥接流程

```
┌─────────────────────────────────────────────────────────────────┐
│                    AI 请求桥接流程                                │
└─────────────────────────────────────────────────────────────────┘

第三方插件                        WPBridge                    AI 服务
(如 AI Engine)                      │                             │
    │                               │                             │
    │  1. 发起 HTTP 请求             │                             │
    │  (api.openai.com)             │                             │
    │──────────────────────────────>│                             │
    │                               │                             │
    │                               │  2. 白名单检查               │
    │                               │  (是否在拦截列表)           │
    │                               │                             │
    │                               │  3. 模式判断                 │
    │                               │  ┌─────────────────────────┐│
    │                               │  │ MODE_DISABLED → 放行    ││
    │                               │  │ MODE_PASSTHROUGH → 转发 ││
    │                               │  │ MODE_WPMIND → WPMind    ││
    │                               │  └─────────────────────────┘│
    │                               │                             │
    │                               │  4a. 透传模式               │
    │                               │──────────────────────────────>
    │                               │      (用户指定端点)          │
    │                               │                             │
    │                               │  4b. WPMind 模式            │
    │                               │──────> WPMind ──────────────>
    │                               │      (国内 AI 服务)          │
    │                               │                             │
    │  5. 返回响应                   │                             │
    │<──────────────────────────────│                             │
    │                               │                             │
```

---

## 2. 系统架构

### 2.1 整体架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                         WPBridge 架构                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                     Admin Layer                          │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐      │   │
│  │  │ AdminPage   │  │ SourceEditor│  │ AISettings  │      │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                              │                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                     Core Layer                           │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐      │   │
│  │  │ Plugin      │  │ Settings    │  │ Logger      │      │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                              │                                  │
│  ┌──────────────────────────┴──────────────────────────────┐   │
│  │                                                          │   │
│  │  ┌─────────────────────┐    ┌─────────────────────┐     │   │
│  │  │   UpdateSource      │    │     AIBridge        │     │   │
│  │  │   Module            │    │     Module          │     │   │
│  │  │                     │    │                     │     │   │
│  │  │  ┌───────────────┐  │    │  ┌───────────────┐  │     │   │
│  │  │  │SourceManager  │  │    │  │ Interceptor   │  │     │   │
│  │  │  └───────────────┘  │    │  └───────────────┘  │     │   │
│  │  │  ┌───────────────┐  │    │  ┌───────────────┐  │     │   │
│  │  │  │PluginUpdater  │  │    │  │ WPMindBridge  │  │     │   │
│  │  │  └───────────────┘  │    │  └───────────────┘  │     │   │
│  │  │  ┌───────────────┐  │    │  ┌───────────────┐  │     │   │
│  │  │  │ ThemeUpdater  │  │    │  │ Passthrough   │  │     │   │
│  │  │  └───────────────┘  │    │  └───────────────┘  │     │   │
│  │  │                     │    │                     │     │   │
│  │  └─────────────────────┘    └─────────────────────┘     │   │
│  │                                                          │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              │                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                   Handler Layer                          │   │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐        │   │
│  │  │ JSON    │ │ GitHub  │ │ GitLab  │ │ WenPai  │        │   │
│  │  │ Handler │ │ Handler │ │ Handler │ │ Handler │        │   │
│  │  └─────────┘ └─────────┘ └─────────┘ └─────────┘        │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 目录结构

```
wpbridge/
├── wpbridge.php                     # 主文件
├── uninstall.php                    # 卸载脚本
├── CHANGELOG.md                     # 更新日志
│
├── includes/
│   ├── Core/
│   │   ├── Plugin.php               # 插件主类
│   │   ├── Loader.php               # 自动加载
│   │   ├── Settings.php             # 设置管理
│   │   ├── Logger.php               # 日志系统
│   │   └── Encryption.php           # 加密工具
│   │
│   ├── UpdateSource/
│   │   ├── SourceManager.php        # 更新源管理
│   │   ├── SourceModel.php          # 数据模型
│   │   ├── PluginUpdater.php        # 插件更新器
│   │   ├── ThemeUpdater.php         # 主题更新器
│   │   ├── CacheManager.php         # 缓存管理
│   │   └── Handlers/
│   │       ├── HandlerInterface.php # 统一接口
│   │       ├── JsonHandler.php      # JSON API
│   │       ├── GitHubHandler.php    # GitHub
│   │       ├── GitLabHandler.php    # GitLab
│   │       └── WenPaiGitHandler.php # 菲码源库
│   │
│   ├── AIBridge/
│   │   ├── AIGateway.php            # AI 网关
│   │   ├── Interceptor.php          # 请求拦截
│   │   ├── WPMindBridge.php         # WPMind 桥接
│   │   ├── Passthrough.php          # 透传模式
│   │   └── Adapters/
│   │       ├── AdapterInterface.php
│   │       ├── YoastAdapter.php
│   │       └── RankMathAdapter.php
│   │
│   └── Admin/
│       ├── AdminPage.php            # 管理页面
│       ├── SourceEditor.php         # 更新源编辑器
│       └── AISettings.php           # AI 设置
│
├── templates/
│   └── admin/
│       ├── settings.php
│       ├── source-list.php
│       ├── source-editor.php
│       └── ai-settings.php
│
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
│
└── languages/
    └── wpbridge.pot
```

---

## 3. 数据模型

### 3.1 更新源数据结构

```php
// wp_options: wpbridge_sources
[
    [
        'id'          => 'src_abc123',           // 唯一标识
        'name'        => 'My Plugin Source',     // 显示名称
        'type'        => 'json',                 // json|github|gitlab|wenpai|zip
        'slug'        => 'my-plugin',            // 插件/主题 slug
        'item_type'   => 'plugin',               // plugin|theme
        'source_url'  => 'https://...',          // 更新源地址
        'auth_type'   => 'token',                // none|token|basic|oauth
        'auth_token'  => 'encrypted:...',        // 加密存储
        'branch'      => 'main',                 // Git 分支（可选）
        'enabled'     => true,                   // 是否启用
        'priority'    => 10,                     // 优先级
        'created_at'  => '2026-02-04 10:00:00',
        'updated_at'  => '2026-02-04 10:00:00',
    ],
    // ...
]
```

### 3.2 缓存数据结构

```php
// transient: wpbridge_update_cache_{slug}
[
    'version'      => '2.0.0',
    'download_url' => 'https://...',
    'tested'       => '6.4',
    'requires'     => '5.9',
    'requires_php' => '7.4',
    'last_checked' => 1707012000,
    'source_id'    => 'src_abc123',
]

// transient: wpbridge_source_health_{source_id}
[
    'status'       => 'healthy',  // healthy|degraded|failed
    'last_check'   => 1707012000,
    'error_count'  => 0,
    'last_error'   => null,
]
```

### 3.3 AI 桥接配置

```php
// wp_options: wpbridge_ai_settings
[
    'enabled'          => true,
    'mode'             => 'wpmind',  // disabled|passthrough|wpmind
    'custom_endpoint'  => '',        // 透传模式的目标端点
    'whitelist'        => [          // 拦截白名单
        'api.openai.com',
        'api.anthropic.com',
    ],
    'adapters'         => [          // 启用的适配器
        'yoast'     => true,
        'rankmath'  => false,
    ],
]
```

---

## 4. 核心类设计

### 4.1 SourceHandlerInterface

```php
<?php
namespace WPBridge\UpdateSource\Handlers;

interface SourceHandlerInterface {
    /**
     * 获取处理器能力
     * @return array ['auth' => [], 'version' => [], 'download' => []]
     */
    public function getCapabilities(): array;

    /**
     * 获取最新版本信息
     * @param string $identifier 源标识（URL/仓库地址）
     * @param array $options 选项（认证信息等）
     * @return VersionInfo|null
     */
    public function getLatestVersion(string $identifier, array $options = []): ?VersionInfo;

    /**
     * 获取下载 URL
     * @param string $identifier 源标识
     * @param string $version 版本号
     * @param array $options 选项
     * @return string|null
     */
    public function getDownloadUrl(string $identifier, string $version, array $options = []): ?string;

    /**
     * 验证源可用性
     * @param string $identifier 源标识
     * @param array $options 选项
     * @return HealthStatus
     */
    public function checkHealth(string $identifier, array $options = []): HealthStatus;
}
```

### 4.2 VersionInfo 值对象

```php
<?php
namespace WPBridge\UpdateSource;

class VersionInfo {
    public string $version;
    public string $downloadUrl;
    public ?string $tested = null;
    public ?string $requires = null;
    public ?string $requiresPhp = null;
    public ?string $changelog = null;
    public ?string $hash = null;
    public ?int $fileSize = null;
    public int $checkedAt;

    public function __construct(string $version, string $downloadUrl) {
        $this->version = $version;
        $this->downloadUrl = $downloadUrl;
        $this->checkedAt = time();
    }

    public function toArray(): array {
        return [
            'version'      => $this->version,
            'download_url' => $this->downloadUrl,
            'tested'       => $this->tested,
            'requires'     => $this->requires,
            'requires_php' => $this->requiresPhp,
            'changelog'    => $this->changelog,
            'hash'         => $this->hash,
            'file_size'    => $this->fileSize,
            'checked_at'   => $this->checkedAt,
        ];
    }
}
```

### 4.3 HealthStatus 值对象

```php
<?php
namespace WPBridge\UpdateSource;

class HealthStatus {
    const STATUS_HEALTHY = 'healthy';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_FAILED = 'failed';

    public string $status;
    public int $responseTime;  // ms
    public ?string $error = null;
    public int $checkedAt;

    public static function healthy(int $responseTime): self {
        $status = new self();
        $status->status = self::STATUS_HEALTHY;
        $status->responseTime = $responseTime;
        $status->checkedAt = time();
        return $status;
    }

    public static function failed(string $error): self {
        $status = new self();
        $status->status = self::STATUS_FAILED;
        $status->responseTime = 0;
        $status->error = $error;
        $status->checkedAt = time();
        return $status;
    }
}
```

---

## 5. WordPress 钩子集成

### 5.1 更新检查钩子

```php
// 插件更新检查
add_filter('pre_set_site_transient_update_plugins', [$this, 'checkPluginUpdates'], 10, 1);

// 主题更新检查
add_filter('pre_set_site_transient_update_themes', [$this, 'checkThemeUpdates'], 10, 1);

// 插件信息 API
add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);

// 主题信息 API
add_filter('themes_api', [$this, 'themeInfo'], 20, 3);

// 下载包过滤
add_filter('upgrader_pre_download', [$this, 'filterDownload'], 10, 3);
```

### 5.2 AI 拦截钩子

```php
// HTTP 请求拦截（优先级 1，最早执行）
add_filter('pre_http_request', [$this, 'interceptAIRequest'], 1, 3);
```

---

## 6. 错误处理与日志

### 6.1 错误码定义

```php
class ErrorCodes {
    // 源相关错误 (1xxx)
    const SOURCE_NOT_FOUND = 1001;
    const SOURCE_UNREACHABLE = 1002;
    const SOURCE_INVALID_RESPONSE = 1003;
    const SOURCE_AUTH_FAILED = 1004;

    // 下载相关错误 (2xxx)
    const DOWNLOAD_FAILED = 2001;
    const DOWNLOAD_HASH_MISMATCH = 2002;
    const DOWNLOAD_SIZE_EXCEEDED = 2003;
    const DOWNLOAD_INVALID_ZIP = 2004;

    // AI 桥接错误 (3xxx)
    const AI_WPMIND_UNAVAILABLE = 3001;
    const AI_ENDPOINT_UNREACHABLE = 3002;
    const AI_RESPONSE_INVALID = 3003;

    // 配置错误 (4xxx)
    const CONFIG_INVALID = 4001;
    const CONFIG_ENCRYPTION_FAILED = 4002;
}
```

### 6.2 日志级别

```php
class Logger {
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    public function log(string $level, string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
```

---

*最后更新: 2026-02-04*
