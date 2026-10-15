# Azuriom 1.0.4：测试范围与验收状态

整理日期：2026-10-15。历史报告记录各自阶段，当前状态以本文件、[真实联调记录](LIVE-COMPAT-20261015.md)及[更新记录](../CHANGELOG.md)为准。

## 已完成的验证

| 验证类别 | 数量与范围 |
| --- | --- |
| 自动回归 | 5 组脚本，99 次扣除嵌套重复执行的通过断言；不是 99 笔支付 |
| PHP 语法 | 30 个业务及测试 PHP 文件 |
| 真实 Azuriom 框架集成 | 13 类场景；安装、启停、双进程同会话、会话保存、路由缓存、真实模板/报价、Shop 积分交付、重复核验、CSRF/归属和关闭收款；商户响应使用 fake |
| 公网回调入口 | 9 类路径、请求方法和大小限制检查；2 类加密通路检查 |
| 真实商户 API | 认证、资产、收款单及交易查询、实际收款单和支付报价 |
| 真实支付 | 1 笔 USDT/BSC，3 USDT；商户毛额 3 USD、手续费 0.03 USD、净额 2.97 USD |
| 修复后交付 | 独立核对商户记录后，对该笔未交付订单受控恢复；两次合成加密回调只发放一次 3 点测试积分 |

真实环境：Azuriom 1.2.13、Shop 1.2.25、Laravel 12.62.0、PHP 8.5.11、SQLite、单机 file Session/cache、OpenSSL CLI 3.5.9。插件 PHP 声明不能代替宿主的运行要求；该核心要求 PHP `^8.2`。

## 自动回归的计数与复跑

在 `azuriom/` 目录运行以下独立 PHP 脚本。需要 PHP 和可执行的 OpenSSL CLI；这些脚本使用模拟环境及临时生成的密钥，不需要商户凭据。

```sh
php tests/offline.php
php tests/readiness.php
php tests/state_regression.php
php tests/codex_regressions.php
php tests/session_race_recovery.php
```

| 脚本 | 独立计入断言数 |
| --- | ---: |
| offline.php | 29 |
| readiness.php | 6 |
| state_regression.php | 10 |
| codex_regressions.php | 32 |
| session_race_recovery.php | 22 |
| 合计 | 99 |

`session_race_recovery.php` 先包含并执行 `codex_regressions.php`，因此原始输出共 131 条 PASS，扣除重复执行的 32 条后为 99 次；断言文字标签也有复用。框架集成脚本依赖独立测试站点，未打包进本插件；表中的历史集成结果不代表可以仅用以上脚本复现全部场景。

## 待使用方完成的最终验收

用最终安装包创建一笔全新订单，验证无需人工改状态、无需合成通知，首次官方回调即可令商户收款单 `paid`、交易 `settled`、本地插件 `delivered`、Shop 完成付款，实际权益发放一次；重复通知不得重复发货。

修复后的合成回调重放不是官方发件端重投递，不能当成上述验收已经通过。USDC、其他网络、真实游戏服发货、MySQL/PostgreSQL、Redis、多节点和生产压力未完成实测。详见[商户操作教程](MERCHANT_GUIDE_ZH_CN.md)。
