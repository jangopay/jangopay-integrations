# JangoPay for WooCommerce

[English](README.md) · [开发文档](https://doc.jangopay.org/documentation) · [官方支持](https://www.jangopay.org/zh/about)

**V1.0 · 首个公开版本**

为 WooCommerce 的一次性 USD 订单提供 USDT / USDC 收款。买家进入 JangoPay 托管收银台选择账户已开通的币种和网络。支持经典结账、Checkout Blocks、CPT 和 HPOS。

## 商户安装

要求 HTTPS、WordPress 6.4+、WooCommerce 8.3+、PHP 8.1+ 和 PHP OpenSSL 扩展。最低版本需在实际主机组合上验证。准备符合平台准入要求的 JangoPay 商户账户和 API 权限。

1. 备份测试站及支付台账，在“插件 → 安装插件”上传提交安装包并激活。完整开发源码包不用于后台直接安装。
2. 在 WordPress 加载前的服务端配置中添加以下内容。API Key 通过主机的环境变量/密钥管理设置；替换示例私钥路径，并先向平台确认 appended16 回调格式。

```php
define('JANGOPAY_WC_API_KEY', getenv('JANGOPAY_WC_API_KEY'));
define('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH', '/private/jangopay-webhook.pem');
define('JANGOPAY_WC_WEBHOOK_FRAMING', 'appended16');
define('JANGOPAY_WC_ENABLE_COLLECTION', true);
```

私钥必须位于 WordPress 和网站公开目录之外，并限制文件读取权限。插件在本机完成 RSA-OAEP-SHA256 / AES-256-GCM 解密，不要求服务器安装 openssl 命令或启用进程执行函数。不要将密钥上传仓库或发送给客服。

3. 进入“WooCommerce → 设置 → 支付 → JangoPay”，仅在目标站启用收款，填写账户已开通的币种/网络，例如 `USDT:bsc`。将界面显示的 HTTPS 回调地址保存到商户后台。
4. 测试 API 连接，再做一笔真实托管收银台付款，核对回跳、加密回调、重复通知与恢复，验收完成后再用于正式收款。

## 日常操作

浏览器回跳不等于付款成功，必须通过商户 API 核验。发票创建超时后保留台账，进行只读恢复或人工核查，不要删记录后重复创建。订单页可执行 JangoPay 重新核验；每小时的定时任务处理未完成记录，低流量网站应配置可靠的 WordPress cron。

仅支持一次性 USD 订单，不支持订阅、网关自动退款、出款及其他法币订单。WooCommerce 手动退款仅记录本地账务，不实际退回数字货币。停用插件会清除定时任务，保留订单及支付台账。

使用过开发包的站点：先停用旧插件，再启用本公开包，不要同时启用两份。目录和翻译域改为 `jangopay-for-woocommerce`；网关 ID `jangopay_woocommerce`、配置常量及台账表保持一致。先备份，缺少商品快照的历史未付发票需人工处理。

## 外部服务与数据

插件通过 HTTPS 请求 `api.jangopay.org`，在创建/安全恢复发票、连接测试、商户重新核验、回跳核验、回调核验及每小时对账时发送所需请求。API Key 作为服务端认证凭据发送并换取令牌；私钥不发送给平台。

创建发票发送商户订单参考号、USD 金额、币种网络限制、最少的订单 ID 元数据，以及含 WooCommerce 订单访问密钥的回跳链接。恢复和核验发送发票/交易/参考号及分页字段；保留的旧报价恢复也可能发送币种、网络和会话字段。不在创建发票时发送客户邮箱、收货/账单地址或商品明细。平台可以看到店铺服务器的请求 IP。

买家跳转 `app.jangopay.org` 后直接与平台交互，平台会依据自身政策处理支付信息、买家 IP 和浏览器请求信息。详细触发时机和数据清单见 [readme.txt](readme.txt) 的 External services。

[服务条款](https://www.jangopay.org/en/legal/terms) · [隐私政策](https://www.jangopay.org/en/legal/privacy)。费用、可用地区及商户准入以平台政策为准。

## 交付文件

完整源码包保留测试及构建说明；WordPress 提交安装包只含运行文件、必要依赖和许可证。目录图标、横幅及截图单独提供，上传 SVN 顶层 `assets/`，与 `trunk/` 并列。插件内实际用于结账与设置页的本地 logo 保留。不打包 .po/.mo 翻译文件。

许可证 GPL-2.0-or-later，依赖保留 MIT 声明；不代表 WordPress 或 WooCommerce 官方背书。
