import { createRouter, createWebHistory } from 'vue-router'
import PortalView from './views/PortalView.vue'

const AdminLoginView = () => import('./views/AdminLoginView.vue')
const DashboardView = () => import('./views/DashboardView.vue')
const PlansView = () => import('./views/PlansView.vue')
const VouchersView = () => import('./views/VouchersView.vue')
const SupportView = () => import('./views/SupportView.vue')
const PaymentsView = () => import('./views/PaymentsView.vue')
const SessionsView = () => import('./views/SessionsView.vue')
const RouterView = () => import('./views/RouterView.vue')
const VoucherDetailView = () => import('./views/VoucherDetailView.vue')
const AdminMoreView = () => import('./views/AdminMoreView.vue')
const LogsView = () => import('./views/LogsView.vue')
const AdvancedAccessView = () => import('./views/AdvancedAccessView.vue')

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: PortalView },

    { path: '/admin/login', component: AdminLoginView },
    { path: '/admin', component: DashboardView, meta: { admin: true } },
    { path: '/admin/plans', component: PlansView, meta: { admin: true } },
    { path: '/admin/vouchers', component: VouchersView, meta: { admin: true } },
    { path: '/admin/vouchers/:id', component: VoucherDetailView, meta: { admin: true } },
    { path: '/admin/support', component: SupportView, meta: { admin: true } },
    { path: '/admin/payments', component: PaymentsView, meta: { admin: true } },
    { path: '/admin/sessions', component: SessionsView, meta: { admin: true } },
    { path: '/admin/router', component: RouterView, meta: { admin: true } },
    { path: '/admin/router/advanced', component: AdvancedAccessView, meta: { admin: true } },
    { path: '/admin/logs', component: LogsView, meta: { admin: true } },
    { path: '/admin/more', component: AdminMoreView, meta: { admin: true } },
  ],
})

router.beforeEach((to) => {
  if (to.meta.admin && !localStorage.getItem('rjay_admin_token')) {
    return '/admin/login'
  }
})

export default router
