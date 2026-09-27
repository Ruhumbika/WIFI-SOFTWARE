import { computed, inject, provide, ref, type InjectionKey } from 'vue'
import { portalMessages } from './portalMessages'
import { formatDate as originalFormatDate } from '../utils/formatDate'

export type PortalLocale = 'en' | 'sw'
const storageKey = 'rjay_portal_language'
const messages = new Map<string, [string, string]>()
const normalize = (value: string) => value.replace(/\s+/g, ' ').trim()
for (const pair of portalMessages) {
  messages.set(normalize(pair[0]), pair)
  messages.set(normalize(pair[1]), pair)
}
export function detectPortalLocale(saved: string | null, languages: readonly string[]): PortalLocale {
  if (saved === 'sw' || saved === 'en') return saved
  for (const language of languages) {
    const base = language.toLowerCase().split(/[-_]/)[0]
    if (base === 'sw' || base === 'en') return base
  }
  return 'en'
}
export function translatePortal(value: unknown, locale: PortalLocale): string {
  if (value === null || value === undefined) return ''
  const text = String(value)
  const pair = messages.get(normalize(text))
  if (pair) return pair[locale === 'sw' ? 1 : 0]
  const duration = text.match(/^(\d+) (weeks?|days?|hours?|minutes?|min)$/)
  if (duration && locale === 'sw') {
    const unit = duration[2]!.startsWith('week') ? 'Wiki' : duration[2]!.startsWith('day') ? 'Siku' : duration[2]!.startsWith('hour') ? 'Saa' : 'Dakika'
    return `${unit} ${duration[1]}`
  }
  const validity = text.match(/^Validity starts on first successful login and lasts (.+)\.$/)
  if (validity && locale === 'sw') return `Muda huanza ukiunganisha mara ya kwanza na hudumu ${translatePortal(validity[1], locale)}.`
  if (locale === 'sw' && text.startsWith('Package speed: ')) return text.replace('Package speed: ', 'Kasi ya kifurushi: ')
  if (locale === 'sw' && text.startsWith('Data allowance: ')) return text.replace('Data allowance: ', 'Kiasi cha data: ')
  return text
}
type PortalLanguage = ReturnType<typeof createPortalLanguage>
const languageKey: InjectionKey<PortalLanguage> = Symbol('portal-language')
function createPortalLanguage() {
  let saved: string | null = null
  try { saved = localStorage.getItem(storageKey) } catch { /* Device storage may be unavailable. */ }
  const locale = ref<PortalLocale>(detectPortalLocale(saved, navigator.languages?.length ? navigator.languages : [navigator.language]))
  const t = (value: unknown) => translatePortal(value, locale.value)
  const formatDate = (value: string | null | undefined, fallback = '—') => {
    if (!value) return fallback
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat(locale.value === 'sw' ? 'sw-TZ' : 'en-TZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
  }
  function toggleLanguage() {
    locale.value = locale.value === 'sw' ? 'en' : 'sw'
    try { localStorage.setItem(storageKey, locale.value) } catch { /* Keep the selected language for this visit. */ }
  }
  return { locale, t, formatDate, toggleLanguage, languageLabel: computed(() => locale.value === 'sw' ? 'Switch to English' : 'Badili kwenda Kiswahili') }
}
export function providePortalLanguage() {
  const language = createPortalLanguage()
  provide(languageKey, language)
  return language
}
export function usePortalLanguage() {
  return inject(languageKey, undefined) ?? { t: (value: unknown) => value == null ? '' : String(value), formatDate: originalFormatDate }
}
