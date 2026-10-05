import { ref } from 'vue'
import { api } from '../api'

export interface AdminUser {
  id: number
  name: string
  email: string
  is_active: boolean
  is_super_user: boolean
  permissions: string[]
}
export const currentUser = ref<AdminUser | null>(null)
let pending: Promise<AdminUser> | null = null
let generation = 0
export function can(permission: string): boolean {
  const user = currentUser.value
  return !!user?.is_active && (user.is_super_user || user.permissions.includes(permission))
}
export function clearAuth(): void {
  generation++
  pending = null
  currentUser.value = null
  localStorage.removeItem('rjay_admin_token')
}
export function setSession(token: string, user: AdminUser): void {
  generation++
  pending = null
  localStorage.setItem('rjay_admin_token', token)
  currentUser.value = user
}
export async function refreshAccess(): Promise<AdminUser> {
  if (!localStorage.getItem('rjay_admin_token')) { clearAuth(); throw new Error('Unauthenticated') }
  if (!pending) {
    const revision = generation
    pending = api.get('/admin/me').then(({ data }) => {
      if (revision !== generation) throw new Error('Session changed')
      currentUser.value = data.user
      return data.user
    }).finally(() => { if (revision === generation) pending = null })
  }
  return pending
}
export const destinations = [
  ['/admin', 'dashboard.view'], ['/admin/payments', 'payments.view'],
  ['/admin/vouchers', 'vouchers.view'], ['/admin/sessions', 'sessions.view'],
  ['/admin/support', 'support.view'], ['/admin/plans', 'plans.view'],
  ['/admin/router', 'router.view'], ['/admin/router/advanced', 'router.advanced'],
  ['/admin/logs', 'logs.view'], ['/admin/users', 'users.view'],
] as const
export function firstAccessible(): string {
  return destinations.find(([, permission]) => can(permission))?.[0] || '/admin/forbidden'
}
export async function logout(): Promise<void> {
  try { await api.post('/admin/logout') } finally { clearAuth() }
}
window.addEventListener('rjay-admin-unauthenticated', clearAuth)
