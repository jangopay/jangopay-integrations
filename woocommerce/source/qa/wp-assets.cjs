// Synthetic DOM verification for the external review assets; no network or WordPress site.
const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
const path = require('path');
const root = path.join(__dirname, '..', 'jangopay-for-woocommerce', 'assets', 'js');

async function main() {
  let intervalCallback, redirect, refresh = false, clearCount = 0;
  const requests = [];
  const ctx = {
    URLSearchParams,
    window: {
      JPWC_CheckoutConfig: {ajaxUrl: 'https://test.local/wp-admin/admin-ajax.php', params: {action: 'jpwc_status', order: 7, key: 'test-key'}, receivedUrl: 'https://test.local/received', initialState: 'awaiting_payment'},
      setInterval: fn => { intervalCallback = fn; return 4; },
      clearInterval: () => { clearCount++; },
      location: {replace: x => { redirect = x; }, reload: () => { refresh = true; }}
    },
    fetch: async (url, options) => { requests.push({url, options}); return {ok: true, json: async () => ({success: true, data: {paid: true, state: 'completed'}})}; }
  };
  vm.runInNewContext(fs.readFileSync(path.join(root,'checkout.js'), 'utf8'), ctx);
  assert.equal(typeof intervalCallback, 'function');
  intervalCallback();
  await new Promise(setImmediate);
  assert.equal(redirect, 'https://test.local/received');
  assert.equal(refresh, false);
  assert.match(requests[0].url, /action=jpwc_status/);
  assert.equal(requests[0].options.credentials, 'same-origin');
  intervalCallback();
  assert.equal(clearCount, 1);
  console.log('PASS checkout external JS polls authorized local state and redirects once');

  let handlers = [];
  let adminRequests = [];
  let reloaded = false;
  const label = {textContent: ''};
  const reverify = {disabled: false, dataset: {order: '7', nonce: 'n'}, parentNode: {querySelector: () => label}};
  const connectionOutput = {textContent: ''};
  const adminCtx = {
    URLSearchParams,
    window: {JPWC_AdminConfig: {ajaxUrl: 'https://test.local/wp-admin/admin-ajax.php', connectionNonce: 'cn'}, location: {reload: () => {reloaded = true;}}},
    document: {
      addEventListener: (name, fn) => {assert.equal(name, 'click'); handlers.push(fn);},
      getElementById: name => name === 'jpwc-test-output' ? connectionOutput : null
    },
    fetch: async (url, options) => {adminRequests.push({url, options}); return {ok: true, json: async () => ({success: true, data: {state: 'awaiting_payment', message: 'Connected'}})};}
  };
  vm.runInNewContext(fs.readFileSync(path.join(root,'admin.js'), 'utf8'), adminCtx);
  assert.equal(handlers.length, 2);
  const orderEvent = {preventDefault: ()=>{}, target: {closest: selector => selector === '.jpwc-reverify' ? reverify : null}};
  await handlers[0](orderEvent);
  assert.equal(reloaded, true);
  assert.equal(reverify.disabled, false);
  assert.equal(label.textContent, ' Checked: awaiting_payment');
  assert.match(adminRequests[0].options.body.toString(), /action=jpwc_reverify/);
  console.log('PASS admin external JS re-verification remains POST/nonce-based');

  const connButton = {disabled: false};
  const connEvent = {preventDefault: ()=>{}, target: {closest: selector => selector === '#jpwc-test' ? connButton : null}};
  await handlers[1](connEvent);
  assert.equal(connButton.disabled, false);
  assert.equal(connectionOutput.textContent, 'Connected');
  assert.match(adminRequests[1].options.body.toString(), /nonce=cn/);
  console.log('PASS admin connection test remains authenticated read-only POST');
}
main().catch(error => {console.error(error);process.exit(1);});
