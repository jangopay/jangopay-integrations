#!/usr/bin/env python3
"""Fix runtime-generated internal class names that cannot be statically scoped."""
import pathlib, re, sys

if len(sys.argv) != 2:
    raise SystemExit('usage: scope_dynamic_names.py <scoped-vendor-dir>')
root = pathlib.Path(sys.argv[1]) / 'phpseclib/phpseclib/phpseclib'
if not root.is_dir():
    raise SystemExit('No phpseclib 4 source found in scoped vendor')
count = 0
for file in root.rglob('*.php'):
    source = file.read_text(encoding='utf-8')
    patched = re.sub(r"(['\"])(\\{0,2})phpseclib4\\", lambda m: m[1] + m[2] + 'JangoPayWooVendor\\phpseclib4\\', source)
    if patched != source:
        file.write_text(patched, encoding='utf-8')
        count += 1
print(f'Updated {count} scoped phpseclib4 dynamic namespace files')
