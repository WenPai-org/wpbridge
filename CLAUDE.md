# WPBridge - 文派云桥

WordPress 自定义源桥接插件，为开发者和高级用户提供灵活的更新源和 AI 服务桥接能力。

## 项目信息

| 项目 | 值 |
|------|-----|
| 插件名称 | WPBridge |
| 中文名称 | 文派云桥 |
| 版本 | 1.0.0 |
| 开发目录 | `~/Projects/wpbridge/` |

## 治理规则

- **本文件上限 200 行**，超出须归档到 `docs/ai-context/`
- 交接记录：`docs/ai-context/HANDOFF.md`

## 核心定位

> 自定义源桥接器 - 让用户完全控制 WordPress 的外部连接

### 目标

- 自托管插件/主题更新服务器桥接
- 商业插件自定义更新源管理
- AI 服务请求桥接（可选）
- 面向开发者/高级用户

### 非目标

- 官方源镜像/加速（文派叶子的职责）
- 商业插件破解或绕过授权
- AI 模型本体（WPMind 的职责）
- WordPress 核心汉化（LitePress 的职责）

### 使用动机优先级

| 优先级 | 场景 | 用户类型 |
|--------|------|----------|
| P0 | 企业内网部署，私有仓库 | 企业用户 |
| P1 | 商业插件更新源管理 | 商业插件用户 |
| P2 | 开发测试，自托管服务器 | 开发者 |
| P3 | AI 服务桥接 | AI 插件用户 |

## 文派生态关系

```
文派叶子 WPCY  → 官方源加速（普通用户）
WPBridge 云桥  → 自定义源桥接（开发者/高级用户）
ArkPress       → 自托管更新服务器（服务端，与 WPBridge 配合）
WPMind 心思    → 纯 AI 应用（国内 AI 服务）
WPMirror       → 镜像源基础设施
LitePress      → WordPress 中国定制版
```

## 核心功能

### 更新源桥接
- 自托管插件/主题更新服务器
- 商业插件自定义更新源
- 私有仓库支持 (GitHub/GitLab)
- 更新源管理界面

### AI 桥接（可选）
- OpenAI API 兼容层
- 商业插件 AI 适配器 (Yoast/Rank Math)
- 依赖 WPMind 提供 AI 能力

### 高级配置
- 自定义 HTTP 头
- 认证方式配置 (API Key/OAuth/Basic)
- 代理设置

## 使用场景

1. **企业内网部署** - 配置内网更新服务器，私有插件/主题分发
2. **商业插件管理** - 统一管理多个商业插件的更新源
3. **开发测试** - 配置测试服务器，版本控制和回滚
4. **AI 服务替换** - 将 OpenAI 请求转发到国内服务

## 技术栈

- PHP 7.4+
- WordPress 5.9+
- 可选依赖：WPMind（AI 桥接功能）

## 相关文档

- [ROADMAP.md](ROADMAP.md) - 开发路线图
- [DISCUSSION.md](DISCUSSION.md) - 讨论记录
- [DESIGN.md](DESIGN.md) - 技术设计文档
- [ARCHITECTURE.md](ARCHITECTURE.md) - 业务流程与架构设计
- [RESEARCH.md](RESEARCH.md) - 市场研究报告
