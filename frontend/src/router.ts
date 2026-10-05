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

    { path: '/admin/users', component: () => import('./views/UsersView.vue'), meta: { admin: true, permission: 'users.view' } },
    { path: '/admin/forbidden', component: () => import('./views/AccessDeniedView.vue'), meta: { admin: true } },
    { path: '/admin/login', component: AdminLoginView },
    { path: '/admin', component: DashboardView, meta: { admin: true, permission: 'dashboard.view' } },
    { path: '/admin/plans', component: PlansView, meta: { admin: true, permission: 'plans.view' } },
    { path: '/admin/vouchers', component: VouchersView, meta: { admin: true, permission: 'vouchers.view' } },
    { path: '/admin/vouchers/:id', component: VoucherDetailView, meta: { admin: true, permission: 'vouchers.view' } },
    { path: '/admin/support', component: SupportView, meta: { admin: true, permission: 'support.view' } },
    { path: '/admin/payments', component: PaymentsView, meta: { admin: true, permission: 'payments.view' } },
    { path: '/admin/sessions', component: SessionsView, meta: { admin: true, permission: 'sessions.view' } },
    { path: '/admin/router', component: RouterView, meta: { admin: true, permission: 'router.view' } },
    { path: '/admin/router/advanced', component: AdvancedAccessView, meta: { admin: true, permission: 'router.advanced' } },
    { path: '/admin/logs', component: LogsView, meta: { admin: true, permission: 'logs.view' } },
    { path: '/admin/more', component: AdminMoreView, meta: { admin: true } },
  ],
})

router.beforeEach(async (to) => {
  if (!to.meta.admin) return
  if (!localStorage.getItem('rjay_admin_token')) return '/admin/login'
  const access = await import('./auth/access')
  try {
    await access.refreshAccess()
  } catch (error: any) {
    if (error.response?.status === 401 || !localStorage.getItem('rjay_admin_token')) return '/admin/login'
    if (to.path !== '/admin/forbidden') return { path: '/admin/forbidden', query: { reason: 'unavailable' } }
    return
  }
  if (typeof to.meta.permission === 'string' && !access.can(to.meta.permission)) {
    return { path: '/admin/forbidden', query: { reason: 'denied' } }
  }
})

export default router
