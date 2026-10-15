# JangoPay × Azuriom Shop 支付插件 — 1.0.4

> **发布包已整理，最终验收由使用方完成。** 1.0.4 包含认证、回调正文、固定精度金额和手续费净额四项真实联调修复。已完成真实 Azuriom/Shop 集成及一笔 USDT/BSC 支付，并经受控恢复和合成通知重放完成本地积分交付。公开上线前仍需用最终包验证新订单的首次官方回调自动发货；真实游戏服发货和生产数据库/多节点不在本轮验证范围。当前结果见 `docs/LIVE-COMPAT-20261015.md`。

## 项目和支持范围

- 独立 Azuriom 插件 ID `jangopay`，向 Shop 注册 `JangoPay` 一次性支付方式；不修改核心。
- Shop 最终结算价以 **USD** 计价，通过 JangoPay 创建发票；只支持后台列出且商户账户实际可用的 `USDT:<chain>`、`USDC:<chain>` 组合。网络是否能用以实时账户权限为准。
- 本地账本记录关联 ID、收款指示、核验和交付状态；支付失联不会默认再次创建发票；后台可核验。
- 只有**商户 API 认证查询**确认订单/金额/币种/交易归属、文档证实的最终状态后，才触发 Shop 原生 `processPayment()`。防止重复发货的交付声明先持久化，外部游戏命令出现异常即转人工审核。
- 不支持订阅、退款、提现、兑换、钱包管理、汇率换算以及访客自动续费。

## 安装前检查

1. Azuriom v1 系列、Shop 1.2.x（以实际兼容验证为准）；PHP >= 8.1。PHP 应启用 OpenSSL；平台 API 通过 Laravel HTTP Client 发起。使用加密回调时服务器必须能够调用 OpenSSL 命令行 `openssl pkeyutl`，并允许 `proc_open()`。
2. 解压安装 ZIP 至 Azuriom 根目录的 `plugins/jangopay/`。**该 ZIP 根目录有 `plugin.json`，不要放到 `plugins/jangopay/jangopay/`。**
3. 在 Azuriom 后台启用插件，检查其迁移成功。安装不需要修改 Azuriom 主项目 `composer.json`，也不需要联网 Composer 安装。
4. 在服务器进程的真实环境变量里设置 `JANGOPAY_API_KEY=...`。不要将凭据写入 Shop gateway JSON、数据库、源码或上传的 ZIP。
5. 在 JangoPay 商户后台 Developer settings → Callback configuration 配置回调 URL 与 RSA 公钥；私钥置于 web 根目录之外，使用 `JANGOPAY_RSA_PRIVATE_KEY_PATH=/secure/path/private.pem` 指定，权限建议 `0600`。收款通知路由由后台 JangoPay 配置页展示，格式 `https://你的站点/api/shop/payments/jangopay/notification`，**具体数据库网关路由绑定必须在真机验证**。
6. 在 Shop → Payment Gateways 新建 JangoPay，填入已核验的币种网络，如 `USDT:tron`。仅当 JangoPay 账户的只读 payment-assets 接口证实该组合可用时启用。可在 `/admin/jangopay/payments` 使用「Test API connection」测试读取权限。
7. **新增收款前安全检查：** `JANGOPAY_ENABLE_PAYMENTS=1`、`JANGOPAY_FINAL_INVOICE_STATUSES`（经过真实接口验证的发票终态）、`JANGOPAY_WEBHOOK_TAG_FORMAT=appended16`（经过真实通知向量确认的 GCM tag 封装）及有效、可读取的 RSA PEM 私钥必须同时满足，才允许创建付款发票。默认全部关闭，支付入口会拒绝。请先在隔离测试站点验证；不能只为让插件放行而随意猜测取值。

## 下单与订单关联

玩家通过 Shop 原生购物车结算，插件创建 Shop pending 付款并保存插件账本。创建一次远端 JangoPay invoice 后显示本地支付页面，用户选择商户允许的稳定币/网络，再从 JangoPay 获取相应的支付地址和精确数量。浏览器绝不接收 API Key、RSA 私钥或商户访问令牌。**报价未获确认前不可自行用 USD 数额当做稳定币数量转账。** 目前付款页展示地址与数量，**尚未提供二维码组件**。

刷新页面不会再创建发票。浏览器页仅展示本站数据库的状态；后台 `POST /admin/jangopay/payments/{attempt}/reverify` 或 `php artisan jangopay:reconcile` 才通过商户 API 查询。管理员看到 `unknown_creation` 时的「Reverify」只读搜索远端发票，不会二次创建；找不到仍然停留未知状态。

## 故障处理

| 状态 | 含义与操作 |
| --- | --- |
| `reserved` / `creating` | 远程创建处于临界阶段；不要盲目重试 |
| `unknown_creation` | 创建结果未知；管理员人工复核已有发票 |
| `awaiting_payment` | 已创建发票，等待选择币种网络 |
| `quoting` / `quote_unknown` | 生成付款指示中/结果未知；**不得重复调用创建报价接口**。可使用已认证的商户交易只读查询恢复，前提是交易归属、资产、金额/地址字段经核验 |
| `quoted` | 用户可按页面报价转账，等待服务端核验 |
| `verified` | 只读查询核验通过，但尚未取得交付声明 |
| `delivery_started` | 已取得唯一交付声明；进程可能执行中或故障，禁止盲目重发 |
| `delivered` | 已由 Shop 成功处理并记录 |
| `manual_review` | 金额/身份/资产/超时不符；人工处理 |
| `delivery_attention` | 外部游戏命令可能已执行部分；先人工审计实际结果 |

> 注意：Shop 1.2.25 的 `Payment::purgePendingPayments()` 会清理两周以上 pending 付款，因此本插件保留自己的独立账本记录；如果原 Shop 付款已不在，**绝不自动创建替代发货订单**。

## 运维与更新

- 计划任务建议在完成联调后由已有 cron 调用 `php artisan jangopay:reconcile --limit=20`。现在按 `last_checked_at` 由早到晚公平轮转，检查 `quoted`、`awaiting_payment`、`verified`、`quote_unknown`；超过本地过期时间的订单进入人工核查而不是自动发货。
- 后台权限使用 `shop.payments`，后台按钮含 CSRF；付款页面使用足够长的随机访问令牌和用户所有权校验。公网 Shop API 回调走其现有 API 路由，不关闭全站 CSRF。
- 停用插件不会删除数据库账本；升级前备份数据库和安全配置。回滚迁移刻意不自动删除支付流水关联表。
- 当前代码不主动发起生产收款、不使用 API Key 调用转出或兑换。正式上线前请依据 `docs/API_CONTRACT.md` 和 `docs/TEST_REPORT.md` 完成测试。

> 代码审查记录见 `docs/CODE_REVIEW_2026-10-14.md`；重要并发问题已修正，但仍需 Codex/真实环境测试。

## 1.0.2 审查回归与风险边界

- 修复 `unknown_creation` 恢复写回的竞争，增加订单 ID 一致性校验；所有关键恢复/报价写回采用数据库条件状态更新。
- 首次没有 nonce 时使用确定性的结账代次，避免两个相同的会话快照各自生成随机结账键；唯一约束冲突时，本地 Shop 数据库写入在事务中回滚。成功结账后旋转代次以允许将来重复购买相同商品。该设计仍须在真实共享会话和数据库环境压力测试。
- 兼容 Shop 的 `shop.user` 访客身份和随机付款访问令牌；记录订单创建时的资产白名单快照，不依赖后续管理员配置变化。
- `quote_unknown` 可在最终付清后通过商户认证查询完成核验；如只确认待付款且取得地址和数额，也可只读恢复报价。不创建第二次付款指示。当前交易列表查询使用上限 3 页、300 条，更多历史记录需人工处理。
- 自动轮转对账避免较早的未支付记录永久占据查询名额。
- 查看 `docs/CODEX_REVIEW_FIXES_2026-10-14.md`。所有新增测试仍是离线/模拟测试，**不是实际 Azuriom+MySQL/PostgreSQL 并发验证**。

## 1.0.3 第二轮 Codex 独立复核修复：会话并发

复核发现：虽然 1.0.2 可以防止双发票，但请求 B 带着旧会话快照结束时，Laravel 仍可能在请求 A 后覆盖访问令牌、购物车清空结果与 checkout nonce。后续点击支付会被持续拒绝。1.0.3 **两层防护**：

- 在 Shop **原生入口** `shop.payments.payment`、`shop.payments.pay`、`shop.offers.buy`、`shop.offers.pay` 上，通过 Laravel `RouteMatched` 提前启用 `Route::block(120, 45)`，让 `StartSession` 在**保存会话之后**再释放会话锁。只在插件自己的付款页加锁不足以解决此问题。
- 新订单令牌通过服务器 `APP_KEY` 对结账键做带用途隔离的 HMAC 计算；数据库仅存令牌 SHA-256 哈希，不再仅依赖容易被旧快照覆盖的 `jangopay_access_*` 会话变量。同一原始会话和购物车的重试可恢复**相同发票**并重新清空被恢复的旧购物车，无需重新开票；浏览器不接触 `APP_KEY`。

**运行环境约束：** 请使用持久化的服务端 Session（file / database / redis 等）及支持跨并发请求原子锁的 Cache store（数据库 / Redis，单机也可使用 file）。`SESSION_DRIVER=cookie/array`、数组或空锁缓存均会拒绝新收款。多机部署必须使用共享 Session 与锁存储；分别存在不同机器上的 `file` 锁不等于全站互斥。Laravel 无法获取锁时不得绕过或创建新发票。实际锁超时、同浏览器并发和会话持久化仍待真实服务器验证。

**升级旧单：** 1.0.2 已存在的随机访问令牌，只要仍留存在原会话，升级后保持有效；历史访问哈希不会被替换。旧令牌在升级前已经丢失的订单，无法根据可猜测的编号重新生成访问权限，必须查远端发票后人工复核，不能为了用户继续支付而直接换令牌或重复创建发票。服务器 `APP_KEY` 轮换也可能令新版结账恢复失效。

**1.0.3 阶段历史状态：** `docs/SESSION_CONCURRENCY_RECHECK_2026-10-14.md`、`docs/TEST_REPORT.md` 记录较早的模拟测试阶段。后续真实框架、商户接口和实付验证以 `docs/LIVE-COMPAT-20261015.md` 及 1.0.4 更新记录为准。
