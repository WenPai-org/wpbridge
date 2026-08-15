# WPBridge 全功能审计与修复记录（2026-08-09）

> [CX] 分支直接 push 被 FeiCode 策略拒绝（该账号只能走 AGit PR refs）；为避免擅自创建 PR，提交保留在 WenPai VM 隔离 worktree。
>
> [CX] 审计基线：FeiCode `WenPai-org/wpbridge` 的 `main`，提交 `a27bd828c97e8999b205459ea1ca0af8a6873c81`。工作分支 `codex/wpbridge-audit-20260809`，隔离 worktree `/home/parallels/Projects/wpbridge-codex-audit-20260809`。未部署、未发布、未合并默认分支。共享目录 `/home/parallels/Projects/wpbridge` 的未跟踪迁移及 CI 文件未改动。

## 1. 功能、入口和数据流

### 入口

- `wpbridge.php`：插件头、常量、激活/停用钩子、嵌入式自更新器、`plugins_loaded` 初始化、`wp bridge` CLI。
- `includes/Core/Plugin.php`：依赖装配、迁移、插件/主题更新器、商业供应商、REST、自动匹配、备份、版本锁、Site Health。
- `includes/Admin/AdminPage.php` 与 `includes/Admin/VendorAdmin.php`：设置页、源/默认规则/项目绑定、供应商、API Key、缓存、日志、导入导出、回滚等管理员操作；审计到的 AJAX 均要求 nonce 和 `manage_options`。
- `includes/API/RestController.php`：`wpbridge/v1` 状态、源、项目、更新检查和安装路由。API 默认关闭，写接口默认要求 API Key；状态路由公开且只暴露版本和端点信息。
- `includes/CLI/BridgeCommand.php`：源、供应商、诊断等 WP-CLI 操作。
- `includes/Performance/BackgroundUpdater.php`：每天两次的源状态/更新任务；停用和卸载清除 `wpbridge_update_sources`。

### 更新数据流

1. `Settings/wpbridge_sources` 保存旧模型，`SourceRegistry/wpbridge_source_registry` 保存新模型。
2. `DefaultsManager/wpbridge_defaults` 定义全局、插件、主题顺序；`ItemSourceManager/wpbridge_item_sources` 保存项目覆盖。
3. `SourceResolver` 把有效注册表记录转换为 `SourceModel`。
4. `PluginUpdater` / `ThemeUpdater` 选择 Handler：JSON、ZIP、GitHub、GitLab、Gitee、WenPai Git、FAIR、ArkPress、AspireCloud、PUC、Bridge Server 或 Vendor。
5. 结果写入 WordPress 更新 transient；失败按源配置降级、使用短期负缓存或保留 WordPress.org 结果。
6. 更新前 `BackupManager` 在 uploads 下写 ZIP；版本锁过滤更新结果；管理员可触发回滚。

### 商业连接边界

- **WPBridge 本插件**：保存站点配置、源绑定、供应商凭据，执行 WordPress 内的更新发现、下载和安装。
- **wpbridge-server**：独立服务端，提供连接测试、订阅/插件元数据和下载代理；插件只通过 `BridgeClient`/`BridgeApiVendor` 调用，不应复制服务端账户、授权或制品逻辑。
- **plugin-registry**：更新元数据/发布注册真源；本插件消费更新 API，不应在运行期成为注册表写入方。发布工作流的可选 API 验证属于发布端职责。
- **wenpai-updater / wp-china-yes**：`class-wenpai-updater.php` 只负责 WPBridge 自身更新，类名 `WPBridge_Updater` 避免多插件同装冲突；`wenpai_updater_override` 允许集中更新插件接管。业务插件/主题更新仍由 WPBridge 的 `PluginUpdater`/`ThemeUpdater` 负责。

### 外部请求与数据

- 固定请求：`updates.wenpai.net` 自更新、`wpcy.com/api/bridge/commercial-config.json` 商业识别配置。
- 管理员配置请求：各 Git/JSON/ZIP/FAIR/ArkPress/AspireCloud/PUC/Bridge/WooCommerce 供应商端点。
- 没有发现独立遥测、埋点或使用量上报模块。自更新请求会发送 WPBridge 文件名和当前版本；商业连接请求会按功能发送 API Key、slug、版本或订阅参数。
- `RemoteConfig` 有 12 小时缓存和内置配置降级；更新 Handler 有缓存/负缓存。Bridge/源离线时不会删除本地配置。

### 多站点与卸载

- 当前代码没有 `switch_to_blog()`、网络级设置或逐站点迁移，所有配置使用当前站点 option。结论：**不支持网络级统一配置或网络卸载**，只能按站点使用；不能把单站激活成功写成 multisite PASS。
- 本次卸载脚本覆盖当前站点所有 `wpbridge_*` 动态/静态 option、普通和 site transient、cron 与对象缓存组。备份 ZIP 保留，因为它们可能是用户恢复数据；是否删除需产品明确选择。

## 2. 已修复问题

### P1

1. **源状态双真相失配**：供应商列表渲染 `SourceRegistry` 项目，却调用只更新 `wpbridge_sources` 的 AJAX；供应商项会报不存在，普通源也可能只改旧模型。现在 AJAX 同步两个模型、支持 registry-only vendor，并在变更后清更新缓存。
2. **迁移提前返回和迁移范围不完整**：旧代码先执行 1.2.0 迁移、写当前版本并 `return`，0.6.0 检测永远不可达；供应商 ID 只覆盖部分选项。现在顺序执行，并同步 registry、defaults 的 `source_ids/source_order`、item bindings 和 slug map，保留旧数据以便恢复。
3. **远程 changelog 可注入管理页 HTML**：更新服务返回以 `<` 开头的内容会原样进入插件详情弹窗。现在所有 raw HTML 和 Markdown 结果都经过 `wp_kses_post()`。
4. **更新响应未拒绝降级/非 HTTPS 包**：自更新器会接受任意版本和包 URL。现在只接受高于当前版本且安装包为 HTTPS 的结果；失败时保留 WordPress 已有结果。
5. **注册表凭据明文保存**：`wpbridge_secret_*` 以前直接写解密后的 token。现在写入 `Encryption::encrypt()` 结果；读取仍兼容既有明文并在下次保存时升级。
6. **卸载遗留运行数据和凭据**：旧脚本遗漏 source registry、项目绑定、defaults、备份索引、迁移版本、`wpbridge_secret_*` 和 slug map。现按受控前缀清理当前站点全部 WPBridge option 和 transient。

### 兼容性与发布

- 替换 PHP 8 的 `str_starts_with()`，保持声明的 PHP 7.4 最低版本。
- `phpcs.xml.dist` 的 PHP/WP 目标由 8.1/6.4 修正为插件头声明的 7.4/5.9。
- `package.json`/lock 的版本和许可证修正为 1.2.3 / GPL-2.0-or-later，`npm test` 不再是必失败占位命令。
- 新增 `readme.txt` 和 GPL `LICENSE`；发布 ZIP 不再主动排除许可证。
- 修正已确认的管理页数值输出转义和阻断错误消息转义。

## 3. 未直接修改的问题与理由

### P1 / 需要产品或较大结构变更

1. **回滚不是原子操作**：`BackupManager::rollback()` 直接把 ZIP 解压到目标父目录；中途失败可能留下半更新文件，旧版本不存在的文件也不会删除。建议下一版改为同盘 staging、校验主文件/slug、目录原子替换，失败自动恢复。该改动涉及插件停机和文件替换策略，本次没有在无真实升级矩阵时改写。
2. **更新前备份失败仍继续安装**：`pre_install_backup()` 忽略 `create_backup()` 的 false。需明确“备份失败阻断更新”还是“警告后继续”的产品策略。
3. **自托管内网与 SSRF 规则冲突**：`Validator` 阻止私网/localhost，但请求阶段仍使用普通 `wp_remote_*`，DNS 重绑定后没有二次地址校验。统一改成 `wp_safe_remote_*` 会同时阻断合法内网 Bridge。建议增加默认公网安全策略和显式的私网 allowlist，两种模式都在请求时复核解析结果。
4. **多站点**：需要网络设置、站点覆盖、逐站迁移/卸载和 network admin 权限模型；不能只加网络激活标记。
5. **发布治理工作流**：`auto-label.yml` 和 `stale-cleanup.yml` 引用仓库不存在的 `scripts/auto-label.py`、`scripts/stale-cleanup.py`，定时任务会失败。它们属于组织治理功能，本次未删除；应补脚本或停用工作流。

### 工具检查债务

- Plugin Check 2.0.0 在最终发布目录运行：命令退出 0，但报告 29 errors / 396 warnings，因此为 **NOT PASS**。29 项为 18 个翻译占位注释、7 个 WordPress 文件函数建议、2 个自建 updater、2 个 WordPress 5.9 静态兼容误报（调用均有 `function_exists` 降级）。完整开发目录的早期结果为 75/450，开发文件噪声明显更多。
- PHPCS：排除 JS 后退出 2，1751 errors / 46 warnings / 73 files；大量为历史格式、命名和文档规则。需要按目录分批修复，不能声明 PASS。
- PHPStan level 3：默认 256M 崩溃；`--memory-limit=1G` 完成并退出 1，81 errors。主要是 WP-CLI stubs/插件常量 bootstrap 缺失，另有若干永真判断和两个空合并提示。需先补 bootstrap/stubs，再处理真实类型错误。
- `npm run wp-env:start` 因 VM 的 `/home/parallels/bin/wp-env-start-patched` 参数传递错误失败；本次用独立 MariaDB 容器和 WordPress 7.0.3 完成真实运行验证。
- 外部 Handler 连通测试没有有效凭据/可控测试服务，多数样本是 SKIP；不计为 PASS。

## 4. 测试证据

- `npm test`：退出 0。包含全量 PHP lint、12 个加密/Zip Slip 回归断言、4 个 updater 回归断言、12 个元数据、更新器和卸载契约断言、Admin JS 语法。
- `gitleaks detect --source .`：退出 0；历史文档中的 `your_api_key` 示例已用精确 allowlist 标注，不隐藏真实凭据规则。
- `npm audit --registry=https://registry.npmjs.org --audit-level=high`：更新 dev lock 后退出 0，0 vulnerabilities。
- Handler API：6 个公开端点 PASS、0 FAIL、8 SKIP，退出 0；缺凭据/测试服务的 Handler 不计 PASS。
- 发布 ZIP 契约：`wpbridge.php`、`readme.txt`、`LICENSE` 存在，tests/docs/隐藏开发文件不进入包，退出 0。
- WordPress 7.0.3 + PHP 8.3.27 + 独立 MariaDB：安装、激活、`wp plugin status wpbridge` 退出 0，版本 1.2.3。
- 真实 WordPress 数据迁移断言：registry、defaults source order、item bindings、slug map 全部通过，退出 0。
- 真实 WordPress 凭据存储：数据库值识别为加密格式且可解密回原 token，通过。
- 真实 WordPress AJAX：`wpbridge_toggle_source` 返回 `success:true`；随后独立读取确认旧/新两个存储均为 disabled，退出 0。
- 最终发布目录 Plugin Check 2.0.0：退出 0，但有上述 29/396 报告，结论为 **NOT PASS**。
- PHPCS：退出 2（1751/46），**FAIL**。PHPStan 1G：退出 1（81 errors），**FAIL**。
- 未执行 PHP 7.4 实际运行矩阵、multisite、浏览器 E2E、真实供应商/Bridge 服务联调；不计通过。

## 5. 功能去留与版本方向

### 保留

- 注册表 + 默认规则 + 项目绑定；插件/主题更新；缓存和离线降级；版本锁；加密凭据；管理员诊断；受鉴权的 CLI/REST；更新前备份。

### 精简

- 下个版本只保留 `SourceRegistry` 为写真源，`wpbridge_sources` 变为只读兼容层并给出迁移完成标记，随后删除双写。
- Handler 统一超时、重试、URL 策略、错误对象和缓存键；删除各 Handler 重复 HTTP 代码。
- 将 WordPress.org 目录规则和 WenPai 私有分发规则拆成两个检查配置，避免自更新器的预期告警淹没真实问题。

### 拆分

- `Commercial/*`（订阅、WooCommerce 商店、Bridge Server）拆为可选模块或独立附加插件；WPBridge 核心只保留通用 source contract。
- 发布/registry 写入和组织 issue 治理工作流不属于运行插件，移到发布仓库或共享 CI 模板。

### 暂不废弃

- 不删除任何 Handler、供应商、REST 或备份功能。缺少真实样本的 Handler 标记 experimental，并在 UI 显示验证状态；收集一个版本的实际使用数据后再决定废弃。

### 建议里程碑

- **1.2.4**：合入本次安全/迁移/卸载修复；补 PHP 7.4 + WP 5.9、WP 6.9、WP 7.0 单站矩阵；对构建 ZIP 跑 Plugin Check；修 CI 引用和 PHPStan bootstrap。
- **1.3**：单一 SourceRegistry 写模型；统一 HTTP/SSRF 策略；原子回滚；明确 multisite 不支持或完成网络级设计；商业模块边界稳定后再拆包。

## 6. 第二轮整治（2026-08-09）

> [CX] 本轮继续使用隔离 worktree `/home/parallels/Projects/wpbridge-codex-audit-20260809` 和分支 `codex/wpbridge-audit-20260809`；FeiCode `main` 基线仍为 `a27bd828c97e8999b205459ea1ca0af8a6873c81`。未部署、未发布、未合并默认分支，且没有改动共享目录 `/home/parallels/Projects/wpbridge` 的未跟踪文件。

### Plugin Check

- [CX] 发布目录全量检查由 29 errors / 396 warnings 降为 **2 errors / 394 warnings**，命令退出 0。27 个代码错误已消除：18 个翻译占位符注释、7 个 WordPress 文件/URL API、2 个 WordPress 5.9 静态兼容误报。
- [CX] 剩余 2 个 error 均为 `plugin_updater_detected`：`wpbridge.php` 的 WenPai 私有自更新器和 `includes/Core/VersionLock.php` 的版本锁 transient 过滤。它们只违反 WordPress.org 目录政策；WPBridge 的 FeiCode/WenPai 私有分发需要这两项功能，未为追求目录检查结果而删除。私有分发检查使用 `--ignore-codes=plugin_updater_detected --ignore-warnings` 后退出 0、无错误结果。
- [CX] 394 个 warning 分类：273 个模板/函数局部变量被 PrefixAllGlobals 当作全局；59 个缺少 `wp_unslash()`；20 个 nonce warning 来自 `handle_actions()` 已统一验证 nonce 后调用的私有处理方法；16 个输入净化 warning 主要是布尔/整数强制转换和 REST 服务器地址；11 个直接 SQL 与 7 个无缓存 warning 用于清理 transient/option 和更新缓存；3 个商标词、2 个预期更新 transient 修改、1 个推荐级 nonce、1 个 discouraged PHP 函数、1 个 textdomain warning。4 个安全重定向 warning 已改为 `wp_safe_redirect()`。未把其余 warning 写成 PASS；输入 unslash 与历史模板命名列为后续标准债务。

### PHPStan、最低版本与多站点

- [CX] `phpstan.neon.dist` 增加 WordPress 常量 bootstrap 和 WP-CLI stubs；修复缺失模板路径、Bridge JSON 标量返回、永真 transient 判断。全仓 PHPStan level 3（1G）由 81 errors 降为 **0 errors，exit 0**；安全、迁移、自更新器文件同样为 0。
- [CX] 独立容器真实运行：PHP `7.4.33` + WordPress `5.9` + MariaDB，WPBridge `1.2.3` 安装/激活成功；WordPress `7.0.3` + PHP `8.3` 运行成功。
- [CX] 网络激活/停用现在逐站点初始化或清理定时任务；卸载逐站点删除 WPBridge option/transient，并从网络 sitemeta 清 site transient。WordPress 7.0.3 两站网络的激活断言 `sites=2`、卸载断言 `clean_sites=2`，均 exit 0。

### Bridge、供应商、回滚与浏览器

- [CX] 新增仅绑定 `127.0.0.1` 的 mock server 和真实 WordPress 合约测试，不连接生产。Bridge 与供应商覆盖成功、超时、非 JSON、401、403；自更新器覆盖降级版本与 HTTP 包保留旧更新结果。结果 14 passed / 0 failed，exit 0。
- [CX] 真实文件回滚覆盖有效 ZIP 恢复与 Zip Slip 包在解压前拒绝，2 项通过。回滚仍是覆盖式解压，不是目录原子替换；该限制保留为 1.3 结构性任务。
- [CX] Playwright 登录真实 WordPress 后打开 WPBridge 设置页，验证 `vendor_weixiaoduo-store` 自动迁移为 `vendor_weixiaoduo-mall` 且旧 ID 不再渲染；1 passed，exit 0。VM 现有浏览器版本与 Playwright 期望 revision 不同，配置允许用 `WPBRIDGE_E2E_CHROMIUM` 显式指定已安装浏览器。

### 密钥来源、轮换与失败策略

- [CX] 新写入密钥优先级为 `WPBRIDGE_ENCRYPTION_KEY`、`AUTH_KEY`、`SECURE_AUTH_KEY`；不再在无密钥时静默生成新的 option 明文密钥。旧 `wpbridge_encryption_key` 只作为最后一个解密候选并继续显示迁移警告。
- [CX] 新增 `WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS`（数组或逗号分隔字符串）作为只读历史密钥环，支持主密钥轮换后解密旧 GCM/CBC 数据；新加密只使用当前第一优先密钥。
- [CX] 所有候选密钥均无法解密时返回空字符串、不会回退明文，并触发不含密文/密钥的 `wpbridge_decryption_failed` 事件。轮换、不可解密失败关闭和当前密钥 round-trip 共 3 项通过。

### 第二轮验证结果

- `npm test`: exit 0。
- PHPStan level 3 全仓：exit 0，0 errors。
- Plugin Check 发布目录全量：exit 0，2 policy errors / 394 warnings；私有分发 profile：exit 0，0 error result。
- PHPCS：exit 2，1354 errors / 33 warnings / 65 files，仍为 FAIL，未包装为通过。
- PHP 7.4.33 + WordPress 5.9：激活与版本断言 exit 0。
- WordPress 7.0.3 两站网络激活/卸载：exit 0 / exit 0。
- Bridge/供应商/降级包 mock 合约：exit 0，14/14。
- 密钥轮换/失败关闭：exit 0，3/3。
- 回滚：exit 0，2/2。
- Playwright 设置迁移：exit 0，1/1。

### 未完成与版本方向

- [CX] PHPCS 和 394 个 Plugin Check warning 尚未清零；优先处理 `wp_unslash`/输入边界，再按模板局部变量、文档/命名分批清理。
- [CX] WordPress.org 目录 profile 仍会拒绝私有 updater/VersionLock；若未来上架目录，应拆出独立的目录发行包，而不是删除 FeiCode 私有发行功能。
- [CX] 1.2.x 保留 SourceRegistry、私有 updater、VersionLock、Bridge/供应商、凭据加密和备份；精简双写旧源模型。1.3 应完成单一 SourceRegistry 写模型、请求期 SSRF 复核/内网 allowlist、原子目录回滚和新建 multisite 站点初始化。商业 Bridge/WooCommerce 适合在契约稳定后拆为可选模块，不在本轮擅自废弃。

### 可复现命令与退出码

```bash
npm test
# exit 0

/home/parallels/.config/composer/vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=1G --error-format=raw
# exit 0, 0 errors

wp plugin check wpbridge --path=/tmp/wpbridge-r2-wp7 --url=http://127.0.0.1:8899 --format=json --no-color
# exit 0, report 2 policy errors / 394 warnings

wp plugin check wpbridge --path=/tmp/wpbridge-r2-wp7 --url=http://127.0.0.1:8899 --ignore-codes=plugin_updater_detected --ignore-warnings --format=json --no-color
# exit 0, no error result

/home/parallels/.config/composer/vendor/bin/phpcs --standard=phpcs.xml.dist --extensions=php --ignore=node_modules,vendor,tests,backups,examples --report=summary .
# exit 2, 1354 errors / 33 warnings / 65 files

docker run --rm --network host -v /tmp/wpbridge-r2-wp59:/tmp/wpbridge-r2-wp59 -v /usr/local/bin/wp:/usr/local/bin/wp:ro -w /tmp/wpbridge-r2-wp59 wpbridge-php74 wp --allow-root plugin is-active wpbridge
# exit 0; runtime: PHP 7.4.33 | WordPress 5.9 | WPBridge 1.2.3

wp eval-file tests/wordpress/bridge-contract.php 28766 --path=/tmp/wpbridge-r2-wp7 --url=http://127.0.0.1:8899 --user=admin
# exit 0, 14 passed / 0 failed

wp eval-file tests/wordpress/encryption-rotation.php --path=/tmp/wpbridge-r2-wp7 --url=http://127.0.0.1:8899 --user=admin
# exit 0, 3 passed

wp eval-file tests/wordpress/rollback-contract.php --path=/tmp/wpbridge-r2-wp7 --url=http://127.0.0.1:8899 --user=admin
# exit 0, 2 passed

WPBRIDGE_E2E_BASE_URL=http://127.0.0.1:8899 WPBRIDGE_E2E_USER=admin WPBRIDGE_E2E_PASSWORD=pass1234 WPBRIDGE_E2E_CHROMIUM=/home/parallels/.cache/ms-playwright/chromium_headless_shell-1234/chrome-linux/headless_shell npx playwright test tests/E2E/settings-migration.spec.js --reporter=line
# exit 0, 1 passed
```

### 评审交付

- [CX] 第二轮代码提交：`c23c66f1380582154dc9ce278dd5d8c62dad01a5`，分支 `codex/wpbridge-audit-20260809`。
- [CX] 已通过 AGit refs 更新 FeiCode 评审 PR [#4](https://feicode.com/WenPai-org/wpbridge/pulls/4)；未合并、未部署、未发布。

## 7. 第三轮：请求期 SSRF、原子回滚与新增站点生命周期（2026-08-09）

> [CX] 继续使用隔离 worktree `/home/parallels/Projects/wpbridge-codex-audit-20260809`、分支 `codex/wpbridge-audit-20260809` 和 FeiCode PR #4。共享目录 `/home/parallels/Projects/wpbridge` 的未跟踪迁移/CI 文件未改；未使用生产凭据，未部署、发布、合并默认分支。

### [CX] 已修复

- [CX] 新增 `SafeHttpClient`：每次请求和每个重定向跳点都重新解析 A/AAAA；任一私有、回环、链路本地、保留或公网/私网混合解析即拒绝；cURL `CURLOPT_RESOLVE` 固定已校验 IP，阻止校验后 DNS rebinding；跨源重定向移除 Authorization、Proxy-Authorization、X-API-Key/API-Key。Bridge、供应商、Handler、后台更新、远程配置、FeiCode release 和自更新器均切到此入口。
- [CX] `BackupManager::rollback()` 改为同一父目录 staging：ZIP 只允许预期顶层 slug，拒绝绝对路径、盘符、`..` 和符号链接；先将现版本原子改名，再将 staging 原子换入；第二次改名失败时恢复原版本。测试过滤器只用于注入确定性失败。
- [CX] 网络激活插件在 `wp_initialize_site` 后为未来新增站点初始化设置和 cron；`wp_uninitialize_site` 在核心删表前清运行状态。激活、停用和卸载的逐站切换全部用 `try/finally` 恢复站点上下文。
- [CX] 修复真实 POST/REST 输入问题：统一 unslash 边界、按字段 sanitize/cast、JSON 保持结构后再净化、REMOTE_ADDR 验证、GET subtab `sanitize_key`。没有机械重命名 274 个模板局部变量。

### [CX] Plugin Check 与静态检查

- [CX] 按发布工作流排除项生成的正确 slug 目录，Plugin Check 从第二轮 2 errors / 394 warnings 降到 **2 errors / 299 warnings**。剩余 2 errors 均为私有分发必需的 `plugin_updater_detected`；私有 profile 精确忽略该 code 并忽略 warnings 后输出为空、exit 0。
- [CX] 299 warnings：274 `PrefixAllGlobals` 模板局部变量，11 direct SQL，7 no-cache，3 商标词，2 update transient 修改，1 `set_time_limit`，1 `load_plugin_textdomain`。本轮已清零 Plugin Check 的 nonce、unslash/input sanitize 和文件系统 error；未把剩余 warnings 写成 PASS。
- [CX] PHPStan level 3 全仓 final run：0 errors，exit 0。PHPCS 可比命令仍为 **FAIL**：1328 errors / 41 warnings / 66 files，exit 2；另一个包含 tests 的全目录口径为 1713/43/75。

### [CX] 最终验证证据

- `npm test`: exit 0。
- PHP 7.4.33 + WordPress 5.9 + WPBridge 1.2.3：版本/加载 exit 0；SSRF 合约 12/12，exit 0。
- PHP 8.3.27 + WordPress 7.0.3 + WPBridge 1.2.3：版本/网络激活 exit 0。
- SSRF/重定向/DNS rebinding：12 passed / 0 failed，exit 0。
- 本地 mock Bridge/供应商/降级包：14 passed / 0 failed，exit 0；覆盖成功、超时、非 JSON、401、403、降级版本与非安全包保留旧更新；未连接生产。
- 原子回滚：有效替换、第二次 swap 失败恢复原版本、恶意 ZIP 解压前拒绝，3/3，exit 0。
- multisite 新站/删除：2/2，exit 0；网络卸载逐站 option/cron 清理：exit 0。
- 密钥：显式配置历史密钥环后，旧密钥解密、不可解密失败关闭并发事件、当前密钥 round-trip，3/3，exit 0。无历史密钥配置时旧密文不可解是预期的 fail-closed，不会回退明文。
- Playwright 设置迁移 E2E：1 passed，exit 0。该次 E2E 后只改了回滚目录可写预检和 PHPCS 边界注释，未改迁移/UI 路径。
- [CX] WenPai 团队只读评审任务 `wenpai-20260809-194330-2060256` 截止提交时仍为 pending，因此没有把零样本写成评审 PASS。

### [CX] 命令与退出码

```bash
npm test
# 0

/home/parallels/.config/composer/vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=1G --error-format=raw
# 0, no output

/home/parallels/.config/composer/vendor/bin/phpcs --standard=phpcs.xml.dist --extensions=php --ignore=node_modules,vendor,tests,backups,examples --report=summary .
# 2, 1328 errors / 41 warnings / 66 files

wp plugin check wpbridge --format=json --no-color
# 0, report 2 private-distribution policy errors / 299 warnings

wp plugin check wpbridge --ignore-codes=plugin_updater_detected --ignore-warnings --format=json --no-color
# 0, empty result

docker run --rm --network host -u 1000:1000 -v /tmp/wpbridge-r3-wp59:/var/www/html -v /home/parallels/Projects/wpbridge-codex-audit-20260809:/plugin -w /var/www/html docker.m.daocloud.io/library/wordpress:cli-php7.4 eval-file /plugin/tests/wordpress/ssrf-contract.php --skip-plugins
# 0, 12/12

wp eval-file tests/wordpress/ssrf-contract.php --skip-plugins
# 0, 12/12
wp eval-file tests/wordpress/bridge-contract.php 28766 --skip-plugins
# 0, 14/14
wp eval-file tests/wordpress/rollback-contract.php --skip-plugins
# 0, 3/3
wp eval-file tests/wordpress/multisite-lifecycle.php
# 0, 2/2
```

### [CX] 未完成与边界

- [CX] PHPCS 仍为历史债务；274 个模板局部变量前缀告警应通过模板作用域规则/基线治理，不应机械改变量名。direct SQL/no-cache 需逐处决定缓存失效和卸载清理语义。
- [CX] WordPress.org 目录包仍不能包含私有 updater/VersionLock；若上架，应拆独立目录发行包。当前 FeiCode/WenPai 私有包保留功能。
- [CX] 没有真实 Bridge/WooCommerce 凭据契约样本；本轮只完成本地 mock，不触碰生产。
- [CX] 代码提交 `a7a83786a76933f3a4eadbbae8722092fff7cb3e`；文档提交和 PR/CI 状态见本节后续交接。

### [CX] FeiCode PR 状态快照

- [CX] 代码和文档推送到 PR #4 后，PR 保持 open、未合并、mergeable=true，评审头为 `4695393678eb3b15cc35a42f644a3ca7edcfdb4a`。
- [CX] 该头的 3 个检查均为 pending/waiting 且显示 `Blocked by required conditions`：gitleaks、security-scan、WordPress 插件 CI；不是 PASS，也没有失败样本。聚合 commit status API 匿名读取返回 403，单项 statuses/actions runs 可读。

- [CX] 最终复核发现并移除 3 处中央 POST reader 后的重复 `wp_unslash()`；代码提交 `c4330c67e44b3e5adf760566830769c11444a35a`。该提交后重新运行 `npm test`、PHPStan 和两种 Plugin Check profile，分别为 exit 0、0 errors、2 policy/299 warnings、私有 profile 空结果。

## 8. [CX] 1.2.4 私有发行本地候选收口（2026-08-11）

- [CX] 产品边界已按批准方案固定：WPBridge 是 FeiCode/WenPai 企业私有分发插件；保留私有更新器和 VersionLock，本版只做兼容与安全修复，不删除现有功能。
- [CX] 版本、插件头、Stable tag、npm/lockfile、POT 和 changelog 统一为 `1.2.4`；Update URI 为 `https://updates.wenpai.net`；最低 WordPress `5.9`、最低 PHP `7.4`、Tested up to `7.0`。
- [CX] 新增明确的 Plugin Check 双 profile。private 只精确豁免 `plugin_updater_detected`，不忽略 warning/error；WordPress.org 不豁免。最终私有 profile：0 errors / 299 warnings，exit 0；WordPress.org profile：2 updater policy errors / 299 warnings，exit 1。299 warnings 已分类为 274 PrefixAllGlobals、11 DirectDatabaseQuery、7 NoCaching、3 trademarked_term、2 update_modification_detected、1 load_plugin_textdomain、1 set_time_limit；没有把 warnings 写成 PASS。
- [CX] 新增阻断型 `phpcs.release.xml.dist`，覆盖输入、nonce、输出、安全重定向、prepared SQL、文件/远程 API 和 PHP 7.4 兼容；最终 exit 0。全量历史债务仍为 FAIL：1328 errors / 37 warnings / 66 files，exit 2。
- [CX] 最终 HEAD 代码回归：`npm test` exit 0；PHPStan level 3 exit 0；PHP 7.4.33 + WP 5.9 + WPBridge 1.2.4 版本加载 exit 0，SSRF 12/12；PHP 8.3.27 + WP 7.0.3 + WPBridge 1.2.4 版本加载 exit 0。
- [CX] 最终真实 WordPress 回归：SSRF/DNS rebinding 12/12；本地 mock Bridge/供应商/超时/非 JSON/401/403/降级与回退保护 14/14；原子替换和失败回滚 3/3；密钥轮换/不可解密失败关闭 3/3；multisite 新站初始化/删站 2/2；三站网络卸载清理 exit 0 并重新网络激活；Playwright 设置迁移 1/1。所有远程契约仅接本地 mock，不使用生产凭据。
- [CX] 可复现构建脚本从干净的已提交 HEAD 使用 `git archive`、固定顺序/时间戳和 `zip -X`；两次独立构建 ZIP 字节一致，`sha256sum -c` 和 `unzip -t` 通过。候选 ZIP SHA-256：`04a432e035077a979b0df37e13ba2829a6885d68986441bfb9005203ec567775`；最终精确 HEAD 由同目录 manifest 与 devops Board 任务说明记录。
- [CX] FeiCode PR #4 仍 open、未合并、mergeable=true，远端评审头仍为 `b68ec90b391b8cae69fe0510b3c5cd159f25cba6`。远端 runs 85（gitleaks）、86（security-scan）、87（wp-plugin-ci）均 waiting、need_approval=true、approved_by=0；没有审批或触发。
- [CX] WenPai 只读 reviewer 任务 `wenpai-20260811-121324-622542` 截止收口仍 pending，且没有输出文件，因此不计评审 PASS。
- [CX] FeiCode P0 `write_policy=board_required` 生效：本轮只在隔离仓库生成本地候选、ZIP/manifest 和 Board change 任务说明；未 push 最终提交、未审批 CI、未合并、未发布、未部署。共享仓库未跟踪 WIP 未覆盖。
