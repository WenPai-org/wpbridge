# WPBridge 开发路线图

> 自定义源桥接器 - 版本规划和开发任务

*创建日期: 2026-02-04*

---

## 当前版本: v0.8.0

## 版本规划

```
v0.1.0 - MVP：最小可用桥接 + 基础缓存/降级 ✅ 已完成
    ↓
v0.2.0 - Git 仓库支持 + WP-CLI + 性能优化 ✅ 已完成
    ↓
v0.3.0 - 源分组 + 商业插件检测 ✅ 已完成
    ↓
v0.4.0 - Bridge API ✅ 已完成
    ↓
v0.8.0 - 配置导入导出 + 稳定性优化 ✅ 已完成
    ↓
v0.9.0 - 版本控制 + 用户体验 ← 当前目标
    ↓
v1.0.0 - 正式发布
```

---

## v0.1.0 - MVP：最小可用桥接 + 基础缓存/降级 ✅

### 目标
实现最小可用的更新源桥接功能，确保基础稳定性

### 任务清单

#### 插件基础结构
- [x] 主文件 `wpbridge.php`
- [x] 自动加载器 `Loader.php`
- [x] 设置页面框架 `Settings.php`
- [x] 数据存储结构

#### 预置更新源
- [x] 文派开源更新源（默认启用）
- [x] ArkPress 支持（文派自托管方案）
- [x] AspireCloud 支持（可选）

#### 更新源管理（基础）
- [x] 更新源数据模型（使用统一 SourceType 枚举）
- [x] 更新源 CRUD 操作
- [x] 更新源列表界面
- [x] 添加/编辑更新源表单

#### 核心桥接功能
- [x] `pre_set_site_transient_update_plugins` 钩子
- [x] `pre_set_site_transient_update_themes` 钩子
- [x] JSON API 处理器（JsonHandler）
- [x] ArkPress 处理器（ArkPressHandler）
- [x] AspireCloud 处理器（AspireCloudHandler）
- [x] Plugin Update Checker JSON 格式兼容（PUCHandler）

#### 基础缓存与降级
- [x] Transient 缓存（12 小时 TTL）
- [x] 源健康状态缓存（1 小时 TTL）
- [x] 失败源冷却机制（30 分钟）
- [x] 过期缓存兜底（源不可用时返回旧数据）
- [x] 请求超时限制（10 秒）

#### 安全基础
- [x] URL 格式校验
- [x] API Key 加密存储
- [x] JSON 响应结构校验

#### 日志与错误处理
- [x] 调试日志（可开关）
- [x] 用户友好的错误信息

---

## v0.2.0 - Git 仓库支持 + WP-CLI + 性能优化 ✅

### 目标
支持 Git 仓库作为更新源，提供命令行工具，实现性能优化

### 任务清单

#### 性能优化（核心）
- [x] 并行请求（`ParallelRequestManager`）
- [x] 条件请求（ETag/Last-Modified）
- [x] WP-Cron 后台预热任务（`BackgroundUpdater`）
- [ ] 请求去重与合并窗口
- [ ] 缓存分层（对象缓存优先，DB 兜底）

#### WP-CLI 支持（`wp bridge`）
- [x] `wp bridge source list` - 列出所有源
- [x] `wp bridge source add <url>` - 添加源
- [x] `wp bridge source remove <id>` - 删除源
- [x] `wp bridge source enable/disable <id>` - 启用/禁用源
- [x] `wp bridge check` - 检查所有源
- [x] `wp bridge cache clear` - 清除缓存
- [x] `wp bridge diagnose` - 诊断报告
- [ ] `wp bridge config export/import` - 配置导入导出

#### Git 仓库支持
- [x] 统一接口 SourceHandlerInterface
- [x] GitHub Releases 支持（GitHubHandler）
- [x] GitLab Releases 支持（GitLabHandler）
- [x] Gitee Releases 支持（GiteeHandler，国内）
- [x] 菲码源库支持（WenPaiGitHandler）
- [x] 私有仓库认证

#### 诊断工具
- [x] 诊断页面（源状态、请求日志）
- [x] 一键测试源连通性
- [ ] 导出诊断报告

#### 配置管理
- [ ] 导入/导出配置
- [ ] 配置备份

#### 认证支持
- [x] API Key 认证
- [x] Basic Auth 认证
- [x] 自定义 HTTP 头

#### 源优先级
- [x] 源优先级设置
- [x] 多源冲突处理（版本号最高优先）

#### FAIR 支持
- [x] FAIR Package Manager 处理器（FairHandler）

---

## v0.3.0 - 源分组 + 商业插件检测 ✅

### 目标
支持源分组管理，实现商业插件检测

### 任务清单

#### 源分组管理
- [x] 源组数据模型（GroupModel）
- [x] 批量管理界面（GroupManager）
- [x] 共享认证信息
- [x] 统一启用/禁用

#### 商业插件适配
- [x] 商业插件检测机制（CommercialDetector）
- [x] 远程 JSON 配置支持
- [x] 检测结果永久缓存
- [x] 手动刷新检测功能
- [ ] 授权验证代理（可选）
- [ ] 版本锁定功能
- [ ] 回滚机制（更新前备份）

#### 通知系统
- [x] 邮件通知（EmailHandler）
- [x] Webhook 通知（WebhookHandler）
- [ ] 更新日志聚合显示

---

## v0.4.0 - Bridge API ✅

### 目标
提供 REST API 服务，支持外部调用

### 任务清单

#### Bridge API 基础
- [x] REST API 端点设计（`/wp-json/bridge/v1/`）
- [x] 认证机制（API Key）
- [x] 状态端点 `/status`
- [ ] 限流策略
- [ ] 可用性 SLA 定义

#### API 端点
- [x] GET /bridge/v1/status - 获取状态
- [ ] GET /bridge/v1/sources - 获取可用更新源列表
- [ ] GET /bridge/v1/check/{source_id} - 检查指定源更新
- [ ] GET /bridge/v1/plugins/{slug}/info - 获取插件信息
- [ ] GET /bridge/v1/themes/{slug}/info - 获取主题信息

---

## v0.8.0 - 配置导入导出 + 稳定性优化 ✅

### 目标
完善配置管理，提升稳定性

### 任务清单

#### 配置管理
- [x] 导入配置（JSON 格式）
- [x] 导出配置（JSON 格式）
- [x] 配置备份/恢复
- [x] WP-CLI 配置命令

#### 稳定性优化
- [x] 完善错误处理
- [x] 安全性检查（nonce、权限、输入清理）
- [ ] 添加单元测试（v1.0 前完成）
- [ ] 性能优化（请求去重）
- [ ] 缓存分层优化

---

## v0.9.0 - 版本控制 + 用户体验 ← 当前目标

### 目标
实现版本锁定和回滚机制，提升用户体验

### 任务清单

#### 版本锁定
- [ ] 锁定插件/主题到当前版本
- [ ] 锁定到指定版本
- [ ] 忽略特定版本更新
- [ ] 版本锁定 UI

#### 回滚机制
- [ ] 更新前自动备份
- [ ] 备份存储管理
- [ ] 一键回滚功能
- [ ] 保留最近 N 个版本

#### 更新日志聚合
- [ ] 从更新源获取 changelog
- [ ] 统一显示格式
- [ ] 在更新页面显示

#### Site Health 集成
- [ ] 更新源连通性检查
- [ ] 配置完整性检查
- [ ] 提供修复建议

#### Bridge API 完善
- [ ] GET /sources - 获取更新源列表
- [ ] GET /check/{source_id} - 检查指定源
- [ ] GET /plugins/{slug}/info - 获取插件信息
- [ ] GET /themes/{slug}/info - 获取主题信息

---

## v1.0.0 - 正式发布

### 目标
稳定版本，完善文档和用户体验

### 任务清单

#### 文档完善
- [x] 用户指南
- [ ] 开发者文档（如何创建兼容的更新服务器）
- [x] API 文档
- [x] 常见问题
- [ ] 视频教程

#### 用户体验
- [ ] 状态仪表板优化
- [ ] 性能基准测试报告

#### 高级功能（延后）
- [ ] 多站点 (Multisite) 支持
- [ ] 安全扫描集成（VirusTotal/Patchstack）
- [ ] 白标/OEM 支持

#### 发布准备
- [ ] GitHub Release
- [ ] 菲码源库 Release
- [ ] 更新日志
- [ ] 宣传材料

---

## AI 桥接层（暂缓）

> 以下功能暂缓开发，待核心功能稳定后考虑

### OpenAI 兼容层
- [ ] `pre_http_request` 拦截器
- [ ] 用户可配置白名单
- [ ] OpenAI Chat API 转发
- [ ] 响应格式转换
- [ ] 自定义端点支持（透传模式）
- [ ] WPMind 集成（可选）

### 商业插件 AI 适配
- [x] AI 网关基础（AIGateway）
- [x] Yoast SEO Pro 适配器
- [x] Rank Math 适配器
- [ ] 嗅探模式（收集 API 格式）

---

## 技术架构

```
wpbridge/
├── wpbridge.php                 # 主文件
├── includes/
│   ├── Core/
│   │   ├── Plugin.php           # 插件主类
│   │   ├── Loader.php           # 自动加载
│   │   ├── Settings.php         # 设置管理
│   │   ├── Logger.php           # 日志系统
│   │   ├── CommercialDetector.php # 商业插件检测
│   │   ├── RemoteConfig.php     # 远程配置
│   │   └── ItemSourceManager.php # 项目源管理
│   │
│   ├── UpdateSource/
│   │   ├── SourceManager.php    # 更新源管理
│   │   ├── SourceModel.php      # 数据模型
│   │   ├── SourceType.php       # 源类型枚举
│   │   ├── PluginUpdater.php    # 插件更新器
│   │   ├── ThemeUpdater.php     # 主题更新器
│   │   └── Handlers/            # 各类处理器
│   │       ├── JsonHandler.php
│   │       ├── GitHubHandler.php
│   │       ├── GitLabHandler.php
│   │       ├── GiteeHandler.php
│   │       ├── ArkPressHandler.php
│   │       ├── AspireCloudHandler.php
│   │       ├── FairHandler.php
│   │       └── ...
│   │
│   ├── SourceGroup/
│   │   ├── GroupManager.php     # 分组管理
│   │   └── GroupModel.php       # 分组模型
│   │
│   ├── Cache/
│   │   ├── CacheManager.php     # 缓存管理
│   │   ├── HealthChecker.php    # 健康检查
│   │   └── FallbackStrategy.php # 降级策略
│   │
│   ├── Performance/
│   │   ├── ParallelRequestManager.php # 并行请求
│   │   ├── ConditionalRequest.php     # 条件请求
│   │   └── BackgroundUpdater.php      # 后台更新
│   │
│   ├── API/
│   │   ├── RestController.php   # REST API
│   │   └── ApiKeyManager.php    # API Key 管理
│   │
│   ├── CLI/
│   │   └── BridgeCommand.php    # WP-CLI 命令
│   │
│   ├── Notification/
│   │   ├── NotificationManager.php
│   │   ├── EmailHandler.php
│   │   └── WebhookHandler.php
│   │
│   ├── AIBridge/
│   │   ├── AIGateway.php        # AI 网关
│   │   └── Adapters/
│   │       ├── YoastAdapter.php
│   │       └── RankMathAdapter.php
│   │
│   └── Admin/
│       └── AdminPage.php        # 管理页面
│
├── templates/
│   └── admin/
│       ├── main.php
│       └── tabs/
│           ├── overview.php
│           ├── sources.php
│           ├── diagnostics.php
│           └── api.php
│
└── assets/
    ├── css/
    └── js/
```

---

## 依赖关系

```
WPBridge 核心功能
├── 更新源桥接 - 无依赖，独立运行
├── 商业插件检测 - 无依赖，独立运行
├── Bridge API - 无依赖，独立运行
└── AI 桥接 - 可选依赖 WPMind（暂缓）
```

---

## 待讨论事项

- [x] 是否需要云端配置同步？→ 暂不需要，v1.0 后考虑
- [x] 是否支持多站点？→ 付费功能（v1.0.0）
- [x] 定价策略（免费 vs 付费）？→ 基础免费 + 高级付费
- [x] 与文派叶子的集成方式？→ 独立运行，检测 WPCY 存在时官方源走 WPCY
- [x] 预置更新源？→ 文派开源（默认）、ArkPress、AspireCloud、FAIR
- [x] WP-CLI 命令前缀？→ `wp bridge`
- [x] 自托管方案支持？→ ArkPress、AspireCloud、UpdatePulse Server、PUC 格式

---

*最后更新: 2026-02-05*
