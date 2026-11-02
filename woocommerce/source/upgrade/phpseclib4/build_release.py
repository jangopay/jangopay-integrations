#!/usr/bin/env python3
"""Fail-closed phpseclib 4.0.2 scoped rebuild for JangoPay for WooCommerce.

Requires build PHP 8.4.1+, test PHP 8.1, Composer 2 and verified HTTPS downloads.
No production credentials, WordPress installation, or merchant APIs are touched.
Only outputs installable ZIPs *after* actual v4 runtime regression passes.
"""
from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PLUGIN = ROOT / "jangopay-for-woocommerce"
PREFIX = "JangoPayWooVendor"
VERSION = "4.0.2"


def run(cmd: list[str], *, cwd: pathlib.Path | None = None, timeout: int = 800) -> None:
    print("+", " ".join(cmd), flush=True)
    subprocess.run(cmd, cwd=cwd, check=True, timeout=timeout)


def edit(path: pathlib.Path, old: str, new: str, expected: int = 1) -> None:
    s = path.read_text(encoding="utf-8")
    if old not in s and new in s:
        return
    hits = s.count(old)
    if hits != expected:
        raise RuntimeError(f"Unexpected source at {path}: {old!r} appears {hits}, expected {expected}")
    path.write_text(s.replace(old, new), encoding="utf-8")


def update_source(stage: pathlib.Path) -> None:
    target = stage / "jangopay-for-woocommerce"
    edit(target / "includes/class-jpwc-webhook.php", r"JangoPayWooVendor\phpseclib3", r"JangoPayWooVendor\phpseclib4", 3)
    edit(target / "tests/public-release.php", "3.0.57", VERSION)
    edit(target / "tests/public-release.php", "1.0.1", "1.0.2", 3)
    edit(target / "jangopay-for-woocommerce.php", "1.0.1", "1.0.2", 2)
    edit(target / "readme.txt", "Stable tag: 1.0.1", "Stable tag: 1.0.2")
    changelog = target / "CHANGELOG.md"
    if "## 1.0.2" not in changelog.read_text(encoding="utf-8"):
        changelog.write_text(changelog.read_text(encoding="utf-8").replace(
        "# Changelog\n", "# Changelog\n\n## 1.0.2\n\nRebuild privately scoped phpseclib to 4.0.2 (PHP 8.1+), keeping RSA-OAEP-SHA256 and AES-256-GCM webhook verification. Regressions must pass on the actual rebuilt v4 dependency.\n", 1), encoding="utf-8")
    return None


def update_notices(target: pathlib.Path, packages: list[dict]) -> None:
    names = []
    for p in packages:
        lic = p.get("license") or []
        if not any("MIT" in x for x in lic):
            raise RuntimeError("Unexpected dependency license; review manually: " + p["name"] + " " + str(lic))
        names.append(f"{p['name']} {p['version']}")
    summary = ", ".join(names)
    for filename in ("NOTICES.txt", "THIRD_PARTY_NOTICES.md"):
        file = target / filename
        existing = file.read_text(encoding="utf-8")
        existing = re.sub(r"phpseclib/phpseclib 3\.0\.57, paragonie/constant_time_encoding v3\.1\.3 and paragonie/random_compat v9\.99\.100",
                          summary, existing, count=1)
        file.write_text(existing, encoding="utf-8")


def zip_tree(out: pathlib.Path, tree: pathlib.Path, keep) -> None:
    out.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=8) as z:
        for file in sorted(tree.rglob("*")):
            if file.is_file() and keep(file.relative_to(tree)):
                z.write(file, file.relative_to(tree).as_posix())


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--output-dir", default=str(ROOT / "dist-phpseclib4"))
    ap.add_argument("--composer", default="composer")
    ap.add_argument("--composer-phar")
    ap.add_argument("--php", default="php")
    ap.add_argument("--php-ini")
    ap.add_argument("--runtime-php", required=True)
    ap.add_argument("--runtime-ini")
    ap.add_argument("--node", default="node")
    args = ap.parse_args()
    if not PLUGIN.exists():
        raise SystemExit("Missing original 1.0.1 full source: " + str(PLUGIN))
    for exe in (args.php, args.runtime_php, args.node) + (() if args.composer_phar else (args.composer,)):
        if not shutil.which(exe):
            raise SystemExit(f"MISSING TOOL: {exe}. Install it on an internet-enabled build machine. No release written.")
    php = [args.php] + (["-c", args.php_ini] if args.php_ini else [])
    runtime = [args.runtime_php] + (["-c", args.runtime_ini] if args.runtime_ini else [])
    composer = php + [args.composer_phar] if args.composer_phar else [args.composer]
    if int(subprocess.check_output(php + ["-r", "echo PHP_VERSION_ID;"]).decode()) < 80401:
        raise SystemExit("Build PHP 8.4.1+ required by the locked build tools. No release written.")
    runtime_version = int(subprocess.check_output(runtime + ["-r", "echo PHP_VERSION_ID;"]).decode())
    if not 80100 <= runtime_version < 80200:
        raise SystemExit("A real PHP 8.1 test runtime is required. No release written.")
    out = pathlib.Path(args.output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="jpwc-phpseclib4-") as tmpname:
        tmp = pathlib.Path(tmpname)
        stage = tmp / "project"
        # Preserve existing full project and QA; do not ship stale v3 in finished ZIP.
        shutil.copytree(ROOT, stage, ignore=shutil.ignore_patterns("dist-phpseclib4", "__pycache__", "*.pyc", ".git"))
        target = stage / "jangopay-for-woocommerce"
        crypto = tmp / "crypto-build"
        crypto.mkdir()
        shutil.copy2(HERE / "composer.json", crypto / "composer.json")
        run(composer + ["update", "--working-dir=" + str(crypto), "--no-dev", "--prefer-dist", "--no-progress", "--no-plugins", "--no-scripts", "--no-interaction"], timeout=1200)
        run(composer + ["audit", "--working-dir=" + str(crypto), "--locked", "--no-dev", "--no-plugins", "--no-scripts", "--no-interaction"])
        lock = json.loads((crypto / "composer.lock").read_text(encoding="utf-8"))
        packages = lock["packages"]
        found = [p for p in packages if p["name"] == "phpseclib/phpseclib"]
        if len(found) != 1 or found[0]["version"] != VERSION:
            raise RuntimeError("Unexpected resolved phpseclib version; refusing to build")
        # Separate build tool: pinned scoper lock from the original source.
        scoper = tmp / "scoper-build"
        shutil.copytree(ROOT / "build/scoper", scoper)
        run(composer + ["install", "--working-dir=" + str(scoper), "--no-dev", "--prefer-dist", "--no-progress", "--no-plugins", "--no-scripts", "--no-interaction"], timeout=1200)
        entry = scoper / "vendor/humbug/php-scoper/bin/php-scoper"
        if not entry.exists():
            raise RuntimeError("PHP-Scoper tool not found")
        scoped = tmp / "crypto-scoped"
        run(php + [str(entry), "add-prefix", "--working-dir=" + str(crypto), "--prefix=" + PREFIX, "--output-dir=" + str(scoped), "--no-config", "--no-interaction"], timeout=1200)
        scoped_vendor = scoped / "vendor"
        if not scoped_vendor.is_dir():
            raise RuntimeError("Scoper did not produce vendor directory")
        run([sys.executable, str(HERE / "scope_dynamic_names.py"), str(scoped_vendor)])
        infra = crypto / "vendor/composer/InstalledVersions.php"
        if infra.exists():
            (scoped_vendor / "composer").mkdir(exist_ok=True)
            shutil.copy2(infra, scoped_vendor / "composer/InstalledVersions.php")
        # Composer rebuilds its namespace map from installed.json, not the rewritten PHP files.
        installed_file = scoped_vendor / "composer/installed.json"
        installed = json.loads(installed_file.read_text(encoding="utf-8"))
        for package in installed["packages"]:
            mappings = package.get("autoload", {}).get("psr-4", {})
            if mappings:
                package["autoload"]["psr-4"] = {
                    key if key.startswith(PREFIX + "\\") else PREFIX + "\\" + key: value
                    for key, value in mappings.items()
                }
        installed_file.write_text(json.dumps(installed, indent=2), encoding="utf-8")
        # Scope rewriting must be fully followed by Composer autoload regeneration.
        for manifest in ("composer.json", "composer.lock"):
            shutil.copy2(crypto / manifest, scoped / manifest)
        run(composer + ["dump-autoload", "--working-dir=" + str(scoped), "--no-dev", "--classmap-authoritative", "--no-scripts", "--no-plugins", "--no-interaction"], timeout=250)
        if not (scoped_vendor / "autoload.php").exists():
            raise RuntimeError("Scoped autoloader missing")
        # Fail if legacy v3 namespace is still present in the rebuilt scoped tree.
        text = (scoped_vendor / "composer/autoload_psr4.php").read_text(encoding="utf-8")
        if "JangoPayWooVendor\\\\phpseclib4" not in text or "phpseclib3" in text:
            raise RuntimeError("Invalid scoped phpseclib4 autoload namespace: " + text[:400])
        shutil.rmtree(target / "vendor")
        shutil.copytree(scoped_vendor, target / "vendor")
        for p in (target, stage / "build/crypto"):
            for manifest in ("composer.json", "composer.lock"):
                shutil.copy2(crypto / manifest, p / manifest)
        update_source(stage)
        update_notices(target, packages)
        # Keep developer documentation in agreement with the actual installed lock.
        for name in ("BUILD.md", "jangopay-for-woocommerce/docs/BUILD.md"):
            doc = stage / name
            if doc.exists():
                v = doc.read_text(encoding="utf-8")
                v = v.replace("phpseclib/phpseclib 3.0.57", "phpseclib/phpseclib 4.0.2")
                v = v.replace("phpseclib 3.0.57", "phpseclib 4.0.2")
                start = v.find("**Dependency-review limitation:**")
                if start >= 0:
                    v = v[:start].rstrip() + "\n"
                v += "\n\n## 1.0.2 upgrade result\n\nThis package contains a real Composer-resolved and PHP-Scoper-isolated phpseclib 4.0.2 runtime. Build-time tests with independent OpenSSL-generated webhook vectors passed. The complete Composer lock file is retained.\n"
                doc.write_text(v, encoding="utf-8")
        # Update the mirrored notices inside developer docs.
        shutil.copy2(target / "THIRD_PARTY_NOTICES.md", target / "docs/THIRD_PARTY_NOTICES.md")
        # Independent OpenSSL-generated RSA-OAEP-SHA256/AES-256-GCM fixtures included in QA.
        for label, executable, ini in (("php85", args.php, args.php_ini), ("php81", args.runtime_php, args.runtime_ini)):
            command = [sys.executable, str(stage / "qa/run_offline.py"), "--php", executable, "--node", args.node]
            if ini:
                command += ["--ini", ini]
            run(command, cwd=stage, timeout=1200)
            shutil.copytree(stage / "qa/results", out / "evidence" / label, dirs_exist_ok=True)
        run([args.node, str(stage / "qa/blocks.cjs")], cwd=stage, timeout=120)
        # Sanity: packaged plugin must include the real 4.0.2 dependency, not old or rewritten metadata.
        run(runtime + ["-r", "require '" + (target / "vendor/autoload.php").as_posix().replace("'", "\\'") + "'; if (\\Composer\\InstalledVersions::getPrettyVersion('phpseclib/phpseclib') !== '4.0.2') exit(5); if (!class_exists('JangoPayWooVendor\\\\phpseclib4\\\\Crypt\\\\PublicKeyLoader')) exit(6); if (class_exists('phpseclib4\\\\Crypt\\\\PublicKeyLoader') || class_exists('JangoPayWooVendor\\\\phpseclib3\\\\Crypt\\\\PublicKeyLoader')) exit(7);"], timeout=30)
        # Runtime allowlist: all plugin files except developer-only source, tests and QA.
        def publish_allowed(rel: pathlib.Path) -> bool:
            parts = rel.parts
            return (len(parts) >= 2 and parts[0] == "jangopay-for-woocommerce"
                    and not any(p in ("tests", "docs", "build", "qa", ".github", "__pycache__") for p in parts)
                    and rel.suffix.lower() not in (".sh", ".bat", ".cmd", ".ps1", ".py", ".pyc", ".phar", ".po", ".mo")
                    and parts[1] not in ("README.md", "README.zh-CN.md", "THIRD_PARTY_NOTICES.md"))
        zip_tree(out / "JangoPay_for_WooCommerce_1.0.2_phpseclib4_WordPress.zip", stage, publish_allowed)
        zip_tree(out / "JangoPay_for_WooCommerce_1.0.2_phpseclib4_完整源码.zip", stage, lambda rel: not any(p in ("results", "__pycache__") for p in rel.parts))
        print("SUCCESS: real 4.0.2 dependency scoped, loaded, test suite passed, release ZIPs created", flush=True)


if __name__ == "__main__":
    main()
