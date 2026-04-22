# Session Handoff — WPBridge

## Quick Status
- **Version**: 1.0.0 (deployed to wpcy.com)
- **Phase**: 发布前最终打磨，UI 重构基本完成
- **Last Session**: 2026-03-11 — UI 全面打磨 + 供应商链路验证
- **Blocking Issues**: 无

## What Was Done (2026-03-10~11)

### UI 全面重设计

**Tab 结构调整**:
- 诊断 Tab 砍掉，源测试合入更新管理→更新源子标签
- Tab 排序：概览 → 供应商 → 更新管理 → Bridge API → 设置
- 子标签排序：更新源（默认）→ 插件 → 主题
- Tab 切换加 fadein 动画减少割裂感

**API Tab 简化**:
- 删除 Bridge Server 配置（由供应商 Tab 管理）
- 添加 Hub-Spoke 说明文案（付费功能卖点）
- API 端点仅在启用时显示

**设置页重构**: 基础设置 + 维护工具 + 配置导入导出，debug_mode 独立 toggle

**更新源列表重设计**:
- 分组显示（预设源 / 供应商 / 自定义源），灰色标题区分
- 外框 border + border-radius 包裹
- 禁用源半透明，统计显示启用数
- 重复源检测（ajax_add_source 检查 api_url 是否已存在）

**视觉统一**:
- border-radius 全局治理：清理所有硬编码值，统一到 4 档变量（sm/default/md/lg）
- 按钮、badge、input、toggle、section、panel 全部加 border-radius
- diagnostics.css 删除，样式迁移到 projects.css
- modal CSS 修复（confirm/prompt/alert 不再全屏拉伸）
- API Key 前缀统一为 `wpb_`

**Bug 修复**:
- JS 语法错误：删除诊断代码时残留 2 行闭合语句导致全站 JS 不可用
- debug_mode 保存：`! empty('0')` 为 true 的陷阱，改为 `=== '1'`
- backup_enabled 未持久化
- 概览页 6 处链接指向修正（stale `action=add` 和 `#diagnostics`）
- data-tab-link 支持 data-subtab 联动跳转

### 供应商→Source 链路验证

Plan 中 6 步全部已实现：

| Step | 功能 | 关键文件 |
|------|------|----------|
| 1 | VendorHandler 适配器 | `Handlers/VendorHandler.php` |
| 2 | 激活/停用自动注册 SourceRegistry | `VendorAdmin.php:register/unregister_vendor_source()` |
| 3 | SourceType::VENDOR + SourceResolver 映射 | `SourceType.php` + `SourceResolver.php` |
| 4 | 可用插件"接管更新" toggle | `vendors.php` + `ajax_bind_vendor_update()` |
| 5 | 项目 Tab 供应商状态标签 | `project-list-plugins.php:140-155` |
| 6 | 默认规则自动包含 | `SourceRegistry::get_enabled()` 自动 |

## What's Next
- [ ] 发版前剩余讨论事项
- [ ] code review 遗留：重复 escapeHtml 合并、批量 AJAX 并发控制
- [ ] 端到端实际测试：激活供应商→WP 更新页面是否正确显示商业插件更新
- [ ] wenpai.net 商店搭建后的 WooCommerce vendor 联调

## Key Architecture

```
激活供应商 → register_vendor_source() → SourceRegistry
                                              ↓
WP 更新检查 → PluginUpdater → SourceResolver → VendorHandler → VendorManager → WC AM API
                                              ↓
项目 Tab ← ItemSourceManager ← "接管更新" toggle
```

**CSS 设计体系**: 8 个模块文件（variables → base → components → sources → projects → overview → modals → vendors → responsive），border-radius 4 档（sm:2px / default:4px / md:6px / lg:8px）

## Known Issues
- wpcy.com 使用 SQLite，WPSlug 触发 readonly 写入错误（与 WPBridge 无关）
- 2 个纯中文产品 slug 待商城侧修复标题
