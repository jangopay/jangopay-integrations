# 2026-10-15 本机真实 API 联调修复

此源码为基于已验证 1.0.3 修复基线整理的 1.0.4 发布包，plugin.json 已同步为 1.0.4；安装 ZIP 的 plugin.json 位于根目录，尚未上传市场。原用户 ZIP 和历史测试包未被覆盖。此次整理仅调整版本、文档及打包结构，支付业务 PHP 代码保持不变；最终新订单验收由使用方执行。

修复四处兼容问题：

1. 空 POST 请求序列化为 `{}`，而不是 `[]`。原认证请求实际返回 HTTP 400；同一 Key 修复后认证、资产查询、商户收款单列表和交易列表均返回 HTTP 200。
2. Webhook 允许解密正文不重复包含 `event_type`，从已验证的 envelope 补全；若正文显式包含冲突事件仍拒绝。官方正文示例不包含此冗余字段。
3. 实际收款单将 USD 3.00 返回为 `3.00000000`。金额比较允许分以下的额外零，不接受非零分以下金额，也不做金额四舍五入。
4. 商户承担手续费时，真实收款单的 `payedAmount` 是扣费后的净额：本次 3.00 USD 毛额、0.03 USD 手续费、2.97 USD 净额。只有已验证的商户收款单为 paid、交易为 settled、订单及币种网络一致、双方明确标记商户承担手续费、交易 USD 毛额等于订单金额且净额加手续费严格相等时，才接受该差额。使用八位小数整数账本单位，支持分以下手续费，不使用浮点金额比较；欠付、错误费率算术、缺少手续费字段及整数溢出仍拒绝。

官方 API Reference 的收款单终态是 `paid`，交易终态为 `settled`；`payedAmount` 为计价币种累计到账金额。官方独角数卡接入实现使用 RSA OAEP SHA256/MGF1-SHA256、AES-256-GCM，认证标签为 ciphertext 末尾 16 字节。

独立本机测试配置已采用以下设置，保持创建收款单关闭：

```text
JANGOPAY_ENABLE_PAYMENTS=0
JANGOPAY_FINAL_INVOICE_STATUSES=paid
JANGOPAY_WEBHOOK_TAG_FORMAT=appended16
JANGOPAY_RSA_PRIVATE_KEY_PATH=<本机私钥绝对路径>
```

API Key 保存在 Windows 当前用户 DPAPI 加密文件中，启动时仅注入本机 PHP 服务进程；私钥、API Key、令牌、用户数据库和便携 OpenSSL 均不包含在源码 ZIP 中。部署到其他服务器仍需配置 API Key、私钥、上述环境变量，以及 PATH 中可运行的 OpenSSL 命令行工具。仅写入 Laravel .env 未必能满足插件的 getenv() 读取方式，必须核验实际运行进程的环境。

检查：29 项离线加密/金额/资产测试通过；readiness、state_regression、session_race_recovery、codex_regressions 四组回归通过，包含 9 组手续费接受/拒绝案例；Laravel HTTP fake 验证空认证正文为 `{}` 且非空订单数据不变；公网 HTTPS 合成通知成功解密后返回 422 unknown_invoice，篡改密文为 503 verification_failed；公网路径、请求方法及大小限制检查通过。

已完成一笔真实 USDT/BSC 支付：用户支付 3 USDT，商户接口确认收款单 paid、交易 settled、净入账 2.97 USD。原通知使订单进入 paid_amount_unconfirmed 人工核查，未重复支付。手续费兼容修复后，操作员独立复核商户记录，仅将这笔未发货订单恢复为可校验状态；通过公网入口发送本机合成的加密通知，实际 Shop 完成订单并增加 3 点本地测试积分。相同通知再次重放后积分不再增加。

限制：修复后的两次回调重放由本机生成，不能称为官方发件端重新投递；本次没有证明全新订单在无操作员恢复的情况下首次回调自动发货成功。应再用最终包验证一笔新订单的完整自动流程。未测试 MySQL/Redis/多节点、真实游戏服命令发放或大规模压力。

来源（2026-10-15 核对）：

- https://github.com/jangopay/jangopay-docs/blob/main/README.zh-CN.md
- https://doc.jangopay.org/api-reference
- https://doc.jangopay.org/integration-guide?section=production
- https://doc.jangopay.org/dujiaoka/app/Http/Controllers/Pay/EpusdtController.php
- https://github.com/JangoPayOrganization/JangoPay_SDK_PHP/tree/7928c1b3fdb2cbe0ff34004b9a5c030df8f802ce
