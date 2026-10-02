import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
const helper = ts.transpileModule(readFileSync(new URL('../src/utils/hotspotLogin.ts', import.meta.url), 'utf8').replaceAll('export function', 'function'), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
function browser(search = '') {
  const storage = new Map(), forms = [];
  const ctx = vm.createContext({ URL, URLSearchParams, location: { search, href: `https://wifi.95-111-248-145.sslip.io/${search}` }, sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) }, document: { createElement: tag => tag === 'form' ? { fields: [], appendChild(input) { this.fields.push(input) }, submit() { forms.push(this) }, remove() {} } : {}, body: { appendChild() {} } } });
  vm.runInContext(helper, ctx); return { ctx, forms, storage };
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
  Object.assign(ctx, { params: ctx.captiveContext(), canConnect: { value: true }, token: { value: 'token' }, verified: { value: false }, expiresAt: { value: Date.now()+60000 }, voucher: { value: { uuid: 'voucher', code: 'CODE', password: 'PIN' } }, context: {}, headers: () => ({}), run: async fn => fn(), applyState() {}, message: { value: '' }, api: { post: async () => ({ data: { state: 'ready', login_url: 'http://rjayshotspot.net/login' } }) } });
  vm.runInContext(functions('../src/components/portal/VoucherAccessPanel.vue', ['connect', 'finishLogin']), ctx);
  await ctx.connect(); assert.equal(forms.length, 1);
  const dst = new URL(forms[0].fields.find(f => f.name === 'dst').value);
  assert.equal(dst.searchParams.get('connected'), '1'); assert.equal(dst.searchParams.get('voucher-return'), '1'); assert.equal(dst.searchParams.get('voucher-uuid'), 'voucher'); assert.equal(forms[0].action, 'http://rjayshotspot.net/login');
  assert.equal(forms[0].fields.find(f => f.name === 'username').value, 'CODE'); assert.equal(forms[0].fields.find(f => f.name === 'password').value, 'PIN');
});
test('paid voucher automatic login submits fallback without captive URL', async () => {
  const { ctx, forms } = browser(); const send = ctx.submitHotspotLogin;
  Object.assign(ctx, { params: ctx.captiveContext(), sendHotspotLogin: send, order: { value: { uuid: 'order', voucher: { recovery_issued: true, code: 'PAID', password: 'PIN' } } }, portalMode: { value: 'buy' }, connectionBusy: { value: false }, recoveryBusy: { value: false }, recoveryDisplay: { value: '' }, connectionState: { value: '' }, deviceMac: '', linkLogin: '', api: { post: async () => ({ data: { state: 'ready', login_url: 'http://rjayshotspot.net/login' } }) } });
  vm.runInContext('let lastPrepareAt=0, prepareAttempts=0;\n' + functions('../src/views/PortalView.vue', ['prepareConnection', 'submitHotspotLogin']), ctx);
  await ctx.prepareConnection(true); assert.equal(forms.length, 1); assert.equal(forms[0].action, 'http://rjayshotspot.net/login'); assert.equal(ctx.connectionState.value, 'connecting');
});
test('current HTTPS captive redirect preserves context in automatic and manual fallback URLs', () => {
  const html = readFileSync(new URL('../../router/hotspot/login.html', import.meta.url), 'utf8')
    .replaceAll('$(hostname)', 'hgd09sryy9d.sn.mynetname.net')
    .replaceAll('$(mac-esc)', 'AA%3ABB%3ACC%3ADD%3AEE%3AFF')
    .replaceAll('$(link-orig-esc)', 'https%3A%2F%2Fexample.org');
  let target; const anchor = {}; const ctx = vm.createContext({ encodeURIComponent, window: { location: { replace: value => target = value } }, document: { getElementById: () => anchor } });
  for (const script of html.matchAll(/<script>([\s\S]*?)<\/script>/g)) vm.runInContext(script[1], ctx);
  const url = new URL(target);
  assert.equal(url.origin, 'https://wifi.95-111-248-145.sslip.io');
  assert.equal(url.searchParams.get('mac'), 'AA:BB:CC:DD:EE:FF');
  assert.equal(url.searchParams.get('link-login-only'), 'https://hgd09sryy9d.sn.mynetname.net/login');
  assert.equal(url.searchParams.get('link-orig'), 'https://example.org');
  assert.equal(anchor.href, target); assert.ok(!html.includes('<form'));
});

const manualSource = readFileSync(new URL('../src/components/portal/VoucherAccessPanel.vue', import.meta.url), 'utf8');
function manualBrowser(state = 'online', savedUuid = 'same-voucher') {
  const env = browser('?connected=1&voucher-return=1&voucher-uuid=same-voucher&mac=AA%3ABB%3ACC%3ADD%3AEE%3AFF');
  const { ctx, storage } = env; const calls = [];
  storage.set('rjay_voucher_access', JSON.stringify({ uuid: savedUuid, token: 'same-token', expires: Date.now() + 60000, management: false }));
  storage.set('rjay_current_order', 'old-paid-order'); storage.set('rjay_order_token', 'old-token');
  Object.assign(ctx, { params: ctx.captiveContext(), voucher: { value: null }, token: { value: '' }, expiresAt: { value: 0 }, verified: { value: false }, state: { value: '' }, message: { value: '' }, nowTick: { value: 0 }, props: { mode: 'recovery' }, context: { device_mac: 'AA:BB:CC:DD:EE:FF' }, headers: () => ({ 'X-Voucher-Recovery-Token': ctx.token.value }), forget: () => { ctx.voucher.value = null; ctx.token.value = ''; }, applyState: value => { ctx.state.value = value; }, api: { get: async (url, options) => {
    calls.push({ url, options }); assert.ok(!url.includes('/orders/'));
    return { data: url.endsWith('/connection') ? { state } : { uuid: 'same-voucher', code: 'VD-FW5NBT', status: 'ready' } };
  } } });
  vm.runInContext(functions('../src/components/portal/VoucherAccessPanel.vue', ['restoreVoucher', 'check']), ctx);
  return { ...env, calls };
}
for (const state of ['online', 'offline']) {
  test(`manual return verifies same voucher/token/device and preserves ${state}`, async () => {
    const { ctx, calls } = manualBrowser(state); await ctx.restoreVoucher();
    assert.equal(ctx.voucher.value.uuid, 'same-voucher'); assert.equal(ctx.state.value, state);
    const connection = calls.find(call => call.url.endsWith('/connection'));
    assert.equal(connection.url, '/public/vouchers/same-voucher/connection');
    assert.equal(connection.options.headers['X-Voucher-Recovery-Token'], 'same-token');
    assert.equal(connection.options.params.device_mac, 'AA:BB:CC:DD:EE:FF');
    assert.ok(calls.every(call => !call.url.includes('old-paid-order')));
  });
}
for (const savedUuid of ['other-voucher', null]) {
  test(`manual return refuses ${savedUuid ? 'mismatched' : 'missing'} saved voucher without paid fallback`, async () => {
    const { ctx, calls, storage } = manualBrowser('online', savedUuid);
    if (!savedUuid) storage.delete('rjay_voucher_access');
    await ctx.restoreVoucher(); assert.equal(calls.length, 0); assert.equal(ctx.voucher.value, null);
  });
}
test('manual return blocks old order restoration even with an explicit order parameter', () => {
  const { ctx, storage } = browser('?voucher-return=1&connected=1&order=old-paid-order');
  storage.set('rjay_current_order', 'old-paid-order');
  assert.equal(ctx.orderToRestore(ctx.captiveContext(), 'rjay_current_order'), null);
  assert.equal(ctx.orderToRestore(new URLSearchParams('order=current-paid-order'), 'rjay_current_order'), 'current-paid-order');
  const portal = readFileSync(new URL('../src/views/PortalView.vue', import.meta.url), 'utf8');
  assert.ok(portal.includes('const savedOrder = orderToRestore(params, orderStorageKey)'));
});
test('shared return builder preserves context and keeps paid/manual identity independent', () => {
  const { ctx } = browser('?mac=AA&link-login-only=https%3A%2F%2Fhgd09sryy9d.sn.mynetname.net%2Flogin&link-orig=https%3A%2F%2Fexample.org&order=old');
  const params = ctx.captiveContext(), manual = ctx.hotspotReturnUrl(params, { voucherUuid: 'same-voucher' });
  for (const key of ['mac', 'link-login-only', 'link-orig']) assert.equal(manual.searchParams.get(key), params.get(key));
  assert.equal(manual.searchParams.get('connected'), '1'); assert.equal(manual.searchParams.get('voucher-return'), '1'); assert.equal(manual.searchParams.has('order'), false);
  ctx.location.href = manual.toString(); const paid = ctx.hotspotReturnUrl(params, { orderUuid: 'paid' });
  assert.equal(paid.searchParams.get('order'), 'paid'); assert.equal(paid.searchParams.get('connected'), '1'); assert.equal(paid.searchParams.has('voucher-return'), false); assert.equal(paid.searchParams.has('voucher-uuid'), false);
});
test('online Connect handler refuses another submission', async () => {
  const { ctx } = browser(); let requests = 0;
  Object.assign(ctx, { canConnect: { value: false }, run: async fn => fn(), api: { post: async () => { requests++; } } });
  vm.runInContext(functions('../src/components/portal/VoucherAccessPanel.vue', ['connect']), ctx);
  await ctx.connect(); assert.equal(requests, 0);
});

for (const state of ['online', 'offline']) {
  test(`${state} renders honest connection state and correct Connect availability`, async () => {
    const ast = ts.createSourceFile('panel.ts', manualSource.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1], ts.ScriptTarget.Latest, true);
    const declaration = ast.statements.find(n => ts.isVariableStatement(n) && n.declarationList.declarations.some(d => d.name.getText(ast) === 'canConnect'));
    const { ctx } = browser(); Object.assign(ctx, { computed: fn => ({ get value() { return fn(); } }), voucher: { value: { uuid: 'same-voucher', code: 'VD-FW5NBT', status: 'ready', registered: true } }, state: { value: state } });
    vm.runInContext(ts.transpileModule(declaration.getText(ast).replace('const canConnect', 'var canConnect'), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText, ctx);
    assert.equal(Boolean(ctx.canConnect.value), state !== 'online');
    const app = createSSRApp({ template: manualSource.slice(manualSource.indexOf('<template>') + 10, manualSource.lastIndexOf('</template>')), components: { SupportAction: { template: '<span></span>' }, SignalEye: { template: '<span>signal</span>' }, VoucherCard: { props: ['showConnect'], template: '<div>Ready voucher<button v-if="showConnect">Connect</button></div>' } }, setup: () => ({ compact: true, supportPhone: null, token: '', context: { device_mac: null }, mode: 'recovery', t: value => value, formatDate: () => '', message: state === 'offline' ? 'Not connected. You can try again.' : '', state, requestStatus: 'idle', issuedPin: '', voucher: ctx.voucher.value, nowTick: Date.now(), canConnect: ctx.canConnect.value, busy: false, verified: false, connect() {}, forget() {} }) });
    const html = await renderToString(app);
    if (state === 'online') { assert.ok(html.includes('Connected to Wi-Fi.')); assert.ok(!html.includes('Ready voucher')); assert.ok(!html.includes('>Connect</button>')); }
    else { assert.ok(html.includes('Not connected.')); assert.ok(html.includes('>Connect</button>')); assert.ok(!html.includes('Connected to Wi-Fi.')); }
  });
}

for (const state of ['online', 'offline']) {
  test(`paid verification remains independent and honest for ${state}`, async () => {
    const { ctx } = browser(); let queried;
    Object.assign(ctx, { order: { value: { uuid: 'paid-order' } }, connectionCheckBusy: { value: false }, connectionState: { value: 'checking' }, deviceMac: 'AA:BB:CC:DD:EE:FF', returnedFromLoginAt: Date.now(), stopPoll() {}, startPoll() {}, startConnectionPoll() {}, stopConnectionPoll() {}, api: { get: async url => { queried = url; return { data: { state } }; } } });
    vm.runInContext(functions('../src/views/PortalView.vue', ['verifyConnection']), ctx);
    await ctx.verifyConnection(); assert.equal(queried, '/public/orders/paid-order/connection');
    assert.equal(ctx.connectionState.value, state === 'online' ? 'connected' : 'checking');
  });
}
