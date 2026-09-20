export type PlatformFamily = 'Windows' | 'Linux' | 'macOS' | 'Android' | 'iOS' | 'ChromeOS' | 'Unknown'
export interface ClientPlatform { family: PlatformFamily; mobile: boolean; architecture: string | null; browser: string }

export async function detectPlatform(): Promise<ClientPlatform> {
  const nav = navigator as Navigator & { userAgentData?: {
    platform?: string; mobile?: boolean; brands?: { brand: string }[];
    getHighEntropyValues?: (keys: string[]) => Promise<{ architecture?: string; bitness?: string }>
  } }
  const ua = nav.userAgent || ''
  const source = `${nav.userAgentData?.platform || ''} ${ua} ${nav.platform || ''}`
  const family: PlatformFamily = /Android/i.test(source) ? 'Android'
    : /iPhone|iPad|iPod/i.test(source) || (/Mac/i.test(source) && navigator.maxTouchPoints > 1) ? 'iOS'
    : /CrOS|ChromeOS/i.test(source) ? 'ChromeOS'
    : /Windows|Win32|Win64/i.test(source) ? 'Windows'
    : /Macintosh|Mac OS|MacIntel/i.test(source) ? 'macOS'
    : /Linux|X11/i.test(source) ? 'Linux' : 'Unknown'
  const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox'
    : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Unknown'
  let architecture: string | null = null
  try {
    const info = await nav.userAgentData?.getHighEntropyValues?.(['architecture', 'bitness'])
    if (info?.architecture) architecture = `${info.architecture}${info.bitness ? ` ${info.bitness}-bit` : ''}`
  } catch { /* Optional browser information may be unavailable. */ }
  return { family, mobile: nav.userAgentData?.mobile ?? ['Android', 'iOS'].includes(family), architecture, browser }
}
