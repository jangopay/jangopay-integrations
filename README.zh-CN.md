<div align="center">

# JangoPay Integrations

为你的网站接入 JangoPay，支持 USDT / USDC 收款。

[English](README.md) · **简体中文**

[官方网站](https://www.jangopay.org/) · [接口文档](https://doc.jangopay.org/api-reference) · [问题反馈](https://github.com/jangopay/jangopay-integrations/issues)

</div>

## 支付插件

| 插件 | 版本 | 收款方式 | 使用教程 |
| --- | --- | --- | --- |
| [Azuriom Shop](azuriom/) | 1.0.4 | USDT / USDC · USD 计价 | [安装与配置 →](azuriom/docs/MERCHANT_GUIDE_ZH_CN.md) |

可用币种和网络以你的 JangoPay 商户账户为准。

## 开始使用

1. [下载 Azuriom 插件安装包](https://github.com/jangopay/jangopay-integrations/raw/refs/heads/main/downloads/jangopay-azuriom-1.0.4.zip)。
2. 将 ZIP 内容解压到网站的 `plugins/jangopay/` 目录。
3. 按照[商户操作教程](azuriom/docs/MERCHANT_GUIDE_ZH_CN.md)连接商户账户，并完成一笔实付测试。

安装时请使用插件 ZIP。GitHub 的 **Download ZIP** 下载的是整个仓库。

> **上线前验收：** Azuriom 1.0.4 尚待最终验收。请确认全新订单收到首次官方付款通知后，能自动完成发货。详见[测试与验收说明](azuriom/docs/VALIDATION.md)。

<details>
<summary>校验安装包</summary>

`jangopay-azuriom-1.0.4.zip` 的 SHA-256：

```text
10BCDC245302F7068CF26256DA7CB88EF16EC930CE73B26C07D6942A9F2B6054
```

</details>

## 帮助与更新

- [问题反馈](https://github.com/jangopay/jangopay-integrations/issues)：请注明平台与插件版本，不要提交 API Key、私钥或密码。
- [Azuriom 更新记录](azuriom/CHANGELOG.md)：查看版本变化。

## 许可证

各插件附有对应许可证与第三方声明，详见[许可说明](LICENSE.md)。
