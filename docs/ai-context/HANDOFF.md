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

## 2026-08-09 — [CX] FeiCode 真源全功能审计与修复

- 基线 `feicode-ssh/main@a27bd828c97e8999b205459ea1ca0af8a6873c81`；隔离分支 `codex/wpbridge-audit-20260809`，worktree `/home/parallels/Projects/wpbridge-codex-audit-20260809`。
- 修复：双数据模型源开关、供应商 ID 迁移范围/顺序、远程 changelog 转义、自更新降级/非 HTTPS 包、注册表 token 明文、卸载遗留、PHP 7.4 兼容和发布元数据。
- 新增 `tests/run-tests.sh`、updater/仓库契约测试、`readme.txt`、`LICENSE`；完整审计见 `docs/audits/wpbridge-full-2026-08-09.md`。
- 验证：`npm test` exit 0；WordPress 7.0.3/PHP 8.3/MariaDB 激活、迁移、加密凭据和双存储 AJAX toggle exit 0。最终发布目录 Plugin Check 报 29 errors/396 warnings（命令 exit 0，NOT PASS）；PHPCS exit 2 / 1751 errors；PHPStan 1G exit 1 / 81 errors；gitleaks 和 npm audit exit 0。
- 未完成：PHP 7.4/WP 5.9 多版本、multisite、浏览器 E2E、真实 Bridge/供应商联调、原子回滚、SSRF 私网 allowlist、单一 SourceRegistry 写模型。
- FeiCode 拒绝直接 push 新分支（账号仅允许 AGit PR refs）；未擅自创建 PR，提交保留在 VM 隔离 worktree。
- 未部署、未发布、未合并默认分支；共享目录原有未跟踪迁移和 CI 文件未覆盖。

## 2026-08-09 — [CX] 第二轮 Plugin Check/PHPStan/兼容矩阵

- 隔离 worktree/分支不变：`/home/parallels/Projects/wpbridge-codex-audit-20260809`，`codex/wpbridge-audit-20260809`；共享 `/home/parallels/Projects/wpbridge` 未改。
- Plugin Check 发布目录：2 个私有发行 policy errors / 394 warnings；27 个原代码 error 已修。忽略精确 policy code 的私有分发 profile 为 0 error result。PHPCS 仍 FAIL（1354/33）。
- PHPStan level 3 全仓 0 error；`npm test` exit 0。
- PHP 7.4.33 + WP 5.9 激活通过；WP 7.0.3 两站网络激活与卸载通过；Playwright 迁移 E2E 1/1。
- 本地 mock：Bridge/供应商/降级包 14/14；密钥轮换/失败关闭 3/3；回滚 2/2。未接生产服务。
- 新增历史密钥环 `WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS`；不可解密返回空值并触发 `wpbridge_decryption_failed`，不回退明文。
- 仍未完成：PHPCS、394 warnings、原子回滚、请求期 DNS/SSRF 复核、未来新增 multisite 站点初始化、真实生产凭据 Bridge/WooCommerce 联调（生产联调不在本轮授权内）。
- 完整命令、分类和范围见 `docs/audits/wpbridge-full-2026-08-09.md` 第 6 节。

- [CX] 第二轮代码提交 `c23c66f1380582154dc9ce278dd5d8c62dad01a5` 已通过 AGit refs 更新 FeiCode PR [#4](https://feicode.com/WenPai-org/wpbridge/pulls/4)；未合并、未部署、未发布。

## 2026-08-09 — [CX] 第三轮 SSRF/原子回滚/multisite

- [CX] 隔离 worktree/分支和 PR #4 不变；共享仓库未跟踪 WIP 未改，未部署、发布、合并或使用生产凭据。
- [CX] 所有远程请求统一经过请求期 A/AAAA 公网校验、逐跳重定向复核和 cURL DNS 固定；跨源重定向剥离凭据。SSRF/DNS rebinding 12/12，Bridge/供应商 mock 14/14。
- [CX] 回滚改为同盘 staging + 原子目录替换；swap 失败恢复原版本，恶意 ZIP 在解压前拒绝，3/3。
- [CX] network-active 状态下未来新增站点自动初始化，删站在删表前清理；新站/删除 2/2，网络卸载清理 exit 0。
- [CX] Plugin Check 正确发布目录为 2 个私有 updater policy errors / 299 warnings；精确私有 profile 0 error result。nonce/unslash/sanitize/文件系统 errors 已清零；274 个模板变量 warning 未机械改名。
- [CX] final：npm test 0；PHPStan level 3 0 errors；PHP 7.4.33/WP 5.9 与 PHP 8.3.27/WP 7.0.3 通过；PHPCS 仍 FAIL（1328/41/66）；E2E 1/1。
- [CX] 密钥轮换显式历史密钥环、不可解密失败关闭、当前密钥 round-trip 3/3。没有配置历史密钥时不会猜测或回退明文。
- [CX] 代码提交 `a7a83786a76933f3a4eadbbae8722092fff7cb3e`。完整命令、警告分类和未完成项见 `docs/audits/wpbridge-full-2026-08-09.md` 第 7 节。
- [CX] 只读 team reviewer 任务 `wenpai-20260809-194330-2060256` 仍 pending，没有评审样本，不计 PASS。

- [CX] PR #4 在 `4695393678eb3b15cc35a42f644a3ca7edcfdb4a` 快照仍 open/未合并/mergeable；gitleaks、security-scan、WordPress 插件 CI 均 waiting，显示 `Blocked by required conditions`，不计 PASS。

- [CX] 最终输入边界复核提交 `c4330c67e44b3e5adf760566830769c11444a35a` 移除 3 处重复 unslash；其后 npm/PHPStan/Plugin Check final profile 均已重跑，结果不变。

## 2026-08-11 — [CX] WPBridge 1.2.4 私有发行本地候选

- [CX] 隔离仓库 `/home/parallels/Projects/wpbridge-codex-audit-20260809`、分支 `codex/wpbridge-audit-20260809`；共享 `/home/parallels/Projects/wpbridge` 未跟踪 WIP 未改。
- [CX] 私有发行元数据统一为 1.2.4，Update URI `https://updates.wenpai.net`，最低 WP 5.9/PHP 7.4，Tested up to WP 7.0；保留私有更新器与 VersionLock。
- [CX] private Plugin Check 0 errors / 299 warnings（仅精确豁免 updater policy，exit 0）；WordPress.org 2 policy errors / 299 warnings（exit 1）；release PHPCS exit 0；全量 PHPCS 历史债务仍 FAIL（1328/37/66）。
- [CX] 最终回归：npm test/PHPStan exit 0；PHP 7.4+WP 5.9 与 PHP 8.3+WP 7.0 通过；SSRF 12/12、mock Bridge 14/14、原子回滚 3/3、密钥轮换 3/3、multisite 生命周期 2/2、三站网络卸载、Playwright E2E 1/1 均 exit 0。
- [CX] 候选 ZIP `dist/wpbridge-1.2.4.zip` SHA-256 `04a432e035077a979b0df37e13ba2829a6885d68986441bfb9005203ec567775`；manifest 记录精确 HEAD 和逐文件哈希，升级/回滚见 `docs/releases/wpbridge-1.2.4-candidate.md`。
- [CX] PR #4 远端头仍为 `b68ec90b391b8cae69fe0510b3c5cd159f25cba6`；runs 85/86/87 均 waiting 且需人工审批。FeiCode P0 Board 门未满足前，不得 push、审批 CI、合并、发布或部署。
- [CX] 本地最终 HEAD、拟推 ref、验收与回退写入 ignored 的 `dist/devops-board-change-wpbridge-1.2.4.json`，供 devops 创建 Board change；该任务说明本身没有发布到 Board。
- [CX] 只读 reviewer 任务 `wenpai-20260811-121324-622542` 仍 pending、无输出，不计 PASS。
