# 技术与发布说明 · 1.0.2

[产品介绍](../README.zh-CN.md) · [English](TECHNICAL.md)

## 架构

插件运行在商户 WordPress 服务端，通过 WordPress HTTP API 向 `api.jangopay.org` 发起 HTTPS 请求。买家进入 `app.jangopay.org` 托管收银台。通知由店铺本地解密，再通过商户 API 核对发票与交易，核验通过后进入 WooCommerce 的订单处理流程。

支持经典结账、Checkout Blocks、CPT 与 HPOS。声明的最低运行环境为 WordPress 6.4、WooCommerce 8.3、PHP 8.1 和 PHP OpenSSL；具体主机组合需单独验证。

## 源码地图

| 路径 | 用途 |
| :--- | :--- |
| `source/jangopay-for-woocommerce/includes/` | API、支付网关、订单控制、核验、台账和加密通知 |
| `source/jangopay-for-woocommerce/assets/` | 前端脚本、样式和运行时 Logo |
| `source/jangopay-for-woocommerce/tests/` | PHP 回归脚本与模拟环境 |
| `source/qa/` | 离线回归与前端断言 |
| `source/build/` | 原始依赖与构建工具的 Composer 锁文件 |
| `source/upgrade/phpseclib4/` | 隔离构建、命名空间修正和打包脚本 |

在线目录保留核心源码、测试及构建输入。**完整开发交付的 vendor 位于[原始完整源码包](../../downloads/jangopay-woocommerce-1.0.2-source-tests.zip)**，没有以网页目录替代或重新生成原包。安装包另行提供，运行依赖已随包附带。

## 接口与订单核验

使用 API Key 换取外部访问令牌，调用创建收款单、资产查询、发票/交易查询和只读恢复接口。HTTP 请求验证证书，禁止重定向，并限制响应体和超时。

履约前核对已付款发票、绑定的已结算交易、商户订单参考号、USD 金额、允许资产与网络及未变更的商品快照。金额使用整数小数单位校验。创建结果不确定时进行只读恢复，不盲目重发创建请求。

完整字段与核验约束见 [API_CONTRACT.md](../source/jangopay-for-woocommerce/docs/API_CONTRACT.md)。

## 通知加密

事件为 `payment.completed`。RSA-OAEP 的 OAEP 与 MGF1 均使用 SHA-256；AES-256-GCM 使用 12 字节 nonce，并将 ciphertext 最后 16 字节作为认证标签。必须显式确认并设置 `appended16` 格式。

1.0.2 使用私有前缀 `JangoPayWooVendor\\phpseclib4` 下的 phpseclib 4.0.2。安装包在 PHP 内解密，不要求执行外部 OpenSSL 程序。通知仅提供核验线索，不能替代商户 API 的付款证明。

## 构建与许可证

商户运行时要求 PHP ≥8.1；锁定的构建工具要求 PHP ≥8.4.1。不要在插件已隔离的 vendor 目录直接执行 Composer install。按 [BUILD.md](../source/BUILD.md) 在独立构建目录重建，保留原始锁文件、私有命名空间和许可证。

运行依赖：phpseclib 4.0.2、constant_time_encoding v3.1.3、polyfill-php82 v1.43.0。插件 GPL-2.0-or-later，第三方依赖保留 MIT 声明，见 [NOTICES](../source/jangopay-for-woocommerce/NOTICES.txt)。

## 维护与支持

网关 ID：`jangopay_woocommerce`。停用保留订单与台账，清除对账计划。费用、可用地区和商户准入依平台政策。

反馈问题请注明 WordPress/WooCommerce/PHP/插件版本、脱敏订单标识和复现步骤。不要提交私钥、API Key、带订单密钥的完整 URL、客户信息或数据库备份。
