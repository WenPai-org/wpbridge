# 订阅状态 UI 与功能门控设计

> 日期: 2026-03-09
> 状态: 已批准

## 概述

在 WPBridge 后台设置界面适配订阅功能：薇晓朵卡片内嵌订阅状态，Bridge API / Bridge Server 等付费功能模块对 free 用户禁用变灰。

## 设计决策

- **展示位置**: 薇晓朵预设卡片内嵌（不新增独立区域）
- **信息粒度**: 简洁模式 — 订阅等级标签 + 刷新按钮，不展开功能列表
- **门控方式**: disabled 属性 + 灰显样式 + 文字提示，不阻断页面渲染

## 改动文件

### 1. `templates/admin/main.php`
- 数据准备区新增 `$subscription` 变量（从 `$bridge_manager->get_subscription()` 获取）
- 所有 tab 共享该变量

### 2. `templates/admin/tabs/vendors.php`
- 薇晓朵卡片已激活时，在 desc 和 actions 之间插入订阅状态行：
  ```
  订阅: [全站通]  [↻ 刷新]
  ```
- 通过 `$preset['subscription_vendor']` 标记判断是否渲染
- Bridge API "添加连接"按钮：free 用户 disabled + 显示"需要 Pro"

### 3. `templates/admin/tabs/settings.php`
- Bridge Server 配置区（服务端地址/API Key/测试连接）：
  - free 用户时 input disabled、button disabled
  - 标题旁显示"需要 Pro"标签

### 4. `templates/admin/tabs/api.php`
- 顶部插入提示栏：`Bridge API 需要 Pro 及以上订阅`（仅 free 用户）
- 所有开关/输入/按钮 disabled
- 提示栏用 `.wpbridge-upgrade-notice` 样式

### 5. `assets/css/vendors.css`
- `.wpbridge-subscription-status` — 订阅状态行（flex 布局，badge 样式）
- `.wpbridge-feature-locked` — 灰显容器（opacity + pointer-events: none）
- `.wpbridge-upgrade-notice` — 提示栏（warning 配色，圆角）
- `.wpbridge-subscription-badge` — 等级标签（复用 active badge 配色）
- `.wpbridge-refresh-subscription` — 刷新按钮样式

### 6. `assets/js/admin.js`
- 刷新按钮点击 → AJAX `wpbridge_refresh_subscription` → 更新 badge 文本
- 预设激活成功回调中渲染订阅状态行（后端已返回 subscription 数据）

## 功能门控矩阵

| 功能 | Free | Pro+ | 门控位置 |
|------|------|------|----------|
| Bridge API Tab | 禁用变灰 | 正常 | api.php |
| Bridge Server 配置 | 禁用变灰 | 正常 | settings.php |
| Bridge API 添加连接 | 禁用变灰 | 正常 | vendors.php |
| 更新源/供应商/项目 | 正常 | 正常 | 无门控 |

## 不做 (YAGNI)

- 概览 Tab 快速入口加锁
- License Proxy UI（功能未实现）
- 升级引导/购买链接
- Tooltip 悬停详情
