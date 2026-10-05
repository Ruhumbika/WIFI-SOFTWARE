import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import ts from 'typescript'
import { ref, createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
const source = path => readFileSync(new URL(path, import.meta.url), 'utf8')
function access(get = async () => ({data:{user:staff(['payments.view'])}})) {
  const storage = new Map(), listeners = new Map(), calls = []
  const exports = {}
  const ctx = vm.createContext({exports, require: name => name === 'vue' ? {ref} : {api:{get:async path=>{calls.push(path);return get(path)},post:async path=>calls.push(path)}},localStorage:{getItem:key=>storage.get(key),setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},window:{addEventListener:(name,fn)=>listeners.set(name,fn)}})
  vm.runInContext(ts.transpileModule(source('../src/auth/access.ts'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,ctx)
  return { ...exports, storage, listeners, calls }
}
const staff = (permissions=[], is_super_user=false) => ({id:1,name:'Staff',email:'staff@example.test',is_active:true,is_super_user,permissions})
function guard(auth, token = 'token') {
  let handler
  const text=source('../src/router.ts')
  const code=text.slice(text.indexOf('router.beforeEach'),text.indexOf('export default router')).replace("await import('./auth/access')",'await loadAccess()')
  const ctx=vm.createContext({router:{beforeEach:fn=>handler=fn},localStorage:{getItem:()=>token},loadAccess:async()=>auth})
  vm.runInContext(ts.transpileModule(code,{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText,ctx)
  return handler
}
test('staff only has explicit permissions; disabled accounts are denied',()=>{
  const auth=access();auth.setSession('token',staff(['payments.view']))
  assert.equal(auth.can('payments.view'),true);assert.equal(auth.can('router.manage'),false)
  auth.setSession('token',{...staff(['payments.view']),is_active:false});assert.equal(auth.can('payments.view'),false)
})
test('super users have every area without explicit permission rows',()=>{
  const auth=access();auth.setSession('token',staff([],true));assert.equal(auth.can('router.advanced'),true);assert.equal(auth.can('users.manage'),true)
})
test('login chooses an accessible page and unassigned staff get a clear denial destination',()=>{
  const auth=access();auth.setSession('token',staff(['payments.view']));assert.equal(auth.firstAccessible(),'/admin/payments')
  auth.setSession('token',staff());assert.equal(auth.firstAccessible(),'/admin/forbidden')
})
test('me restores server permissions after refresh and concurrent requests share one response',async()=>{
  const auth=access();auth.storage.set('rjay_admin_token','token')
  assert.equal(auth.currentUser.value,null);await Promise.all([auth.refreshAccess(),auth.refreshAccess()])
  assert.deepEqual(auth.calls,['/admin/me']);assert.equal(auth.can('payments.view'),true)
})
test('logout and 401 clear both user and token',async()=>{
  const auth=access();auth.setSession('token',staff(['payments.view']));await auth.logout()
  assert.equal(auth.currentUser.value,null);assert.equal(auth.storage.has('rjay_admin_token'),false)
  auth.setSession('token',staff([],true));auth.listeners.get('rjay-admin-unauthenticated')()
  assert.equal(auth.currentUser.value,null);assert.equal(auth.storage.has('rjay_admin_token'),false)
})
test('an in-flight me response cannot restore access after logout',async()=>{
  let resolve;const auth=access(()=>new Promise(done=>resolve=done));auth.storage.set('rjay_admin_token','token')
  const pending=auth.refreshAccess();auth.clearAuth();resolve({data:{user:staff([],true)}})
  await assert.rejects(pending,/Session changed/);assert.equal(auth.currentUser.value,null)
})
test('guard allows permitted staff and super users and sends authenticated denials to 403',async()=>{
  const auth=access();auth.storage.set('rjay_admin_token','token');const run=guard(auth)
  assert.equal(await run({path:'/admin/payments',meta:{admin:true,permission:'payments.view'}}),undefined)
  const denied=await run({path:'/admin/router',meta:{admin:true,permission:'router.view'}});assert.equal(denied.path,'/admin/forbidden')
  const superAuth=access(async()=>({data:{user:staff([],true)}}));superAuth.storage.set('rjay_admin_token','token')
  assert.equal(await guard(superAuth)({path:'/admin/users',meta:{admin:true,permission:'users.view'}}),undefined)
})
test('guard distinguishes unauthenticated and unavailable access and leaves public routes alone',async()=>{
  assert.equal(await guard(access(),null)({meta:{admin:true}}),'/admin/login')
  const auth=access(async()=>{throw new Error('offline')});auth.storage.set('rjay_admin_token','token')
  const denied=await guard(auth)({path:'/admin/payments',meta:{admin:true,permission:'payments.view'}})
  assert.equal(denied.path,'/admin/forbidden');assert.equal(denied.query.reason,'unavailable')
  assert.equal(await guard(auth)({path:'/',meta:{}}),undefined);assert.deepEqual(auth.calls,['/admin/me'])
})
test('navigation renders only permitted areas across desktop and mobile',async()=>{
  const auth=access();auth.setSession('token',staff(['payments.view']))
  const text=source('../src/components/AdminShell.vue'),template=text.slice(text.indexOf('<template>')+10,text.lastIndexOf('</template>'))
  const html=await renderToString(createSSRApp({template,components:{'router-link':{props:['to'],template:'<a :href="to"><slot/></a>'}},setup:()=>({can:auth.can,moreOpen:true,logout(){}})}))
  assert.ok(html.includes('href="/admin/payments"'));assert.ok(!html.includes('href="/admin/users"'));assert.ok(!html.includes('href="/admin/router"'));assert.ok(!html.includes('href="/admin"'))
})
test('admin routes and access state stay lazy loaded from the public router',()=>{
  const router=source('../src/router.ts')
  assert.ok(router.includes("await import('./auth/access')"));assert.ok(router.includes("component: () => import('./views/UsersView.vue')"))
  assert.ok(!/^import .*auth\/access/m.test(router));assert.ok(!source('../src/api.ts').includes("from './auth"))
  for(const permission of ['dashboard.view','payments.view','vouchers.view','sessions.view','support.view','plans.view','router.view','router.advanced','logs.view','users.view'])assert.ok(router.includes(`permission: '${permission}'`))
})
function handlers(path,names) {
  const script=source(path).match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1]
  const ast=ts.createSourceFile('view.ts',script,ts.ScriptTarget.Latest,true)
  return ts.transpileModule(ast.statements.filter(n=>ts.isFunctionDeclaration(n)&&names.includes(n.name?.text)).map(n=>n.getText(ast)).join('\n'),{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText
}
test('dashboard and payments do not request unauthorized router or package data',async()=>{
  const calls=[],box=vm.createContext({can:()=>false,Promise,Date,loading:{value:false},dashboardUpdated:{value:null},routerUpdated:{value:null},dashboard:{value:{}},routerHealth:{value:{}},routerError:{value:''},error:{value:''},plans:{value:[]},planError:{value:''},api:{get:async path=>{calls.push(path);return {data:{sales_today:1}}}}})
  vm.runInContext(handlers('../src/views/DashboardView.vue',['load'])+handlers('../src/views/PaymentsView.vue',['loadPlans']),box)
  await box.load();await box.loadPlans();assert.deepEqual(calls,['/admin/dashboard']);assert.equal(box.dashboard.value.sales_today,1)
})
test('voucher list skips packages without plans.view and keeps its own data',async()=>{
  const calls=[],box=vm.createContext({can:()=>false,Promise,loading:{value:false},error:{value:''},rows:{value:[]},selectedIds:{value:[]},total:{value:0},lastPage:{value:1},page:{value:1},search:{value:''},status:{value:''},filterPlan:{value:''},date:{value:''},plans:{value:[]},planId:{value:null},api:{get:async path=>{calls.push(path);return {data:{data:[{id:1}],total:1,last_page:1,current_page:1}}}}})
  vm.runInContext(handlers('../src/views/VouchersView.vue',['load']),box);await box.load()
  assert.deepEqual(calls,['/admin/vouchers']);assert.equal(box.rows.value[0].id,1);assert.equal(box.error.value,'')
})
test('API 401 clears access through the real interceptor while 403 preserves authentication',async()=>{
  const auth=access();auth.setSession('token',staff(['payments.view']));let reject;let redirect
  const api={interceptors:{request:{use(){}},response:{use:(_,handler)=>reject=handler}}}
  const text=source('../src/api.ts').replace("import axios from 'axios'",'const axios = injectedAxios').replace('import.meta.env.VITE_API_BASE_URL','undefined')
  const ctx=vm.createContext({exports:{},Promise,Event,injectedAxios:{create:()=>api},localStorage:{getItem:key=>auth.storage.get(key),removeItem:key=>auth.storage.delete(key)},window:{dispatchEvent:event=>auth.listeners.get(event.type)?.(),location:{assign:path=>redirect=path}}})
  vm.runInContext(ts.transpileModule(text,{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,ctx)
  await assert.rejects(reject({response:{status:403},config:{url:'/admin/payments'}}))
  assert.equal(auth.can('payments.view'),true);assert.equal(redirect,undefined)
  await assert.rejects(reject({response:{status:401},config:{url:'/admin/me'}}))
  assert.equal(auth.currentUser.value,null);assert.equal(auth.storage.has('rjay_admin_token'),false);assert.equal(redirect,'/admin/login')
})
