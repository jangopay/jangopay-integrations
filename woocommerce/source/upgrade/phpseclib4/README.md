# JangoPay for WooCommerce — phpseclib 4.0.2 升级工程

**状态：已完成源码适配与自动构建流程，尚未构建/验收 4.0.2 安装包。**

这份工程附带原本已经通过离线测试的 **1.0.1 技术源码**，并新增可复现的 **phpseclib 4.0.2 编译、命名空间隔离、回调测试与安全发布脚本**。不能把原有 `jangopay-for-woocommerce/vendor/`（仍是 3.0.57）直接上传并声称已升级。也不能仅修改 `composer.json` 而保留旧 vendor。

## 已核对的官方接口

- phpseclib 4.0.2 最低 PHP 8.1，与本插件声明一致。
- `phpseclib4\Crypt\PublicKeyLoader::loadPrivateKey()`、`phpseclib4\\Crypt\\RSA\\PrivateKey`、`RSA::ENCRYPTION_OAEP`、`withHash()`、`withMGFHash()`、`withPadding()` 与 `decrypt()` 均在 4.0.2 源码中存在。由于 4.x 的命名空间更改，插件 RSA 解密类在构建时从 `JangoPayWooVendor\\phpseclib3` 调整为 `JangoPayWooVendor\\phpseclib4`；AES-GCM 解密仍走 PHP OpenSSL。
- 新增运行时依赖由 Composer 根据 4.0.2 的正式元数据解析，不擅自伪造锁文件。phpseclib 4.0.2 还依赖 `symfony/polyfill-php82` 等软件包。

官方参考：https://phpseclib.com/docs/intro/migrating 以及 https://github.com/phpseclib/phpseclib/tree/4.0.2

## 构建和验收

在**可以访问 Packagist / GitHub 的开发电脑或 CI**上，需要 `php`（8.1+、启用 OpenSSL）、`composer` 2、`python3`、`node` 与用于测试的 `openssl` CLI。进入本完整源码包的根目录，运行：

```bash
python3 upgrade/phpseclib4/build_release.py
```

脚本将自动：

1. 使用固定 `phpseclib/phpseclib=4.0.2` 创建隔离的 Composer 工程，下载经过 Composer 解析的真实依赖；原来的 v3 vendor 不参与构建。
2. 使用原项目锁定的 PHP-Scoper 0.18.x 重新生成 `JangoPayWooVendor` 私有命名空间和 Composer autoload，不允许与站点其他插件的 phpseclib 冲突。
3. 修改 JangoPay 的 RSA 解密引用及版本、`composer.json`/`composer.lock`、README/许可证与发布元数据；同步更新原有 `public-release` 版本测试。
4. 在真正的 v4 runtime 下运行 **完整离线回归**（含基于独立 OpenSSL 生成的 RSA-OAEP-SHA256 + AES-GCM 数据）、WooCommerce Blocks 测试，并检查 `Composer\InstalledVersions` 实际安装的版本。
5. **全部成功才生成** `dist-phpseclib4/` 内的 WordPress 正式上传 ZIP 与完整技术源码 ZIP。任何依赖获取、作用域隔离、运行时加载、测试失败都会停止且不生成发行包。

**不要**把 `upgrade/phpseclib4/composer.json` 直接放进已部署的插件当作升级，也不要运行 Composer 替换现有私有化 vendor；应该只使用构建成功且经过测试的安装 ZIP。

## 本次已执行的检查

- 既有 1.0.1 基线：`python qa/run_offline.py`，**480 步 / 688 PASS / 0 失败**；`node qa/blocks.cjs`，**16/16**。
- 构建脚本 Python 语法通过；RSA v4 的源码改写预览通过 PHP 语法检查。
- 当前沙箱没有 Composer，也无法解析访问 GitHub / Packagist，执行构建脚本得到 `MISSING TOOL: composer`。**所以并没有实际下载、构建或验证 phpseclib 4.0.2**。不能把这里的通过断言称为 4.x 回归通过。

## 不能忽略的上线边界

即使最终打包成功，仍需 WordPress/WooCommerce 测试站激活、支付回调的真实商户密文解密、HPOS/CPT 订单到账、真实 PHP-FPM/PHP 8.1 环境验证；这不是通过离线加密测试就能替代的。WordPress.org 的 JangoPay 品牌 DNS 验证是另一个独立步骤。
