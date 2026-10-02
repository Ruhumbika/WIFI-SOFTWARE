import { createRouter, createWebHistory } from 'vue-router'
import PortalView from './views/PortalView.vue'
import AdminLoginView from './views/AdminLoginView.vue'
import DashboardView from './views/DashboardView.vue'
import PlansView from './views/PlansView.vue'
import VouchersView from './views/VouchersView.vue'
import SupportView from './views/SupportView.vue'
import PaymentsView from './views/PaymentsView.vue'
import SessionsView from './views/SessionsView.vue'
import RouterView from './views/RouterView.vue'
import VoucherDetailView from './views/VoucherDetailView.vue'
import AdminMoreView from './views/AdminMoreView.vue'
import LogsView from './views/LogsView.vue'
import AdvancedAccessView from './views/AdvancedAccessView.vue'

const router = createRouter({ history:createWebHistory(), routes:[
  {path:'/',component:PortalView},
  {path:'/admin/login',component:AdminLoginView},
  {path:'/admin',component:DashboardView,meta:{admin:true}},
  {path:'/admin/plans',component:PlansView,meta:{admin:true}},
  {path:'/admin/vouchers',component:VouchersView,meta:{admin:true}},
  {path:'/admin/vouchers/:id',component:VoucherDetailView,meta:{admin:true}},
  {path:'/admin/support',component:SupportView,meta:{admin:true}},
  {path:'/admin/payments',component:PaymentsView,meta:{admin:true}},
  {path:'/admin/sessions',component:SessionsView,meta:{admin:true}},
  {path:'/admin/router',component:RouterView,meta:{admin:true}},
  {path:'/admin/router/advanced',component:AdvancedAccessView,meta:{admin:true}},
  {path:'/admin/logs',component:LogsView,meta:{admin:true}},
  {path:'/admin/more',component:AdminMoreView,meta:{admin:true}},
]})
router.beforeEach((to)=>{ if(to.meta.admin && !localStorage.getItem('rjay_admin_token')) return '/admin/login' })
export default router
