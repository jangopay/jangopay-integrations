import pathlib,re,sys
root=pathlib.Path(sys.argv[1])/'phpseclib/phpseclib/phpseclib'
assert root.is_dir(), 'Pass the scoped vendor directory'
for f in root.rglob('*.php'):
    s=f.read_text(encoding='utf8')
    patched=re.sub(r"(['\"])(\\{0,2})phpseclib3\\",lambda m:m[1]+m[2]+'JangoPayWooVendor\\\\phpseclib3\\',s)
    if patched!=s:f.write_text(patched,encoding='utf8')
