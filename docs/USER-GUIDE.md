# WPBridge 用户指南

> 自定义源桥接器 - 让用户完全控制 WordPress 的外部连接

## 目录

- [简介](#简介)
- [安装](#安装)
- [快速开始](#快速开始)
- [更新源管理](#更新源管理)
- [商业插件检测](#商业插件检测)
- [配置导入导出](#配置导入导出)
- [WP-CLI 命令](#wp-cli-命令)
- [Bridge API](#bridge-api)
- [常见问题](#常见问题)

---

## 简介

WPBridge（文派云桥）是一个 WordPress 插件，允许您配置自定义的插件和主题更新源。

### 主要功能

- **自定义更新源**：支持 JSON API、GitHub、GitLab、Gitee 等多种更新源
- **商业插件管理**：自动检测商业插件，支持自定义更新源
- **源分组**：批量管理多个更新源
- **Bridge API**：提供 REST API 供外部调用
- **WP-CLI 支持**：命令行管理更新源

### 适用场景

- 企业内网部署，需要私有更新服务器
- 商业插件用户，需要统一管理更新源
- 开发者测试环境
- 需要 AI 服务桥接的用户

---

## 安装

### 要求

- WordPress 5.9+
- PHP 7.4+

### 安装步骤

1. 下载插件 ZIP 文件
2. 在 WordPress 后台进入「插件 > 安装插件 > 上传插件」
3. 上传并激活插件
4. 进入「设置 > WPBridge」配置插件

---

## 快速开始

### 1. 访问设置页面

激活插件后，在 WordPress 后台菜单找到「设置 > WPBridge」。

### 2. 查看概览

概览页面显示：
- 已配置的更新源数量
- 源健康状态
- 最近的更新检查

### 3. 添加更新源

1. 点击「更新源」标签
2. 点击「添加更新源」按钮
3. 填写更新源信息：
   - **名称**：更新源的显示名称
   - **类型**：JSON API / GitHub / GitLab / Gitee 等
   - **URL**：更新源的 API 地址
   - **项目类型**：插件或主题
4. 点击「保存」

---

## 更新源管理

### 支持的更新源类型

| 类型 | 说明 | URL 格式 |
|------|------|----------|
| JSON API | 标准 JSON 格式 | `https://example.com/updates.json` |
| GitHub | GitHub Releases | `https://github.com/owner/repo` |
| GitLab | GitLab Releases | `https://gitlab.com/owner/repo` |
| Gitee | Gitee Releases | `https://gitee.com/owner/repo` |
| ArkPress | 文派自托管方案 | `https://api.example.com/v1` |
| AspireCloud | AspireCloud 服务 | `https://api.aspirecloud.com` |
| PUC | Plugin Update Checker | `https://example.com/plugin-info.json` |

### 更新源优先级

当多个更新源提供同一插件的更新时，WPBridge 会：
1. 按优先级排序（数字越小优先级越高）
2. 选择版本号最高的更新

### 认证配置

对于需要认证的更新源：
1. 在更新源设置中填写 API Token
2. 支持 API Key、Basic Auth、自定义 HTTP 头

---

## 商业插件检测

WPBridge 可以自动检测已安装的商业插件。

### 检测方式

1. **远程配置**：从云端获取已知商业插件列表
2. **WordPress.org 检查**：不在官方目录的插件标记为第三方
3. **手动标记**：用户可手动设置插件类型

### 插件类型

- **免费**：WordPress.org 官方目录中的插件
- **商业**：已知的商业插件
- **第三方**：不在官方目录的其他插件

### 刷新检测

点击「刷新检测」按钮可重新检测所有插件类型。

---

## 配置导入导出

### 导出配置

1. 进入「设置」标签
2. 在「配置导入导出」区域点击「导出」
3. 可选择是否包含敏感信息（API Key 等）
4. 下载 JSON 配置文件

### 导入配置

1. 点击「导入」按钮
2. 选择之前导出的 JSON 文件
3. 选择导入模式：
   - **合并**：与现有配置合并
   - **覆盖**：完全替换现有配置
4. 确认导入

---

## WP-CLI 命令

WPBridge 提供完整的 WP-CLI 支持。

### 更新源管理

```bash
# 列出所有更新源
wp bridge source list

# 添加更新源
wp bridge source add https://example.com/updates.json --name="My Source"

# 删除更新源
wp bridge source remove <source_id>

# 启用/禁用更新源
wp bridge source enable <source_id>
wp bridge source disable <source_id>
```

### 缓存管理

```bash
# 清除缓存
wp bridge cache clear

# 查看缓存状态
wp bridge cache status
```

### 诊断

```bash
# 检查所有更新源
wp bridge check

# 运行诊断
wp bridge diagnose
```

### 配置管理

```bash
# 导出配置
wp bridge config export /path/to/config.json

# 导入配置
wp bridge config import /path/to/config.json
```

---

## Bridge API

WPBridge 提供 REST API 供外部调用。

### 启用 API

1. 进入「API」标签
2. 点击「生成 API Key」
3. 保存生成的 Key（只显示一次）

### API 端点

```
GET /wp-json/bridge/v1/status
```

返回插件状态信息。

### 认证

在请求头中添加：
```
X-WPBridge-Key: your_api_key
```

---

## 常见问题

### Q: 更新源不工作怎么办？

1. 检查更新源 URL 是否正确
2. 在「诊断」页面测试源连通性
3. 检查是否需要认证信息
4. 查看调试日志（需启用调试模式）

### Q: 如何处理商业插件更新？

1. WPBridge 会自动检测商业插件
2. 您可以为商业插件配置自定义更新源
3. 或者手动标记插件类型

### Q: 配置丢失怎么恢复？

1. 如果有备份，使用「导入配置」功能恢复
2. 如果没有备份，需要重新配置

### Q: 如何与文派叶子配合使用？

WPBridge 会自动检测文派叶子（WPCY）的存在：
- 官方源更新走 WPCY 加速
- 自定义源走 WPBridge 配置

---

## 获取帮助

- **文档**：https://wenpai.org/docs/wpbridge
- **问题反馈**：https://github.com/ArkPress/wpbridge/issues
- **社区支持**：https://wenpai.org/community

---

*最后更新: 2026-02-05*
