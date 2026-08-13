# 文派账户站点配对与更新授权

> 状态：本地开发候选，尚未合并 canonical、发布或部署。

## 产品边界

平台、商户、客户三个完整门户由 WordPress + WooCommerce 开发：平台入口在 WooCommerce/MarketKing 后台，商户入口在 MarketKing vendor dashboard，客户入口在 WooCommerce My Account。WPBridge 只运行在客户已激活的 WordPress 站点，负责最小权限的更新接入；license-server Web 仍是公司内部授权运营控制台。

## 配对流程

1. 客户在 WooCommerce My Account 的许可证卡片中，为一个已激活站点生成一次性 `WPB1-...` 连接码。
2. 客户在该站点的 WPBridge Bridge Server 更新源中填写 `https://updates.wenpai.net`、`https://license.wenpai.net` 和一次性连接码。
3. WPBridge 在站点内生成 Ed25519 密钥对，只把公钥、连接码和规范化站点 URL 发往 `POST /api/v1/updates/pair`。
4. 私钥以 WPBridge AES-256-GCM 格式加密后保存在该更新源的 `metadata.update_private_key`；明文连接码和明文私钥不写 WordPress option、日志或 URL。
5. 重新配对会轮换同一 activation 的设备公钥。留空连接码保存更新源时保留现有设备元数据。

`WPBRIDGE_ENCRYPTION_KEY` 应在 `wp-config.php` 中配置并纳入站点秘密备份；缺少所有可用主密钥时配对失败关闭，而不是明文保存。

## 元数据与包下载

WPBridge 每次请求受保护元数据时，用设备私钥签发一个只在函数内存中存在的 `updates:read` grant；下载前再签发独立的 `packages:read` grant。两者均通过 `Authorization: Bearer` 发送到 wenpai-bridge，不进入 query、transient 或持久认证字段。

受保护下载的顺序是：

1. 更新信息返回稳定的 HTTPS bridge URL、SHA-256、精确制品文件名/大小及 detached Ed25519 签名 envelope 字段；
2. WPBridge 将 URL、摘要、签名元数据和本地受信 keyring 关联到 site transient；
3. WordPress upgrader 下载前，WPBridge 重新解析该 slug 的 Bridge Server source；
4. 签发短期 package grant，并让 SafeHttpClient DNS 固定请求 bridge；
5. bridge 代理同源 Forgejo Release ZIP；
6. WPBridge 先对临时文件计算 SHA-256 和大小，再按固定 canonical string 使用本地 keyring 中 `signature_kid` 对应的 Ed25519 公钥验签，通过后才交给 upgrader。

受保护源在 `signature_required=true` 时，缺少配对、摘要、签名字段、可信 `kid`、Sodium、加密主密钥或有效 grant，以及摘要、大小或签名不一致，均失败关闭。Bridge 响应中的公钥永远不能成为信任根。公共 legacy 源没有声明强制签名时继续兼容 SHA-256-only 路径。

## 制品签名信任根与轮换

source registry 以 `artifact_public_keys` 保存按 `kid` 索引的只读公钥环；站点自更新也可在 `wp-config.php` 定义同结构的 `WPBRIDGE_ARTIFACT_PUBLIC_KEYS`。这里只允许公钥，禁止写入发布私钥：

```php
define(
	'WPBRIDGE_ARTIFACT_PUBLIC_KEYS',
	[
		'wpbridge-ed25519-2026q3' => [ 'public_key' => 'BASE64URL_PUBLIC_KEY', 'status' => 'active', 'not_before' => '2026-07-01T00:00:00Z' ],
		'wpbridge-ed25519-2026q2' => [ 'public_key' => 'BASE64URL_PUBLIC_KEY', 'status' => 'verify-only', 'not_before' => '2026-04-01T00:00:00Z', 'not_after' => '2026-09-30T23:59:59Z' ],
	]
);
```

`active` 和 `verify-only` 均可验证签名时间落在 inclusive `[not_before, not_after]` 内的历史制品；`verify-only` 必须设置 `not_after`，`active` 可省略。所有时间必须是精确 UTC 秒格式 `YYYY-MM-DDTHH:MM:SSZ`。新制品由发布流水线选择唯一 active 私钥签名，WPBridge 不负责签发。未知 `kid`、禁用状态、格式错误的 key 都不进入可验证 keyring。

`artifact_signed_at` 是签名者自声明并受签名保护的时间，不是第三方可信时间戳，也不能替代私钥撤销。若旧私钥疑似泄露，部署方必须从 allowlist 移除该 `kid`、重新签发仍需分发的历史制品并更新客户端 keyring；不能依赖回填时间或 `not_after` 单独收敛泄露风险。

定义 `WPBRIDGE_ARTIFACT_PUBLIC_KEYS` 后，它就是部署级 allowlist：source 只能重复其中已有 `kid` 且公钥必须完全一致，不能覆盖同名 key，也不能追加部署未批准的 `kid`；冲突时有效 keyring 置空并让强制签名验收失败关闭。仅当部署常量未定义时，才使用 source-local `artifact_public_keys`，以兼容既有部署方式。

签名原文固定为 UTF-8，字段顺序和末尾换行不得改变：

```text
WENPAI-RELEASE-SIGNATURE-V1
slug:{slug}
version:{version}
file:{artifact_file}
size:{artifact_size}
sha256:{sha256}
signed_at:{artifact_signed_at}
```

## 兼容与限制

- 插件最低 PHP 7.4；实现不使用构造器属性提升或 `str_starts_with`。
- 只有 exact `updates.wenpai.net` Bridge Server URL 不自动追加旧 `/wp-json/bridge/v1/`；其他 Hub 站点维持旧兼容行为。
- 当前签名合同一次只授权一个 product slug 和一个 scope。
- 更新授权 grant 签名 key 与 release artifact keyring 相互独立，不能复用；artifact keyring 支持 active + verify-only 验证窗口。
- 本候选未证明真实 Woo 购买、续费、退款和 production rollback，不能据此声称生产更新闭环已完成。
