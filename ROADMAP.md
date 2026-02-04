# WPBridge 开发路线图

> 自定义源桥接器 - 版本规划和开发任务

*创建日期: 2026-02-04*

---

## 版本规划

```
v0.1.0 - MVP：最小可用桥接 + 基础缓存/降级
    ↓
v0.2.0 - Git 仓库支持 + WP-CLI + 性能优化
    ↓
v0.3.0 - AI 桥接层 + 源分组 + 高级功能
    ↓
v0.4.0 - Cloud API（可选）
    ↓
v1.0.0 - 正式发布
```

---

## v0.1.0 - MVP：最小可用桥接 + 基础缓存/降级

### 目标
实现最小可用的更新源桥接功能，确保基础稳定性

### 范围限定
- ✅ 核心桥接功能（JSON API + 预置源）
- ✅ 基础缓存和降级
- ✅ 简单管理界面
- ❌ 不含 Git 仓库支持（移至 v0.2.0）
- ❌ 不含 WP-CLI（移至 v0.2.0）
- ❌ 不含诊断工具（移至 v0.2.0）

### 任务清单

#### 插件基础结构
- [ ] 主文件 `wpbridge.php`
- [ ] 自动加载器
- [ ] 设置页面框架
- [ ] 数据存储结构

#### 预置更新源
- [ ] 文派开源更新源（默认启用）
- [ ] ArkPress 支持（文派自托管方案）
- [ ] AspireCloud 支持（可选）

#### 更新源管理（基础）
- [ ] 更新源数据模型（使用统一 SourceType 枚举）
- [ ] 更新源 CRUD 操作
- [ ] 更新源列表界面
- [ ] 添加/编辑更新源表单

#### 核心桥接功能
- [ ] `pre_set_site_transient_update_plugins` 钩子
- [ ] `pre_set_site_transient_update_themes` 钩子
- [ ] JSON API 处理器（JsonHandler）
- [ ] ArkPress 处理器（ArkPressHandler）
- [ ] AspireCloud 处理器（AspireCloudHandler）
- [ ] Plugin Update Checker JSON 格式兼容

#### 基础缓存与降级
- [ ] Transient 缓存（12 小时 TTL）
- [ ] 源健康状态缓存（1 小时 TTL）
- [ ] 失败源冷却机制（30 分钟）
- [ ] 过期缓存兜底（源不可用时返回旧数据）
- [ ] 请求超时限制（10 秒）

#### 安全基础
- [ ] URL 格式校验
- [ ] API Key 加密存储
- [ ] JSON 响应结构校验

#### 日志与错误处理
- [ ] 调试日志（可开关）
- [ ] 用户友好的错误信息

---

## v0.2.0 - Git 仓库支持 + WP-CLI + 性能优化

### 目标
支持 Git 仓库作为更新源，提供命令行工具，实现性能优化

### 任务清单

#### 性能优化（核心）
- [ ] 并行请求（`Requests::request_multiple`）
- [ ] 请求去重与合并窗口
- [ ] 条件请求（ETag/Last-Modified）
- [ ] 缓存分层（对象缓存优先，DB 兜底）
- [ ] WP-Cron 后台预热任务

#### WP-CLI 支持（`wp bridge`）
- [ ] `wp bridge source list` - 列出所有源
- [ ] `wp bridge source add <url>` - 添加源
- [ ] `wp bridge source remove <id>` - 删除源
- [ ] `wp bridge source enable/disable <id>` - 启用/禁用源
- [ ] `wp bridge check` - 检查所有源
- [ ] `wp bridge cache clear` - 清除缓存
- [ ] `wp bridge diagnose` - 诊断报告
- [ ] `wp bridge config export/import` - 配置导入导出

#### Git 仓库支持
- [ ] 统一接口 SourceHandlerInterface
- [ ] GitHub Releases 支持（GitHubHandler）
- [ ] GitLab Releases 支持（GitLabHandler）
- [ ] Gitee Releases 支持（GiteeHandler，国内）
- [ ] 菲码源库支持（WenPaiGitHandler）
- [ ] 私有仓库认证

#### 诊断工具
- [ ] 诊断页面（源状态、请求日志）
- [ ] 一键测试源连通性
- [ ] 导出诊断报告

#### 配置管理
- [ ] 导入/导出配置
- [ ] 配置备份

#### 认证支持
- [ ] API Key 认证
- [ ] Basic Auth 认证
- [ ] 自定义 HTTP 头

#### 源优先级
- [ ] 源优先级设置
- [ ] 多源冲突处理（版本号最高优先）

#### FAIR 支持
- [ ] FAIR Package Manager 处理器（FairHandler）

---

## v0.3.0 - AI 桥接层 + 源分组 + 商业插件

### 目标
实现 OpenAI API 兼容层和商业插件 AI 适配，支持源分组管理

### 任务清单

#### 源分组管理
- [ ] 源组数据模型
- [ ] 批量管理界面
- [ ] 共享认证信息
- [ ] 统一启用/禁用

#### 商业插件适配
- [ ] 商业插件检测机制
- [ ] 更新源覆盖逻辑
- [ ] 授权验证代理（可选）
- [ ] 版本锁定功能
- [ ] 回滚机制（更新前备份）

#### 预置配置框架
- [ ] 通用配置模板
- [ ] EDD Software Licensing 兼容
- [ ] WooCommerce Licensing 兼容

#### 通知系统
- [ ] 邮件通知（可选）
- [ ] Webhook 通知（面向开发者）
- [ ] 更新日志聚合显示

#### OpenAI 兼容层
- [ ] `pre_http_request` 拦截器
- [ ] 用户可配置白名单
- [ ] OpenAI Chat API 转发
- [ ] 响应格式转换
- [ ] 自定义端点支持（透传模式）
- [ ] WPMind 集成（可选）

#### 商业插件 AI 适配
- [ ] 嗅探模式（收集 API 格式）
- [ ] Yoast SEO Pro 适配器
- [ ] Rank Math 适配器

#### 设置界面
- [ ] AI 桥接开关
- [ ] 模式选择（禁用/透传/WPMind）
- [ ] 白名单管理
- [ ] 适配器管理

#### 无 WPMind 时的行为
- [ ] 透传到用户指定端点
- [ ] 清晰的功能状态提示

---

## v0.4.0 - Cloud API（可选）

### 目标
提供云端 API 服务，支持无需安装插件的场景

### 任务清单

#### Cloud API 基础
- [ ] REST API 端点设计
- [ ] 认证机制（API Key）
- [ ] 限流策略
- [ ] 可用性 SLA 定义

#### API 端点
- [ ] GET /api/v1/sources - 获取可用更新源列表
- [ ] GET /api/v1/check/{source_id} - 检查指定源更新
- [ ] GET /api/v1/plugins/{slug}/info - 获取插件信息
- [ ] GET /api/v1/themes/{slug}/info - 获取主题信息
- [ ] GET /api/v1/wenpai-git/{repo}/releases - 菲码源库 Release

#### 文派叶子集成
- [ ] WPCY 调用 WPBridge API 示例
- [ ] 菲码源库集成文档

---

## v1.0.0 - 正式发布

### 目标
稳定版本，完善文档和用户体验

### 任务清单

#### 文档完善
- [ ] 用户指南
- [ ] 开发者文档（如何创建兼容的更新服务器）
- [ ] API 文档
- [ ] 常见问题
- [ ] 视频教程

#### 用户体验
- [ ] 设置向导
- [ ] 状态仪表板
- [ ] 性能基准测试报告
- [ ] WordPress Site Health 集成

#### 高级功能（付费）
- [ ] 多站点 (Multisite) 支持
- [ ] 安全扫描集成（VirusTotal/Patchstack）
- [ ] 白标/OEM 支持
- [ ] 优先技术支持

#### 发布准备
- [ ] GitHub Release
- [ ] 菲码源库 Release
- [ ] 更新日志
- [ ] 宣传材料

---

## 技术架构

```
wpbridge/
├── wpbridge.php                 # 主文件
├── includes/
│   ├── Core/
│   │   ├── Plugin.php           # 插件主类
│   │   ├── Loader.php           # 自动加载
│   │   └── Settings.php         # 设置管理
│   │
│   ├── UpdateSource/
│   │   ├── SourceManager.php    # 更新源管理
│   │   ├── SourceModel.php      # 数据模型
│   │   ├── PluginUpdater.php    # 插件更新器
│   │   └── ThemeUpdater.php     # 主题更新器
│   │
│   ├── AIBridge/
│   │   ├── AIGateway.php        # AI 网关
│   │   ├── OpenAIProxy.php      # OpenAI 代理
│   │   └── Adapters/
│   │       ├── YoastAdapter.php
│   │       └── RankMathAdapter.php
│   │
│   └── Admin/
│       ├── AdminPage.php        # 管理页面
│       └── SourceEditor.php     # 更新源编辑器
│
├── templates/
│   └── admin/
│       ├── settings.php
│       └── source-editor.php
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
├── 商业插件更新 - 无依赖，独立运行
└── AI 桥接 - 可选依赖 WPMind
              └── 无 WPMind 时：仅支持 OpenAI 兼容层
              └── 有 WPMind 时：支持国内 AI 服务商
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

*最后更新: 2026-02-04*
