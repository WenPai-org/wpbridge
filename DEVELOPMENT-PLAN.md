# WPBridge 开发计划

> 完整的开发计划和任务分解

*创建日期: 2026-02-04*

---

## 一、项目概述

### 1.1 项目定位

**WPBridge（文派云桥）** - 自定义源桥接器，让用户完全控制 WordPress 的外部连接。

### 1.2 核心价值

1. **自定义更新源桥接** - 支持自托管更新服务器、商业插件更新源
2. **性能优化** - 并行请求、智能缓存、减少后台加载时间
3. **AI 服务桥接** - OpenAI API 兼容层，可选依赖 WPMind

### 1.3 非目标

- 不替代 WordPress.org 官方源（由文派叶子 WPCY 负责）
- 不提供镜像/CDN 服务
- 不破解/绕过商业插件授权

---

## 二、版本规划

```
v0.1.0 MVP（2-3 周）
    ↓
v0.2.0 性能优化 + Git 支持（2-3 周）
    ↓
v0.3.0 AI 桥接 + 商业插件（2-3 周）
    ↓
v0.4.0 Cloud API（可选，1-2 周）
    ↓
v1.0.0 正式发布（1-2 周）
```

---

## 三、v0.1.0 MVP 详细计划

### 3.1 目标

实现最小可用的更新源桥接功能，确保基础稳定性。

### 3.2 范围

| 包含 | 不包含（移至后续版本）|
|------|----------------------|
| JSON API 桥接 | Git 仓库支持 |
| 预置源（文派开源、ArkPress、AspireCloud）| WP-CLI |
| 基础缓存和降级 | 诊断工具 |
| 简单管理界面 | 配置导入导出 |
| 安全基础 | FAIR 支持 |

### 3.3 任务分解

#### 阶段 1：插件骨架（Day 1-2）

```
任务 1.1: 创建插件主文件
- wpbridge.php（插件头信息、激活/停用钩子）
- 预计：2 小时

任务 1.2: 自动加载器
- includes/Core/Loader.php
- PSR-4 风格自动加载
- 预计：1 小时

任务 1.3: 插件主类
- includes/Core/Plugin.php
- 单例模式，初始化各模块
- 预计：2 小时

任务 1.4: 设置管理
- includes/Core/Settings.php
- wp_options 读写封装
- 预计：2 小时
```

#### 阶段 2：数据模型（Day 3-4）

```
任务 2.1: 源类型枚举
- includes/UpdateSource/SourceType.php
- 统一定义所有源类型
- 预计：1 小时

任务 2.2: 更新源模型
- includes/UpdateSource/SourceModel.php
- 数据结构、验证、序列化
- 预计：2 小时

任务 2.3: 源管理器
- includes/UpdateSource/SourceManager.php
- CRUD 操作、预置源加载
- 预计：3 小时

任务 2.4: 预置源配置
- includes/UpdateSource/PresetSources.php
- 文派开源、ArkPress、AspireCloud
- 预计：2 小时
```

#### 阶段 3：核心桥接（Day 5-8）

```
任务 3.1: 处理器接口
- includes/UpdateSource/Handlers/HandlerInterface.php
- 统一接口定义
- 预计：1 小时

任务 3.2: JSON 处理器
- includes/UpdateSource/Handlers/JsonHandler.php
- Plugin Update Checker 格式兼容
- 预计：3 小时

任务 3.3: ArkPress 处理器
- includes/UpdateSource/Handlers/ArkPressHandler.php
- AspireCloud API 兼容
- 预计：3 小时

任务 3.4: AspireCloud 处理器
- includes/UpdateSource/Handlers/AspireCloudHandler.php
- 预计：2 小时

任务 3.5: 插件更新器
- includes/UpdateSource/PluginUpdater.php
- pre_set_site_transient_update_plugins 钩子
- plugins_api 钩子
- 预计：4 小时

任务 3.6: 主题更新器
- includes/UpdateSource/ThemeUpdater.php
- pre_set_site_transient_update_themes 钩子
- themes_api 钩子
- 预计：3 小时
```

#### 阶段 4：缓存与降级（Day 9-10）

```
任务 4.1: 缓存管理器
- includes/Cache/CacheManager.php
- Transient 缓存封装
- 预计：2 小时

任务 4.2: 源健康检查
- includes/Cache/HealthChecker.php
- 连通性测试、状态缓存
- 预计：2 小时

任务 4.3: 降级策略
- includes/Cache/FallbackStrategy.php
- 过期缓存兜底、失败冷却
- 预计：2 小时
```

#### 阶段 5：安全与日志（Day 11-12）

```
任务 5.1: 输入校验
- includes/Security/Validator.php
- URL 格式、版本号、JSON 结构
- 预计：2 小时

任务 5.2: 密钥加密
- includes/Security/Encryption.php
- API Key 加密存储
- 预计：2 小时

任务 5.3: 日志系统
- includes/Core/Logger.php
- 调试日志、错误日志
- 预计：2 小时
```

#### 阶段 6：管理界面（Day 13-15）

```
任务 6.1: 管理页面
- includes/Admin/AdminPage.php
- 设置页面注册
- 预计：2 小时

任务 6.2: 源列表界面
- templates/admin/source-list.php
- WP_List_Table 实现
- 预计：3 小时

任务 6.3: 源编辑表单
- templates/admin/source-editor.php
- 添加/编辑更新源
- 预计：3 小时

任务 6.4: 样式和脚本
- assets/css/admin.css
- assets/js/admin.js
- 预计：2 小时
```

### 3.4 文件结构

```
wpbridge/
├── wpbridge.php                    # 主文件
├── includes/
│   ├── Core/
│   │   ├── Plugin.php              # 插件主类
│   │   ├── Loader.php              # 自动加载
│   │   ├── Settings.php            # 设置管理
│   │   └── Logger.php              # 日志系统
│   │
│   ├── UpdateSource/
│   │   ├── SourceType.php          # 源类型枚举
│   │   ├── SourceModel.php         # 数据模型
│   │   ├── SourceManager.php       # 源管理器
│   │   ├── PresetSources.php       # 预置源配置
│   │   ├── PluginUpdater.php       # 插件更新器
│   │   ├── ThemeUpdater.php        # 主题更新器
│   │   └── Handlers/
│   │       ├── HandlerInterface.php
│   │       ├── JsonHandler.php
│   │       ├── ArkPressHandler.php
│   │       └── AspireCloudHandler.php
│   │
│   ├── Cache/
│   │   ├── CacheManager.php        # 缓存管理
│   │   ├── HealthChecker.php       # 健康检查
│   │   └── FallbackStrategy.php    # 降级策略
│   │
│   ├── Security/
│   │   ├── Validator.php           # 输入校验
│   │   └── Encryption.php          # 密钥加密
│   │
│   └── Admin/
│       └── AdminPage.php           # 管理页面
│
├── templates/
│   └── admin/
│       ├── source-list.php
│       └── source-editor.php
│
└── assets/
    ├── css/
    │   └── admin.css
    └── js/
        └── admin.js
```

### 3.5 验收标准

1. **功能验收**
   - [ ] 可添加自定义 JSON 更新源
   - [ ] 预置源（文派开源）可正常检查更新
   - [ ] 更新信息正确显示在 WordPress 后台
   - [ ] 可下载并安装更新

2. **稳定性验收**
   - [ ] 源不可用时不阻塞后台
   - [ ] 缓存正常工作
   - [ ] 错误信息用户友好

3. **安全验收**
   - [ ] URL 格式校验有效
   - [ ] API Key 加密存储
   - [ ] 无 XSS/SQL 注入风险

---

## 四、v0.2.0 性能优化 + Git 支持

### 4.1 目标

实现性能优化核心功能，支持 Git 仓库作为更新源。

### 4.2 关键任务

#### 性能优化
- [ ] 并行请求管理器（ParallelRequestManager）
- [ ] 请求去重器（RequestDeduplicator）
- [ ] 条件请求（ConditionalRequest）
- [ ] 缓存分层（对象缓存 + DB）
- [ ] WP-Cron 后台预热（BackgroundUpdater）

#### Git 仓库支持
- [ ] GitHub 处理器（GitHubHandler）
- [ ] GitLab 处理器（GitLabHandler）
- [ ] Gitee 处理器（GiteeHandler）
- [ ] 菲码源库处理器（WenPaiGitHandler）
- [ ] 私有仓库认证

#### WP-CLI
- [ ] `wp bridge source` 命令组
- [ ] `wp bridge check` 命令
- [ ] `wp bridge cache` 命令
- [ ] `wp bridge diagnose` 命令
- [ ] `wp bridge config` 命令

#### 诊断工具
- [ ] 诊断页面
- [ ] 源连通性测试
- [ ] 诊断报告导出

---

## 五、v0.3.0 AI 桥接 + 商业插件

### 5.1 目标

实现 AI 服务桥接和商业插件支持。

### 5.2 关键任务

#### AI 桥接
- [ ] AI 设置数据模型（AISettings）
- [ ] AI 桥接主类（AIBridge）
- [ ] OpenAI 代理（OpenAIProxy）
- [ ] WPMind 转发器（WPMindForwarder）
- [ ] 白名单管理界面

#### 商业插件
- [ ] 商业插件检测
- [ ] 更新源覆盖
- [ ] 版本锁定
- [ ] 回滚机制

#### 源分组
- [ ] 源组数据模型
- [ ] 批量管理界面

---

## 六、v0.4.0 Cloud API

### 6.1 目标

提供云端 API 服务。

### 6.2 关键任务

- [ ] REST API 端点
- [ ] 认证机制
- [ ] 限流策略
- [ ] 文派叶子集成示例

---

## 七、v1.0.0 正式发布

### 7.1 目标

稳定版本，完善文档和用户体验。

### 7.2 关键任务

- [ ] 用户指南
- [ ] 开发者文档
- [ ] API 文档
- [ ] 设置向导
- [ ] 状态仪表板
- [ ] GitHub Release
- [ ] 菲码源库 Release

---

## 八、技术规范

### 8.1 编码规范

- PHP 7.4+ 兼容
- WordPress 编码标准
- PSR-4 自动加载
- 类型声明（PHP 7.4 风格）

### 8.2 测试要求

- 单元测试覆盖核心逻辑
- 集成测试覆盖 WordPress 钩子
- 手动测试覆盖 UI 交互

### 8.3 安全要求

- 所有用户输入必须校验
- 敏感数据加密存储
- 遵循 WordPress 安全最佳实践

---

## 九、风险与缓解

| 风险 | 影响 | 缓解措施 |
|------|------|----------|
| 第三方 API 变更 | 处理器失效 | 模块化设计，易于更新 |
| 性能问题 | 后台卡顿 | 缓存优先，异步处理 |
| 安全漏洞 | 数据泄露 | 代码审计，安全测试 |
| 兼容性问题 | 插件冲突 | 最小化钩子使用，命名空间隔离 |

---

## 十、里程碑

| 里程碑 | 目标日期 | 交付物 |
|--------|----------|--------|
| M1: MVP 完成 | +3 周 | v0.1.0 可用版本 |
| M2: 性能优化 | +6 周 | v0.2.0 性能版本 |
| M3: AI 桥接 | +9 周 | v0.3.0 完整版本 |
| M4: 正式发布 | +12 周 | v1.0.0 稳定版本 |

---

*最后更新: 2026-02-04*
