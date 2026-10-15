# JangoPay for Azuriom Shop

[English](README_EN.md) · **简体中文** · [返回插件首页](../README.zh-CN.md)

为 Azuriom Shop 接入 USDT / USDC 收款，适用于 USD 计价的一次性订单。

## 准备条件

- 已启用 Shop 的 Azuriom 网站，以及可用的公网 HTTPS 地址。
- JangoPay 商户账户，已开通需要使用的币种与网络。
- 满足 Azuriom 和 Shop 要求的 PHP 及扩展。
- PHP 可以执行 OpenSSL 命令行，且允许使用 `proc_open()`。
- 持久化的服务端 Session，以及支持原子锁的缓存。

已验证环境为 Azuriom 1.2.13、Shop 1.2.25、PHP 8.5.11、SQLite、单机 file Session/cache。该 Azuriom 版本要求 PHP `^8.2`，部署前请由技术确认运行环境。

## 1. 安装插件

1. 备份网站和数据库。
2. [下载 1.0.4 安装包](https://github.com/jangopay/jangopay-integrations/raw/refs/heads/main/downloads/jangopay-azuriom-1.0.4.zip)。
3. 将 ZIP 内容解压到 `plugins/jangopay/`，确认文件位于 `plugins/jangopay/plugin.json`。
4. 在 Azuriom 后台先启用 Shop，再启用 JangoPay。

安装包没有额外的 `jangopay/` 外层目录，不要重复嵌套。GitHub 的整个仓库 ZIP 不用于直接安装。

## 2. 连接商户账户

创建本站专用的 API Key，授予创建收款单及查询订单、交易所需权限。插件不需要提现或兑换权限。

由技术生成通知加密所需的 RSA 密钥对。私钥放在网站公开目录之外，仅允许 PHP 服务账户读取。

由技术为网站进程和定时对账进程配置以下环境变量：

| 配置项 | 填写内容 |
| --- | --- |
| `JANGOPAY_API_KEY` | 本站专用的商户 API Key |
| `JANGOPAY_RSA_PRIVATE_KEY_PATH` | RSA 私钥绝对路径 |
| `JANGOPAY_FINAL_INVOICE_STATUSES` | `paid` |
| `JANGOPAY_WEBHOOK_TAG_FORMAT` | `appended16` |
| `JANGOPAY_ENABLE_PAYMENTS` | 初始设为 `0`，具备测试条件后设为 `1` |

插件通过 `getenv()` 读取配置。只写入 Laravel 的 `.env` 未必会传给 PHP-FPM 或计划任务，需要技术确认实际进程中的设置已生效。

在 JangoPay「开发者设置 → 回调配置」填写 RSA **公钥**，以及网站 JangoPay 配置页显示的回调地址。通常格式为：

```text
https://你的网站/api/shop/payments/jangopay/notification
```

API Key 和私钥不要写入源码、支付方式表单、截图或工单。密钥生成及服务器配置的完整步骤见[商户操作教程](docs/MERCHANT_GUIDE_ZH_CN.md)。

## 3. 添加支付方式

1. 确认 Shop 使用 USD 计价。
2. 在 Shop 的支付方式设置中添加 JangoPay。
3. 填写允许的币种和网络，例如 `USDT:bsc`。每个组合都需要先确认商户账户实际可用。
4. 打开 `/admin/jangopay/payments`，点击 **Test API connection (read-only)** 测试连接。

连接成功仅表示接口可以访问，回调和发货仍需实付验证。

请使用服务端持久化 Session。cookie/array Session 及 array/null 锁缓存无法创建新收款单；多台服务器需要共享 Session 和锁。

## 4. 完成上线前测试

为受控测试订单开启收款，严格按照付款页显示的币种、网络、数量和地址付款，不要直接根据 USD 价格推算转账数量。

确认全新订单无需人工改状态、无需合成通知，收到首次官方付款通知后便能自动完成；实际权益发放一次，重复通知不会重复发货。

**1.0.4 尚待上述最终验收。** 已验证一笔真实 USDT/BSC 支付，该订单经受控恢复及合成通知重放后完成交付。USDC、其他网络、真实游戏服发货、生产数据库和多节点尚未完成实测。详见[测试与验收说明](docs/VALIDATION.md)。

## 日常使用

在 `/admin/jangopay/payments` 查看付款状态。

| 状态 | 处理方式 |
| --- | --- |
| `quoted` | 等待客户按付款指示支付。 |
| `delivered` | 核对客户是否收到对应权益。 |
| `unknown_creation` / `quote_unknown` | 先核对远端已有记录，不要盲目重新付款。 |
| `manual_review` | 由技术核查订单与付款信息。 |
| `delivery_started` / `delivery_attention` | 先核对实际发货结果，避免重复发放。 |

测试完成后，可由技术安排 `php artisan jangopay:reconcile --limit=20` 定时对账。该命令查询已有付款，核验成功时可能触发 Shop 发货。

设置 `JANGOPAY_ENABLE_PAYMENTS=0` 可停止创建新收款单，已有订单仍可能继续核验及发货。升级前备份数据库，并保留未完成订单的记录。

## 帮助

[完整商户教程](docs/MERCHANT_GUIDE_ZH_CN.md) · [更新记录](CHANGELOG.md) · [问题反馈](https://github.com/jangopay/jangopay-integrations/issues) · [许可证](LICENSE)
