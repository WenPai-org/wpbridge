# WPBridge 1.2.4 私有发行候选说明

> [CX] 产品定位：FeiCode/WenPai 企业私有分发。保留私有更新器和 VersionLock；本版只包含兼容与安全修复，不删除现有功能。

## 候选元数据

- Version / Stable tag / npm package / lockfile / POT：`1.2.4`
- Update URI：`https://updates.wenpai.net`
- 最低 WordPress：`5.9`
- Tested up to：`7.0`
- 最低 PHP：`7.4`
- 更新 API：`https://updates.wenpai.net/api/v1`

## 变更范围

- 请求期 DNS/SSRF：每跳重新解析，拒绝私网/保留/混合解析，cURL 固定已校验地址，跨源重定向剥离凭据。
- 原子回滚：同盘 staging、ZIP 顶层/路径/符号链接验证、替换失败恢复原目录。
- 密钥轮换：当前密钥只用于新加密，历史密钥环只用于解密；不可解密失败关闭，不回退明文。
- Multisite：网络激活逐站初始化，未来新站自动初始化，删站与卸载逐站清理。
- 修复迁移、管理输入、PHP 7.4 和 WP 5.9 兼容问题；不删除 Handler、供应商、Bridge、REST、CLI、备份或私有更新功能。

## 检查 profile

### 私有发行阻断门

```bash
tests/plugin-check/run-profile.sh private wpbridge /tmp/wpbridge-plugin-check-private.txt
```

仅豁免 `plugin_updater_detected`。所有 warnings 保留在报告中，其他 error 会使脚本退出 1；没有使用 `--ignore-warnings` 或 `--ignore-errors`。

### WordPress.org 评估

```bash
tests/plugin-check/run-profile.sh wordpress-org wpbridge /tmp/wpbridge-plugin-check-wordpress-org.txt
```

不豁免任何 code。当前私有 updater/VersionLock 会使该 profile 失败；这是发行渠道差异，不是私有候选 PASS。若未来上架 WordPress.org，应制作不含私有 updater 的独立发行包。

### Release PHPCS

```bash
phpcs --standard=phpcs.release.xml.dist --report=full .
```

该阻断 profile 覆盖输入净化、nonce、输出转义、安全重定向、SQL prepared statements、文件/远程 API 和 PHP 7.4 兼容。全量 `phpcs.xml.dist` 继续作为非阻断历史债务报告，不能替代 release profile。

## 可复现构建

必须从干净且已提交的候选 HEAD 构建：

```bash
tests/release/build-candidate.sh --version 1.2.4 --output-dir dist
sha256sum -c dist/wpbridge-1.2.4.zip.sha256
unzip -t dist/wpbridge-1.2.4.zip
```

脚本使用 `git archive HEAD`、固定文件顺序、固定 ZIP 时间戳和 `zip -X`。输出：

- `dist/wpbridge-1.2.4.zip`
- `dist/wpbridge-1.2.4.zip.sha256`
- `dist/wpbridge-1.2.4.manifest.json`

manifest 记录 HEAD、版本、运行要求、Update URI、ZIP SHA-256，以及包内每个文件的路径、模式、大小和 SHA-256。

## 升级步骤

1. 记录当前插件版本、network-active 状态、站点列表和 `WPBRIDGE_ENCRYPTION_KEY`/历史密钥环配置；不得把密钥写入工单或日志。
2. 备份数据库及现有 `wp-content/plugins/wpbridge`，验证备份可读。
3. 在隔离站点用候选 ZIP从 1.2.3 升级，验证插件加载、设置迁移、源绑定、更新检查和 cron。
4. Multisite 先验证两站网络激活、新建站点、删站和卸载边界；生产变更必须另走 Board 审批。
5. 核对候选 SHA-256 与 manifest 后，才允许 devops 推送候选 HEAD并审批对应 CI。发布 tag、Release 和部署是后续独立人工动作。

## 回滚

- 更新前备份仍存在时，优先使用 WPBridge 的原子回滚：新目录换入失败会恢复原目录。
- 插件无法加载时，人工将当前 `wpbridge` 目录改名隔离，再恢复已验证的 1.2.3 目录；不要覆盖失败现场。
- 恢复 1.2.3 后清理 WPBridge cron 并重新激活；multisite 必须逐站核对。
- 1.2.4 的迁移保持兼容读取，但不会自动把所有 option 恢复成升级前字节状态。要求完全回到升级前状态时，恢复同一时间点的数据库备份。
- 密钥轮换后回滚仍需保留当前和历史解密密钥；缺失历史密钥时凭据会失败关闭，不能用明文兜底。
- 回滚后重新运行最低版本、mock Bridge、更新器降级保护和设置迁移 E2E；零样本不能写 PASS。

## 发布门

- 本地候选提交与工作区干净。
- 元数据契约、`npm test`、PHPStan、release PHPCS、私有 Plugin Check、最低版本、WP 7、多站、mock Bridge、SSRF/回滚/密钥轮换、E2E 全部有 exit code 和非零样本。
- ZIP 两次独立构建 SHA-256 一致，manifest 与 ZIP 内容逐文件一致。
- devops Board change 已包含精确拟推 HEAD、影响、验收、回退和 CI runs。
- FeiCode push、CI approval、merge、tag、release、更新 API 和部署均不由本地候选任务执行。
