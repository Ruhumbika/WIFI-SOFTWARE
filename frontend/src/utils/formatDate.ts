const formatter = new Intl.DateTimeFormat('en-TZ', {
  day: '2-digit', month: 'short', year: 'numeric',
  hour: '2-digit', minute: '2-digit', hour12: false,
})

export function formatDate(value: string | null | undefined, fallback = '—'): string {
  if (!value) return fallback
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? fallback : formatter.format(date)
}
