# Azuriom 商户：JangoPay 安装与操作教程

适用对象：运营 Azuriom 商店、希望收取 USDT/USDC 的商户，以及协助配置服务器的技术人员。  
适用安装包：`jangopay-azuriom-1.0.4.zip`。  
文档日期：2026-10-15。

## 1. 这个插件负责什么

客户在 Azuriom Shop 购买商品，订单以 USD 计价。插件向 JangoPay 创建收款单，客户选择商户允许的币种和网络，页面显示准确转账数量及收款地址。付款后，插件通过服务端商户查询核实，再调用 Shop 原有发货流程。插件不提供自动退款、提现、兑换、钱包管理或订阅。

## 2. 商户与技术分别准备什么

| 商户准备 | 技术准备 |
| --- | --- |
| 能登录的 JangoPay 商户账户 | 已安装的 Azuriom 与 Shop，数据库已备份 |
| 专门用于本站的 API Key | 符合核心/Shop 要求的 PHP、HTTPS 出网、OpenSSL CLI |
| 登录 Azuriom 后台并管理支付的权限 | 稳定公网 HTTPS 网站及可达的回调地址 |
| 一个测试商品及可用于测试的钱包 | API Key、RSA 私钥的服务器安全配置 |

API Key、RSA 私钥不要放进聊天、工单、截图、Git、支付网关 JSON 或源码包。需要给技术配置时，使用双方认可的密钥交付方式；商户在 JangoPay 后台只填写 RSA 公钥。

## 3. 安装插件（技术完成）

1. 备份 Azuriom 代码、数据库和原支付设置。升级时保留现有 APP_KEY、订单账本及未完成订单所需配置。
2. **本次 1.0.4 安装 ZIP 的 `plugin.json` 直接位于根目录，没有 `jangopay` 外层文件夹。** 手动安装时，先在 Azuriom 的 `plugins` 下准备 `jangopay` 目录，再把 ZIP 内的文件与子目录解压进去。
3. 检查文件实际位置：

   ```text
   Azuriom站点/
   └─ plugins/
      └─ jangopay/
         ├─ plugin.json
         ├─ src/
         ├─ resources/
         └─ database/
   ```

   不要出现 `plugins/jangopay/jangopay/plugin.json`。提交市场时上传 `jangopay-azuriom-1.0.4.zip`，不要上传包含交接文档和测试证据的整份技术交接 ZIP。市场账号填写的信息和该版本号应保持一致。

4. 在 Azuriom 后台启用 Shop，再启用 JangoPay。技术也可在站点根目录执行：

   ```bash
   php artisan plugin:enable shop
   php artisan plugin:enable jangopay
   ```

5. 检查插件迁移和静态资源正常。已有 Azuriom/Shop 环境安装本插件，不需要为了它改主项目 Composer 依赖；新装站点仍需先满足核心与 Shop 的依赖。
6. 保持 `JANGOPAY_ENABLE_PAYMENTS=0`，先完成后续配置。

已验证环境是 Azuriom 1.2.13、Shop 1.2.25、PHP 8.5.11、SQLite。当前核心要求 PHP `^8.2`；其他环境由技术确认，不要只按插件声明的 PHP ≥ 8.1 配服务器。

## 4. 创建 JangoPay API Key（商户完成）

1. 登录自己的 JangoPay 商户账户，打开 API Key 管理。菜单名称可能随后台版本变化。
2. 新建专用于本站的 Key，例如 `Azuriom-测试站` 或 `Azuriom-正式站`，测试与正式站分开。
3. 授予创建收款单及查询订单/交易所需权限；本轮使用的权限标识为 `invoice:create`、`order:read`。以后台实际权限项为准。
4. 本插件不需要提现、转出或兑换权限，不必为它开启这些能力。
5. 安全保存完整 Key，由技术注入服务器环境。不要自行 Base64 编码、添加空格或改写 Key。

## 5. 准备 RSA 密钥并配置回调

### 5.1 技术生成并保管密钥

每个环境使用独立 RSA 密钥。私钥放在 Web 根目录之外，由 PHP 运行账户读取。以下是命令示例，目录所有权及权限由技术按服务器实际情况设置：

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /srv/azuriom-secrets/jangopay-private.pem
openssl pkey -in /srv/azuriom-secrets/jangopay-private.pem -pubout -out /srv/azuriom-secrets/jangopay-public.pem
```

执行前先由技术创建安全目录；不要覆盖已有且仍在使用的私钥。私钥通常设置为仅服务账户可读（如 0600），目录限制访问。公钥可以交给商户填写，私钥不上传到 JangoPay。

### 5.2 商户填写 JangoPay 回调配置

进入 JangoPay「开发者设置 → 回调配置」：

| 配置项 | 填写方式 |
| --- | --- |
| 回调 URL | `https://你的站点域名/api/shop/payments/jangopay/notification` |
| RSA 公钥 | 技术提供的完整 PEM 公钥，包括 BEGIN/END 两行 |

优先复制 Azuriom 后台 JangoPay 支付配置页显示的实际回调 URL，并由技术确认域名正确。回调要能从公网接收平台 POST，不应要求浏览器登录、验证码或交互式验证。

本次临时 `trycloudflare.com` 地址仅用于本机测试，不是你的正式回调地址。生产部署请使用稳定域名。用浏览器打开回调 URL 属于 GET，出现错误不能直接说明回调失败；连通性应由技术按正确 POST 格式检查。

## 6. 配置服务器参数（技术完成）

| 参数 | 初始值/要求 |
| --- | --- |
| `JANGOPAY_API_KEY` | 本站独立 API Key，使用服务器密钥配置 |
| `JANGOPAY_RSA_PRIVATE_KEY_PATH` | 本环境 RSA 私钥的绝对路径 |
| `JANGOPAY_FINAL_INVOICE_STATUSES` | `paid`，这是收款单终态；交易终态是 `settled` |
| `JANGOPAY_WEBHOOK_TAG_FORMAT` | `appended16` |
| `JANGOPAY_ENABLE_PAYMENTS` | 初始 `0`，具备测试条件后才临时设为 `1` 创建测试单 |

其他运行要求：

- PHP 的 OpenSSL/cURL 及核心要求的扩展可用，HTTPS CA 配置正常。
- PHP 运行账户能执行 `openssl pkeyutl`，并允许 `proc_open()`；只启用 PHP OpenSSL 扩展还不够。
- 使用持久化服务端 Session 和支持原子锁的缓存。单机已测 file；多机需要共享 Session/锁。
- `SESSION_DRIVER=cookie`、`array` 或进程内 `array/null` 锁缓存会被插件拒绝新增收款。
- 商店按 USD 计价；不要把启用“站内余额结算”当作本次外部支付配置。由技术核对商店计价与支付模式。

**关键区别：插件使用 `getenv()` 读取配置。** 只把这些行写进 Laravel `.env`，未必会传给 PHP-FPM 或计划任务。请技术检查实际 Web 进程、CLI 对账进程的环境，调整服务配置并重载相应进程；不要用公开 `phpinfo()` 页面检查密钥。

## 7. 在 Azuriom 后台添加支付方式（商户完成）

1. 登录 Azuriom 后台，进入 Shop/商店的 Payment Gateways/支付方式设置。
2. 新建或编辑一个 JangoPay 支付方式。当前已验证的是一个商户配一个 JangoPay 网关。
3. 名称可填 `USDT / USDC 支付`，实际只启用 BSC 时也可以填 `USDT（BSC）`。
4. 在 `Allowed USDT/USDC networks` 输入框填写允许的币种网络组合。

   本轮验证组合：

   ```text
   USDT:bsc
   ```

   多项用英文逗号分隔，例如 `USDT:bsc,USDC:ethereum`；添加前必须由技术通过当前商户 payment-assets 接口确认 enabled、invoicePaymentEnabled、最低金额及网络。示例中的其他组合不代表本轮已实付验证。

5. 首次接入建议将 Shop 网关额外手续费设为 0，方便核对商品金额与平台手续费；需要加价时另行验收。
6. 保存配置，打开 JangoPay 的 Payment review/付款审核页面：

   ```text
   https://你的站点域名/admin/jangopay/payments
   ```

7. 点击 **Test API connection (read-only)**。成功会显示返回的资产数量；失败时交给技术检查 Key、权限和网络。该按钮只测试认证及资产查询，不会自动证明回调、实付和发货都正常。

## 8. 完成上线前小额实付验收

先选一个只发放测试权益的商品，避免在接入阶段直接执行不可逆的游戏指令。由技术确认私钥、回调和运行环境就绪后，再将创建收款开关设为 `1`。

1. 用实际购买人进入 Azuriom Shop，把测试商品加入购物车。
2. 选择 JangoPay 支付，进入插件付款页，再选择 `USDT:bsc`。
3. 点击确认，等待页面显示 **准确 USDT 数量、BSC 网络和收款地址**。未取得明确报价时不要付款，也不要按 USD 数字自行推算稳定币数量。
4. 钱包/交易所选择同一网络；BSC 主网通常标为 BSC/BEP20。复制本订单地址，确保接收方实际收到页面要求的数量，钱包另备链上手续费。
5. 每张报价只付款一次；超过报价有效期先联系技术核对，不继续沿用旧地址。收款单有效期与选币交易报价有效期可能不同，以当前报价为准。
6. 付款后保存链上 **TxHash**。EVM/BSC 交易哈希是 `0x` 后 64 位十六进制字符；`0x` 后 40 位通常是地址，不能当作交易哈希。
7. 等待官方通知及服务端核验。最终验收应同时满足：JangoPay 收款单 `paid`、交易 `settled`、本地插件 `delivered`、Shop 付款完成、实际权益发放一次。
8. 由技术在最终包上核实：新订单不用人工改状态、不依赖本机合成通知，首次官方回调即可自动完成。随后检查重复通知不会再次发货。

2026-10-15 本轮 USDT/BSC 接口最低收款量为 2 USDT，测试订单为 3 USD、实际支付 3 USDT。**这是当时的观测值，不是固定最低金额或汇率承诺**；你的订单以实时资产限制和支付报价为准。

## 9. 商户每天怎么看订单

打开 Payment review 页面，主要看 State、Last issue 和关联 Shop payment/Invoice。

| 状态 | 含义 | 商户操作 |
| --- | --- | --- |
| `awaiting_payment` | 收款单已创建，等待选择币种网络 | 客户尚未拿到完整报价，不催促转账 |
| `quoted` | 已生成明确数量及地址 | 客户可在报价有效期内按指示付款 |
| `verified` | 商户查询已通过，等待交付声明 | 交给技术检查后续发货，不手工重复发权益 |
| `delivered` | Shop 已完成处理 | 核对实际权益是否到账 |
| `reserved` / `creating` | 创建订单处于处理中 | 不反复点击或再建替代单 |
| `unknown_creation` | 远端是否创建成功还不明确 | 技术只读核对远端订单；不要让客户重复付款 |
| `quoting` / `quote_unknown` | 支付指示生成中或结果未知 | 不凭猜测的地址付款，不盲目重复选币请求 |
| `manual_review` | 金额、归属、超时等需要核查 | 提供订单号/TxHash给技术，先核实，不直接改已支付 |
| `delivery_started` | 已取得发货声明，可能正在执行或中断 | 先确认实际发货结果，避免重复发放 |
| `delivery_attention` | Shop/外部交付异常，可能部分执行 | 技术审计实际执行情况，再决定补偿方式 |

**Reverify** 会核验可处理的订单，但不是“强制改为成功”按钮，也不会自动把人工核查/交付异常状态重置。页面刷新主要读取本站状态，不会每次都直接查询 JangoPay。

### 手续费为什么可能导致实收少于商品金额

本次商品 3 USD、客户付 3 USDT，平台收取商户 0.03 USD 手续费，商户净入账 2.97 USD。最终包会核对平台的毛额、手续费、净额、最终状态与订单归属，符合条件时按已足额支付处理。

不要仅看到净额小于商品金额就要求客户补付，也不要仅看截图或浏览器返回页面就手动标记成功。以服务端商户记录和插件核验结果为准。

## 10. 技术设置定时对账

技术可在已配置同一密钥及环境的站点根目录运行：

```bash
php artisan jangopay:reconcile --limit=20
```

由现有计划任务按商户流量和接口限制安排频率，并避免重叠运行。该命令对 JangoPay 做只读查询，但**查询成功会触发本地 Shop 发货**。它不替代公网回调，也不自动解除所有人工核查状态。

## 11. 常见问题

| 问题 | 处理方式 |
| --- | --- |
| 插件启用后仍无法创建订单 | 技术检查开关、真实进程环境变量、有效 RSA 私钥、终态/tag 配置、Session 和锁存储 |
| API 测试失败 | 检查完整 Key、权限、API 出网、CA；不要把 Key 发到日志或截图里 |
| 付款页某些提示是英文 | 当前中文目录与站点语言标识存在已知差异，技术后续可对齐，不代表支付失败 |
| 已付款但页面没有变更 | 保存订单号和 TxHash，让技术核对商户 paid/settled、回调投递、插件状态和实际权益；不要重复转账 |
| 状态为 manual_review，点 Reverify 没变化 | 这类状态有意阻止盲目自动恢复，需要技术审计；本轮的受控恢复脚本不是通用商户按钮 |
| 回调返回 503 | 可能是头部、密文、私钥或商户查询失败，也可能还未达到终态；让技术查脱敏错误，不直接改成固定返回成功 |
| 支付页面没有二维码 | 当前版提供文字地址和准确数量，未提供二维码组件 |
| 需要退款/提现 | 本插件不实现这些操作，按商户平台及人工处理流程执行 |

## 12. 暂停收款、升级和交接

- 暂停创建新单：技术设置 `JANGOPAY_ENABLE_PAYMENTS=0` 并确保运行进程生效。已有待付款订单的回调及核验仍可能继续，不能把此开关当成“停止所有支付处理”。
- 升级前备份代码、数据库、APP_KEY、凭据/私钥和未完成订单记录；升级不要随意轮换 APP_KEY 或覆盖私钥。
- 回滚由技术结合支付及交付记录处理。不要把已发生付款的数据库恢复到付款前快照后重新自动发货。
- 原始商户密钥、私钥和 Windows DPAPI 文件不能当作普通源码文件群发。DPAPI 文件受原 Windows 用户保护，换机器不能靠复制文件直接使用。

**交给技术的支持信息：** 插件源码包名称/版本、Azuriom/Shop/PHP版本、订单号、交易号、TxHash、发生时间、当前 State/Last issue、是否已经发放权益。不要附带 API Key、私钥、后台密码或完整敏感回调正文。

参考：[Azuriom 插件说明](https://azuriom.com/en/docs/plugins)、[JangoPay API 文档](https://doc.jangopay.org/api-reference)、[JangoPay 回调指南](https://doc.jangopay.org/integration-guide?section=production)。本教程依据当前交付代码和实际联调结果编写；后台菜单变化时以相应版本实际界面为准。
