"""Run reviewed local-only test fixtures; keep all outputs and exit codes."""
import collections, json, os, pathlib, re, subprocess, time, argparse, shutil
parser=argparse.ArgumentParser(description='Local synthetic plugin tests; requires PHP OpenSSL and development OpenSSL CLI on PATH.')
parser.add_argument('--php',default='php');parser.add_argument('--ini');parser.add_argument('--node',default='node')
args=parser.parse_args()
ROOT=pathlib.Path(__file__).resolve().parent
PLUGIN=ROOT.parent/'jangopay-for-woocommerce'
PHP=args.php;INI=args.ini
prefix=[PHP]+(['-c',INI] if INI else [])
EVIDENCE=ROOT/'results';EVIDENCE.mkdir(exist_ok=True)
env=dict(os.environ)
rows = []
commands = [('lint-'+str(i), prefix+[ '-l', str(path)])
            for i,path in enumerate(sorted(PLUGIN.rglob('*.php')), 1)]
commands += [('suite-'+path.stem, prefix+[ str(path)]) for path in sorted((PLUGIN/'tests').glob('*.php'))]
commands += [('hosted-'+scenario, prefix+[ str(PLUGIN/'tests/fixtures/hosted-checkout-controller.php'), scenario])
             for scenario in ['hosted_get','legacy_post','legacy_quoted','resume','return_paid','quote_unknown','wrong_key']]
commands += [('buyer-'+scenario, prefix+[ str(PLUGIN/'tests/fixtures/fourth-review-buyer.php'), scenario])
             for scenario in ['get_reserved','retry_reserved','reject_unknown','reject_nonce']]
commands += [('blocks-'+scenario, prefix+[ str(PLUGIN/'tests/fixtures/blocks-load-order.php'), scenario])
             for scenario in ['woo-first','plugin-first']]
commands += [('js-syntax-'+path.stem, [args.node, '--check', str(path)]) for path in sorted((PLUGIN/'assets/js').glob('*.js'))]
commands += [('js-asset-behavior', [args.node, str(ROOT/'wp-assets.cjs')])]
assert len({x[0] for x in commands}) == len(commands)
for label,cmd in commands:
    start = time.monotonic()
    result = subprocess.run(cmd, cwd=PLUGIN, env=env, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=60)
    log = result.stdout+result.stderr
    (EVIDENCE/(label+'.txt')).write_text(log, encoding='utf-8')
    rows.append({'case': label, 'exit_code': result.returncode, 'pass_markers': len(re.findall(r'^PASS(?:\s|:)', log, re.M)),
                 'fail_markers': len(re.findall(r'^FAIL(?:\s|:)', log, re.M)), 'seconds': round(time.monotonic()-start,3)})
    if result.returncode:
        print(json.dumps({'failed_case': label, 'tail': log[-1400:]}, ensure_ascii=True), flush=True)
summary = {'steps':len(rows), 'php_lint_files':sum(x['case'].startswith('lint-') for x in rows),
           'suite_scripts':sum(x['case'].startswith('suite-') for x in rows),
           'failed_steps':sum(x['exit_code']!=0 or x['fail_markers']>0 for x in rows),
           'pass_markers':sum(x['pass_markers'] for x in rows), 'results':rows}
(EVIDENCE/'summary.json').write_text(json.dumps(summary,indent=2), encoding='utf-8')
print(json.dumps({k:v for k,v in summary.items() if k!='results'}))
raise SystemExit(1 if summary['failed_steps'] else 0)
