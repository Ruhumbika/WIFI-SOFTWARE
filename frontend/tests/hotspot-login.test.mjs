import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
const helper = ts.transpileModule(readFileSync(new URL('../src/utils/hotspotLogin.ts', import.meta.url), 'utf8').replaceAll('export function', 'function'), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
function browser(search = '') {
  const storage = new Map(), forms = [];
  const ctx = vm.createContext({ URL, URLSearchParams, location: { search, href: `https://wifi.95-111-248-145.sslip.io/${search}` }, sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) }, document: { createElement: tag => tag === 'form' ? { fields: [], appendChild(input) { this.fields.push(input) }, submit() { forms.push(this) }, remove() {} } : {}, body: { appendChild() {} } } });
  vm.runInContext(helper, ctx); return { ctx, forms };
}
function functions(file, names) {
  const script = readFileSync(new URL(file, import.meta.url), 'utf8').match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
  const ast = ts.createSourceFile('screen.ts', script, ts.ScriptTarget.Latest, true);
  return ts.transpileModule(ast.statements.filter(n => ts.isFunctionDeclaration(n) && names.includes(n.name?.text)).map(n => n.getText(ast)).join('\n'), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
}
test('captive context survives payment and manual voucher returns', () => {
  const { ctx } = browser('?mac=AA%3ABB%3ACC%3ADD%3AEE%3AFF&link-login-only=http%3A%2F%2F10.10.1.1%2Flogin&link-orig=https%3A%2F%2Fexample.org');
  const first = ctx.captiveContext(); ctx.location.search = '?payment-return=1';
  const restored = ctx.captiveContext(), url = ctx.preserveCaptiveContext(new URL('https://wifi.95-111-248-145.sslip.io/?voucher-return=1'), restored);
  for (const key of ['mac', 'link-login-only', 'link-orig']) { assert.equal(restored.get(key), first.get(key)); assert.equal(url.searchParams.get(key), first.get(key)); }
});
test('manual Connect posts credentials to HTTP fallback from HTTPS portal', async () => {
  const { ctx, forms } = browser();
  Object.assign(ctx, { params: ctx.captiveContext(), voucher: { value: { uuid: 'voucher', code: 'CODE', password: 'PIN' } }, context: {}, headers: () => ({}), run: async fn => fn(), applyState() {}, message: { value: '' }, api: { post: async () => ({ data: { state: 'ready', login_url: 'http://rjayshotspot.net/login' } }) } });
  vm.runInContext(functions('../src/components/portal/VoucherAccessPanel.vue', ['connect', 'finishLogin']), ctx);
  await ctx.connect(); assert.equal(forms.length, 1); assert.equal(forms[0].action, 'http://rjayshotspot.net/login');
  assert.equal(forms[0].fields.find(f => f.name === 'username').value, 'CODE'); assert.equal(forms[0].fields.find(f => f.name === 'password').value, 'PIN');
});
test('paid voucher automatic login submits fallback without captive URL', async () => {
  const { ctx, forms } = browser(); const send = ctx.submitHotspotLogin;
  Object.assign(ctx, { params: ctx.captiveContext(), sendHotspotLogin: send, order: { value: { uuid: 'order', voucher: { recovery_issued: true, code: 'PAID', password: 'PIN' } } }, portalMode: { value: 'buy' }, connectionBusy: { value: false }, recoveryBusy: { value: false }, recoveryDisplay: { value: '' }, connectionState: { value: '' }, deviceMac: '', linkLogin: '', api: { post: async () => ({ data: { state: 'ready', login_url: 'http://rjayshotspot.net/login' } }) } });
  vm.runInContext('let lastPrepareAt=0, prepareAttempts=0;\n' + functions('../src/views/PortalView.vue', ['prepareConnection', 'submitHotspotLogin']), ctx);
  await ctx.prepareConnection(true); assert.equal(forms.length, 1); assert.equal(forms[0].action, 'http://rjayshotspot.net/login'); assert.equal(ctx.connectionState.value, 'connecting');
});
test('canonical redirect passes all three escaped parameters and minimal fallback', () => {
  const html = readFileSync(new URL('../../router/hotspot/login.html', import.meta.url), 'utf8'); let target;
  vm.runInNewContext(html.match(/<script>([\s\S]*?)<\/script>/)[1], { window: { location: { replace: value => target = value } } });
  assert.equal(target, 'https://wifi.95-111-248-145.sslip.io/?mac=$(mac-esc)&link-login-only=$(link-login-only-esc)&link-orig=$(link-orig-esc)'); assert.ok(html.includes('Continue to RJAY WiFi')); assert.ok(!html.includes('<form'));
});
