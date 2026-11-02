# JangoPay for WooCommerce — phpseclib 4.0.2 构建工程

JangoPay for WooCommerce 1.0.2 使用私有命名空间下的 phpseclib 4.0.2。运行依赖已随独立 WordPress 安装包提供。

## 构建环境

商户运行时要求 PHP 8.1+；锁定的构建工具要求 PHP 8.4.1+。构建需要 Composer 2、Python、Node.js 及用于生成加密向量的 OpenSSL CLI。商户安装包的回调解密在 PHP 内完成，不依赖命令行 OpenSSL。

保留 `build/crypto/` 与 `build/scoper/` 的 Composer 锁文件和许可证。不要在部署后的私有化 vendor 目录执行 Composer install；在独立目录准备原始依赖后按构建脚本生成发行包。

## 构建命令

在完整开发源码包根目录运行，按实际环境替换路径：

```text
python upgrade/phpseclib4/build_release.py --php /path/to/php85 --php-ini /path/to/build.ini --runtime-php /path/to/php81 --runtime-ini /path/to/runtime.ini --composer-phar /path/to/composer.phar --node /path/to/node
```

脚本负责依赖准备、PHP-Scoper 命名空间隔离、动态类名和 Composer 映射修正、自动加载生成及发行包组装。构建步骤失败时停止打包。

## 运行依赖

- phpseclib/phpseclib 4.0.2
- paragonie/constant_time_encoding v3.1.3
- symfony/polyfill-php82 v1.43.0

RSA 使用 `JangoPayWooVendor\\phpseclib4` 命名空间，OAEP 与 MGF1 均为 SHA-256；AES-GCM 由 PHP OpenSSL 处理。保持完整私有依赖和第三方许可声明。

[完整构建说明](../../BUILD.md) · [商户安装指南](../../../docs/SETUP.zh-CN.md) · [WordPress.org](https://wordpress.org/plugins/jangopay-for-woocommerce/)
