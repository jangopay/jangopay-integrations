# Azuriom 部署与运行说明

## 安装与配置

从 [Azuriom 市场](https://market.azuriom.com/resources/256)获取插件，或使用仓库的独立安装包。将安装包内容放入 `plugins/jangopay/`，启用 Shop 与 JangoPay。

按[商户操作教程](MERCHANT_GUIDE_ZH_CN.md)设置服务端 API Key、RSA 私钥、公钥回调配置、USD 计价及允许资产。PHP 版本应满足安装的 Azuriom 核心和 Shop 要求。

## 订单与权益

收款核验需同时匹配商户收款单、已结算交易、订单参考号、USD 金额及允许的币种网络。核验通过后由 Shop 执行对应权益发放。

保留持久化支付记录，使用原子锁和条件状态更新，避免重复履约。已有付款、异常订单和部分交付应按实际记录核查，不能通过删除台账或盲目重复创建收款单处理。

多节点部署需共享 Session 与锁缓存。外部游戏命令存在副作用，恢复前先核对实际发放结果。

## 维护资料

[产品介绍](../README.zh-CN.md) · [安装配置](../README_ZH_CN.md) · [接口与核验规则](API_CONTRACT.md) · [回归脚本说明](TEST_REPORT.md) · [更新记录](../CHANGELOG.md)
