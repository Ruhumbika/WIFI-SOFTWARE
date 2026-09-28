import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

// Execute the active SFC payment handlers with isolated browser/API boundaries.
const source = readFileSync(new URL('../src/views/PortalView.vue', import.meta.url), 'utf8');
const script = source.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
const ast = ts.createSourceFile('PortalView.ts', script, ts.ScriptTarget.Latest, true);
const handlers = ast.statements.filter(n => ts.isFunctionDeclaration(n) && ['requestPayment', 'continuePayment'].includes(n.name?.text));
assert.equal(handlers.length, 2);
const code = ts.transpileModule(handlers.map(n => n.getText(ast)).join('\n'), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;

for (const handler of ['requestPayment', 'continuePayment']) {
  test(`${handler}: confirmed push stays on the portal and polls`, async () => {
    let polls = 0;
    let navigations = 0;
    const confirmed = { uuid: 'test-order', checkout_state: 'pin_required', payment: { checkout_url: 'https://snippe.me/checkout/test' } };
    const context = vm.createContext({
      order: { value: confirmed }, loading: { value: false }, error: { value: '' },
      api: { post: async () => ({ data: { order: confirmed, checkout_url: confirmed.payment.checkout_url } }) },
      startPoll: () => polls++, window: { location: { assign: () => navigations++ } },
      sessionStorage: { setItem() {} }, URL, deviceMac: '', linkLogin: '', linkOrig: '',
    });
    vm.runInContext(code, context);
    await context[handler]();
    assert.equal(polls, 1);
    assert.equal(navigations, 0);
    assert.equal(context.order.value.checkout_state, 'pin_required');
  });
}
