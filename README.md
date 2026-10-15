# JangoPay Integrations

JangoPay 第三方平台支付插件。每个平台使用独立目录和版本，在这个仓库统一维护。

Payment plugins for third-party platforms, maintained in one repository with separate directories and versions.

## Azuriom Shop

当前源码版本 / Current source version: **1.0.4** · 插件 ID / Plugin ID: `jangopay`

支持 USD 计价的一次性订单，通过 JangoPay 收取商户账户支持的 USDT/USDC，并在服务端核验后调用 Shop 发货。实际可用币种和网络以商户账户、接口及配置为准。

USD-denominated one-time payments using merchant-enabled USDT/USDC networks, with server-side verification and Shop-native fulfillment.

**验收状态 / Acceptance status:** 已验证真实 Azuriom/Shop 集成和一笔 USDT/BSC 支付，修复后通过受控恢复及合成通知重放完成测试积分交付。仍需由使用方验证全新订单在首次官方回调后自动发货。当前版本不代表生产验收已完成或市场审核已通过。

Real Azuriom/Shop integration and one USDT/BSC payment were verified. The paid order completed after audited recovery and synthetic callback replay. Fresh-order automatic fulfillment on the first official callback remains pending owner acceptance; this is not a production acceptance or marketplace approval claim.

| 入口 / Resource | 链接 / Link |
| --- | --- |
| 插件源码 / Source | [azuriom/](azuriom/) |
| 安装 ZIP / Install ZIP | [jangopay-azuriom-1.0.4.zip](https://github.com/jangopay/jangopay-integrations/raw/refs/heads/main/downloads/jangopay-azuriom-1.0.4.zip) |
| 中文商户教程 / Merchant guide | [安装、配置与验收](azuriom/docs/MERCHANT_GUIDE_ZH_CN.md) |
| English guide | [Installation and operation](azuriom/README_EN.md) |
| 测试范围 / Validation | [测试与验收说明](azuriom/docs/VALIDATION.md) |
| 更新记录 / Changelog | [CHANGELOG](azuriom/CHANGELOG.md) |

安装时把 ZIP 内容解压到 Azuriom 的 `plugins/jangopay/`；ZIP 根目录直接包含 `plugin.json`。不要把整个仓库 ZIP 当作插件安装包。服务器需同时满足 Azuriom、Shop、PHP、OpenSSL CLI、Session 和缓存锁要求，详见教程。

Extract the install ZIP into `plugins/jangopay/`; `plugin.json` is at the ZIP root. The repository source archive is not an installable plugin package. Check all host and runtime requirements in the guides.

安装 ZIP 的 SHA-256 / Install ZIP SHA-256:

```text
10BCDC245302F7068CF26256DA7CB88EF16EC930CE73B26C07D6942A9F2B6054
```

## 维护 / Maintenance

确定新增适配后，再加入对应平台目录；各插件分别维护版本和安装包。问题请通过 [Issues](https://github.com/jangopay/jangopay-integrations/issues) 提交，注明平台和版本，不要附带 API Key、私钥、后台密码或完整敏感回调。

Add a platform directory when its integration is actually developed. Version and package each plugin separately. Report issues with the platform and version, without credentials, private keys or sensitive callback payloads.

JangoPay: [官网 / Website](https://www.jangopay.org/) · [API 文档 / API documentation](https://doc.jangopay.org/api-reference)

## 许可证 / Licensing

各平台目录的许可证和第三方声明分别适用，见 [LICENSE.md](LICENSE.md)。后续加入的适配需单独标明授权范围。

Platform-specific licenses and third-party notices apply; see [LICENSE.md](LICENSE.md). Each additional integration must identify its own license scope.
