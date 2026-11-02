# 商户安装与操作指南 · 1.0.2

[产品介绍](../README.zh-CN.md) · [English](SETUP.md)

## 1. 准备商店

- 使用 HTTPS，准备 WordPress 6.4+、WooCommerce 8.3+、PHP 8.1+ 与 PHP OpenSSL 扩展。
- 店铺订单货币设为 USD。本版用于一次性订单。
- 准备符合平台准入要求的 JangoPay 商户账户与 API 权限。
- 在测试站先备份数据库、插件和支付台账，再进行安装或升级。

## 2. 安装

在 WordPress 后台搜索 **JangoPay for WooCommerce**，或从 [WordPress.org](https://wordpress.org/plugins/jangopay-for-woocommerce/) 获取插件。

手动安装时，在“插件 → 安装插件 → 上传插件”上传[1.0.2 安装包](../../downloads/jangopay-woocommerce-1.0.2.zip)，安装后激活。不要上传完整开发源码包或整个 GitHub 仓库 ZIP。

## 3. 配置服务器与密钥

通过主机的环境变量/密钥管理保存 API Key。生成对应的 RSA 密钥对，私钥保存在 WordPress 目录和 HTTP 网站根目录之外，仅允许需要的服务器账户读取；将公钥填入 JangoPay Console 的回调配置。

在 WordPress 加载前的服务端配置中设置以下常量。替换示例路径，先确认商户通知使用 appended16 格式；仅在目标店铺准备好收款时启用收款开关。

```php
define('JANGOPAY_WC_API_KEY', getenv('JANGOPAY_WC_API_KEY'));
define('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH', '/private/jangopay-webhook.pem');
define('JANGOPAY_WC_WEBHOOK_FRAMING', 'appended16');
define('JANGOPAY_WC_ENABLE_COLLECTION', true);
```

安装包已包含私有命名空间的加密依赖，不要求商户主机安装 Composer 或命令行 OpenSSL。仍需 PHP OpenSSL 扩展。不要将 API Key、私钥或带订单访问密钥的返回链接发送到公开 Issue。

## 4. 开启支付方式

进入 **WooCommerce → 设置 → 支付 → JangoPay**：

1. 配置支付标题与说明。
2. 选择账户实际启用的币种/网络组合，例如 `USDT:bsc`；不要只凭币种名称假设网络可用。
3. 将设置页面显示的 HTTPS 回调地址保存到商户 Console，并配置对应公钥。
4. 执行 API 连接测试，核对配置后启用支付方式。

## 5. 验证付款

用测试站的一次性 USD 订单完成托管收银台实付，确认返回店铺、加密回调、商户 API 核验和订单更新。重复通知不得重复交付。创建发票超时、未收到通知、临时网络故障也要验证恢复流程。

买家回到店铺不代表付款成功。订单金额、资产和原始商品快照需符合插件核验规则；不明确的记录应保留待核对。

## 日常操作与升级

- 每小时的 WordPress cron 对账未完成记录；低流量店铺应配置可靠的 cron 执行。
- 可使用订单页的 JangoPay 重新核验功能排查付款状态。
- 不要删除不确定的支付台账来强制重新创建发票。
- 停用插件会清除定时计划，保留订单及支付台账。
- 曾安装开发包的站点先停用旧插件，不要同时启用两份。网关 ID 仍为 `jangopay_woocommerce`。
- 手动 WooCommerce 退款不会自动退回加密货币，实际退回资金需要单独安排。

## 数据与外部服务

创建发票会发送订单参考号、USD 金额、允许资产、最少订单 ID 元数据，以及含 WooCommerce 订单访问密钥的返回链接。不会在创建发票时发送客户邮箱、收货/账单地址或商品明细。API Key 通过 HTTPS 用于服务端认证；私钥始终留在服务器。

买家进入 `app.jangopay.org` 后与托管页面直接交互。详细触发时机和数据说明见原始 [readme.txt](../source/jangopay-for-woocommerce/readme.txt)。

[服务条款](https://www.jangopay.org/en/legal/terms) · [隐私政策](https://www.jangopay.org/en/legal/privacy) · [WordPress 支持](https://wordpress.org/support/plugin/jangopay-for-woocommerce/)
