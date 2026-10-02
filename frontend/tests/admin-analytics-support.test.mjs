import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
function source(path) { return readFileSync(new URL(path,import.meta.url),'utf8'); }
function handlers(path,names) {
  const ast=ts.createSourceFile('view.ts',source(path).match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1],ts.ScriptTarget.Latest,true);
  return ts.transpileModule(ast.statements.filter(n=>ts.isFunctionDeclaration(n)&&names.includes(n.name?.text)).map(n=>n.getText(ast)).join('\n'),{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText;
}
const utils=ts.transpileModule(source('../src/utils/paymentReporting.ts').replaceAll('export function','function'),{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText;
test('payments apply filters through route query and preserve them for pagination',()=>{
  let pushed;const ctx=vm.createContext({ Intl,Date,router:{push:value=>pushed=value},filters:{value:{date:'custom',from:'2026-10-01',to:'2026-10-02',status:'completed',plan_id:'2',search:'REF'}}, });
  vm.runInContext(utils+handlers('../src/views/PaymentsView.vue',['apply']),ctx);ctx.apply(3);
  assert.equal(pushed.path,'/admin/payments');assert.equal(pushed.query.page,'3');assert.equal(pushed.query.status,'completed');assert.equal(pushed.query.search,'REF');assert.equal(pushed.query.plan_id,'2');assert.equal(pushed.query.from,'2026-10-01');
  ctx.filters.value.date='7d';ctx.apply();assert.equal(pushed.query.from,undefined);assert.equal(pushed.query.to,undefined);
});
test('ledger requests URL filters server-side and uses completed time; missing net stays unavailable',async()=>{
  let sent; const ctx=vm.createContext({Intl,Date,route:{query:{date:'today',status:'completed',plan_id:'2',page:'3',search:'2557'}},loading:{value:false},error:{value:''},page:{value:null},api:{get:async(path,options)=>{sent={path,options};return {data:{data:[{id:1}],total:1}}}}});
  vm.runInContext(utils+'let loadId=0;\n'+handlers('../src/views/PaymentsView.vue',['load']),ctx);await ctx.load();
  assert.equal(sent.path,'/admin/payments');assert.equal(sent.options.params.page,'3');assert.equal(sent.options.params.search,'2557');assert.equal(ctx.page.value.total,1);
  assert.equal(ctx.paymentTime({status:'completed',completed_at:'completion',created_at:'creation'}),'completion');assert.equal(ctx.paymentMoney(null),'Unavailable');assert.notEqual(ctx.paymentMoney(null),'TZS 0');
});
test('chart loads only aggregated API for selected period',async()=>{
  let sent; const aggregated={totals:[{sales:4,net:1900}],trend:[{bucket:'2026-10-02',sales:4}],packages:[],statuses:{completed:4}};
  const ctx=vm.createContext({period:{value:'30d'},analytics:{value:null},busy:{value:false},error:{value:''},api:{get:async(path,options)=>{sent={path,options};return {data:aggregated}}}});
  vm.runInContext('let requestId=0;\n'+handlers('../src/components/admin/DashboardAnalytics.vue',['load']),ctx);await ctx.load();
  assert.equal(sent.path,'/admin/dashboard/analytics');assert.equal(sent.options.params.period,'30d');assert.equal(ctx.analytics.value,aggregated);
});
test('router failure does not erase dashboard financial data',async()=>{
  const dashboard={sales_today:3,net_revenue_today:1470};const ctx=vm.createContext({Promise,Date,loading:{value:false},error:{value:''},routerError:{value:''},dashboardUpdated:{value:null},routerUpdated:{value:null},dashboard:{value:{}},routerHealth:{value:{}},api:{get:async path=>{if(path==='/admin/router/health')throw new Error('offline');return {data:dashboard}}}});
  vm.runInContext(handlers('../src/views/DashboardView.vue',['load']),ctx);await ctx.load();assert.equal(ctx.dashboard.value.net_revenue_today,1470);assert.equal(ctx.routerHealth.value.connected,undefined);assert.ok(ctx.routerError.value);
});
function support(props={}) {
  const ctx=vm.createContext({encodeURIComponent,props,busy:{value:false},sent:{value:false},error:{value:''},supportPhone:{value:props.phone || null},dialog:{value:null},t:s=>s});
  vm.runInContext(handlers('../src/components/portal/SupportAction.vue',['send']),ctx);return ctx;
}
test('support one click keeps token in header, captures context and suppresses repeated submissions',async()=>{
  const ctx=support({voucherUuid:'voucher',token:'private-token',phone:'255712345678',deviceMac:'AA:BB:CC:DD:EE:FF',connectionState:'offline'});let calls=0,release;let request;
  ctx.api={post:async(path,payload,options)=>{calls++;request={path,payload,options};await new Promise(resolve=>release=resolve)}};
  const pending=ctx.send();await ctx.send();assert.equal(calls,1);assert.equal(ctx.busy.value,true);release();await pending;await ctx.send();assert.equal(calls,1);assert.equal(ctx.sent.value,true);
  assert.equal(request.path,'/public/vouchers/voucher/support');assert.equal(request.payload.connection_state,'offline');assert.equal(request.options.headers['X-Voucher-Recovery-Token'],'private-token');assert.ok(!JSON.stringify(request.payload).includes('private-token'));
});
test('support errors do not falsely confirm and allow a retry',async()=>{
  const ctx=support({phone:'255712345678'});ctx.api={post:async()=>{throw new Error('offline')}};await ctx.send();assert.equal(ctx.sent.value,false);assert.ok(ctx.error.value);assert.equal(ctx.busy.value,false);
  ctx.api={post:async()=>({})};await ctx.send();assert.equal(ctx.sent.value,true);
});
test('support success renders concise confirmation without another button',async()=>{
  const s=source('../src/components/portal/SupportAction.vue');const template=s.slice(s.indexOf('<template>')+10,s.lastIndexOf('</template>'));
  const html=await renderToString(createSSRApp({template,setup:()=>({sent:true,busy:false,error:'',t:s=>s})}));
  assert.ok(html.includes('Ombi la msaada limetumwa.'));assert.ok(!html.includes('<button'));
});
test('ledger renders both compact mobile and desktop layouts with honest settlement fields',async()=>{
  const s=source('../src/views/PaymentsView.vue');const template=s.slice(s.indexOf('<template>')+10,s.lastIndexOf('</template>'));
  const payment={id:1,amount:500,currency:'TZS',status:'completed',net_amount:null,fee_amount:null,completed_at:'2026-10-02T12:00:00Z',order:{plan:{name:'Hour'},customer_phone:'255712345678'}};
  const html=await renderToString(createSSRApp({template,components:{AdminShell:{template:'<main><slot/></main>'}},setup:()=>({filters:{},showFilters:false,filterCount:0,plans:[],planError:'',loading:false,error:'',page:{data:[payment],current_page:1,last_page:1,total:1},paymentGroups:[{phone:'255712345678',payments:[payment]}],apply(){},label:s=>s,statusClass:s=>`payment-status--${s}`,paymentMoney:(v)=>v===null ? 'Unavailable' : String(v),paymentTime:p=>p.completed_at,reportingDate:s=>s})}));
  assert.ok(html.includes('d-md-none'));assert.ok(html.includes('d-none d-md-block'));assert.ok(html.includes('Net received'));assert.ok(html.includes('Unavailable'));assert.ok(html.includes('Hour'));
});
