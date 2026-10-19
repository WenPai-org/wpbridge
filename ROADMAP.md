# WPBridge 开发路线图

> 自定义源桥接器：版本规划和开发任务

*创建日期：2026-02-04 · 最后更新：2026-10-19*

---

## 当前版本：1.3.0（2026-08-15）

各版本的具体改动见 [CHANGELOG.md](CHANGELOG.md)。架构说明见 [ARCHITECTURE.md](ARCHITECTURE.md)。

## 版本脉络

```
0.2.0 – 0.9.6  初版实现、Bridge API、导入导出、版本锁定（2026-02）       ✅
1.0.0          wenpai-bridge 集成、供应商系统、加密 API 密钥（2026-03）   ✅
1.1.0          订阅管理、Kestrel；删除 12 个非核心模块（2026-03）         ✅
1.2.0 – 1.2.2  后台重设计（8 Tab → 5）、安全审查修复（2026-03）           ✅
1.2.3          更新器唯一类名，避免与其他文派插件冲突（2026-07）          ✅
1.3.0          Hub-Spoke 授权、受保护的更新授权、制品 Ed25519 签名（2026-08）✅
    ↓
1.3.1          升级和回滚过程中的更新器安全（评审中）                    ← 当前目标
    ↓
1.3.x          可访问性修复、后台 URL 收口、和服务端的接口合同测试
    ↓
后续           接入文派 Admin UI 套件（排在套件迁移的最后）
```

---

## 1.3.1：升级、回滚安全 ← 当前目标

范围已冻结（2026-10-19）。合并顺序：#12 → #19 → #16 → #20 → #15 → #13 → #14 → #17 → #21 → 发版准备。

### 升级与回滚
- [ ] WordPress 在同一请求里替换插件文件后，旧版本的更新器对象不再发起网络请求，按失败关闭处理（#12）
- [ ] HTTP 客户端在文件切换期间不可用时，返回错误而不是致命错误（#12）
- [ ] CI 打包版本号从插件头读取，不再写死 1.2.4（#12）
- [ ] Site Health 检查插件头版本和 `WPBRIDGE_VERSION` 是否一致；不一致时暂停自更新检查，`plugin_info()` 加同样的守卫（#16）

### 安全
- [ ] 自更新验签：新增 `WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS`，服务端返回的字段只能加严不能放宽；服务端下发 `sha256` 后，安装包必须和它一致（#20）

### 修复
- [ ] 启用默认 wenpai-open 源时，打开 Site Health 不再出现致命错误（#19）

### 可访问性
- [ ] 8 个后台表单 label：单个控件补 `for`，成组输入改用 `role=group` 加 `aria-labelledby`（#14）
- [ ] 19 处站外链接补 `rel`：写死的文派域名只加 `noopener`，第三方和动态 URL 加 `noopener noreferrer`（#14）
- [ ] 补 aria-label，并修正两处低对比度文字（#17）

### 测试与发版
- [ ] 服务端接口合同测试：v2 路由权限、bridge/v1 错误码、capabilities 回退（#21）
- [ ] `npm test` 全绿；测试站 1.3.0 → 1.3.1 → 回滚验收（单站点和多站点，WP 7.1.3）
- [ ] Tested up to 改为 7.1；plugin-registry 的 `tested_wp` 同步改为 7.1（由服务端维护人改）
- [ ] 发版说明，`readme.txt` 和 `CHANGELOG.md` 同步更新

## 1.3.2：安全加固和 i18n

### 安全（排第一）
- [ ] bridge/v1 的限流改到认证之前执行，用错 key 的请求也要计数，避免暴力猜 key
- [ ] `require_auth=false` 时，限流不再按 key 区分，换 key 绕不过去
- [ ] 公开的 `/status` 不再暴露插件版本号（去掉，还是只给登录用户看，待定）
- [ ] `repo` 参数第二段允许 `..`：如果会拼进路径，改进 1.3.1；如果只用作查找的键，在这里收紧

### 接口和错误码
- [ ] `POST /wpbridge/v2/spoke-links/accept` 按错误类型返回 400、403、409、503 或 502；远端 Hub 的状态码不再透传，原值放进 `hub_status`（topic `wpbridge-spoke-accept-status`）
- [ ] 预设源 `PluginUpdater::plugin_info` 也加上版本守卫
- [ ] 补 #20 之后的签名和校验和合同测试：CT-1、2、3、5、6、7、11，以及 CT-12 里的 sha256 部分

### 可访问性和 i18n
- [ ] 对比度：#9ca3af 的白底正文和「已安装」徽标提到 4.5 以上；「即将上线」卡片要用文字或 aria 写明状态，不能只靠变灰（topic `wpbridge-a11y-contrast`）
- [ ] admin.js 文案统一走 `wp.i18n`，用 `wp_set_script_translations`，重新生成 .pot；同时清理没注入和没用到的翻译键

## 1.3.x 后续小版本：管理后台

- [ ] 16 处写死的 `admin.php?page=wpbridge` 收口到 `AdminPage::url()`，加合同测试（为以后迁移菜单做准备，本阶段不搬菜单、不碰加密）
- [ ] 后台 CSS 同时兼容现有一级菜单和以后「设置」子菜单两套页面选择器

### 服务端（不在本仓库，问题单已转维护人）
- [ ] wenpai-bridge 的 update-check 下发 `sha256` 和 Ed25519 签名，release.yml 生成签名附件
- [ ] wpbridge-server 下载接口要求签名或 grant；`checksum` 和 `sha256` 字段名对齐；补上 `/capabilities`
- [ ] 确认 `/api/v1/updates/pair` 和 `/api/v1/updates/grants` 由哪个服务提供

## 后续：接入文派 Admin UI 套件

套件正式宣布可以接入之后才开始。云桥排在文派插件迁移顺序的最后。

- [ ] 一级菜单「云桥」移到「设置」下，用 Tab 加二级导航，旧 URL 自动跳转
- [ ] 密钥加密对接套件的 `secret` / `encrypt`，或保留自有 `Encryption` 并对接，附升级回调
- [ ] 更新检查和授权**不进套件**，继续由云桥和文派更新器负责
- [ ] 套件暂时没有的部分（模态框、导入导出、Site Health）由插件自己保留
- [ ] 集成测试：和其他文派插件自带的更新器共存

---

## 早期规划的落实情况（2026-02 版路线图）

2026-02 的路线图按 0.x 版本拆分任务。下表记录这些计划后来的去向，方便对照。

| 计划项 | 现状 |
|---|---|
| 更新源桥接、缓存与降级、WP-CLI（`wp bridge`）、Git 源（GitHub、GitLab、Gitee、WenPaiGit）、FAIR、ArkPress、AspireCloud | ✅ 已实现 |
| Bridge API（REST，可选启用） | ✅ 已实现 |
| 配置导入导出 | ✅ 已实现（0.8.0） |
| 版本锁定 | ✅ 已实现（`Core/VersionLock`） |
| 更新前备份、一键回滚 | ✅ 已实现（`Core/BackupManager`），1.2.4 起备份原子替换 |
| Site Health 集成 | ✅ 已实现（`Core/SiteHealth`） |
| 多站点支持 | ✅ 1.2.4 加入新站点初始化和卸载清理；1.3.0 加入 Hub-Spoke 凭据代理 |
| 商业插件检测（CommercialDetector） | ❌ 1.1.0 删除，改由供应商系统和 AutoMatcher 处理 |
| 更新日志聚合（ChangelogManager） | ❌ 1.1.0 删除，WordPress 原生已有更新日志展示 |
| 源分组 | ❌ 1.1.0 删除（后台没有入口） |
| 通知系统（邮件、Webhook） | ❌ 1.1.0 删除 |
| AI 桥接层（OpenAI 兼容层、商业插件 AI 适配） | ❌ 1.1.0 删除，AI 桥接归 WPMind 负责 |
| 开发者文档（如何搭建兼容的更新服务器） | ⏳ 未完成，产品文档放在 wpcy.com |
| 安全扫描集成、白标 | ⏳ 未排期 |

## 待讨论事项（2026-02 结论，保留备查）

- [x] 是否需要云端配置同步？→ 暂不需要
- [x] 是否支持多站点？→ 当时定为付费功能（v1.0.0）；后来 1.2.4、1.3.0 已在插件内实现多站点支持
- [x] 定价策略？→ 基础免费 + 高级付费
- [x] 与文派叶子的集成方式？→ 独立运行，检测到 WPCY 时官方源走 WPCY
- [x] 预置更新源？→ 文派开源（默认）、ArkPress、AspireCloud、FAIR
- [x] WP-CLI 命令前缀？→ `wp bridge`
- [x] 自托管方案支持？→ ArkPress、AspireCloud、UpdatePulse Server、PUC 格式
