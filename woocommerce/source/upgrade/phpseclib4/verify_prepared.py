#!/usr/bin/env python3
"""Check correctness of the prepared upgrade inputs, without claiming v4 runtime success."""
import importlib.util
import json
import pathlib
import shutil
import subprocess
import tempfile

here = pathlib.Path(__file__).resolve().parent
manifest = json.loads((here / 'composer.json').read_text())
assert manifest['require']['phpseclib/phpseclib'] == '4.0.2'
assert manifest['config']['platform']['php'] == '8.1.0'
spec = importlib.util.spec_from_file_location('builder', here / 'build_release.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
with tempfile.TemporaryDirectory() as d:
    dest = pathlib.Path(d) / 'jangopay-for-woocommerce'
    shutil.copytree(here.parents[1] / 'jangopay-for-woocommerce', dest, ignore=shutil.ignore_patterns('vendor'))
    builder.update_source(pathlib.Path(d))
    webhook = (dest / 'includes/class-jpwc-webhook.php').read_text()
    assert webhook.count('JangoPayWooVendor\\phpseclib4') == 3
    assert 'JangoPayWooVendor\\phpseclib3' not in webhook
    assert 'withMGFHash(\'sha256\')' in webhook and 'withHash(\'sha256\')' in webhook
    for f in ['includes/class-jpwc-webhook.php', 'tests/public-release.php', 'jangopay-for-woocommerce.php']:
        subprocess.run(['php', '-l', str(dest / f)], check=True, capture_output=True)
    assert 'Stable tag: 1.0.2' in (dest / 'readme.txt').read_text()
    assert 'getPrettyVersion(\'phpseclib/phpseclib\')===\'4.0.2\'' in (dest / 'tests/public-release.php').read_text()
print('PASS: upgrade manifest, PHP 8.1 constraint, scoped v4 source preview, RSA OAEP-SHA256 parameters, version checks, and PHP syntax. NOT a phpseclib4 runtime test.')
