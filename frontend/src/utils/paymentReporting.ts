export function paymentQuery(query: Record<string, unknown>) {
  const keys = ['date', 'from', 'to', 'status', 'plan_id', 'search', 'page']
  return Object.fromEntries(keys.filter(key => typeof query[key] === 'string' && query[key] !== '').map(key => [key, query[key] as string]))
}
export function paymentMoney(value: unknown, currency = 'TZS') {
  return value === null || value === undefined ? 'Unavailable' : `${currency} ${Number(value).toLocaleString('en-TZ')}`
}
export function paymentTime(payment: any) {
  return payment.status === 'completed' ? payment.completed_at : payment.updated_at
}
export function reportingDate(value: string | null | undefined) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-TZ', { timeZone: 'Africa/Dar_es_Salaam', dateStyle: 'medium', timeStyle: 'short' }).format(date)
}
