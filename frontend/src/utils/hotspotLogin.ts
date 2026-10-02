export function submitHotspotLogin(loginUrl: string, voucher: { code: string; password: string }, returnUrl: URL) {
  const form = document.createElement('form')
  form.method = 'post'
  form.action = loginUrl
  for (const [name, value] of Object.entries({ username: voucher.code, password: voucher.password, dst: returnUrl.toString(), popup: 'false' })) {
    const input = document.createElement('input')
    input.type = 'hidden'; input.name = name; input.value = value
    form.appendChild(input)
  }
  document.body.appendChild(form)
  form.submit()
  form.remove()
}

export function captiveContext() {
  const params = new URLSearchParams(location.search)
  let saved: Record<string, string> = {}
  try { saved = JSON.parse(sessionStorage.getItem('rjay_hotspot_context') || '{}') || {} } catch {}
  for (const key of ['mac', 'link-login-only', 'link-orig']) {
    if (!params.has(key) && typeof saved[key] === 'string') params.set(key, saved[key])
  }
  sessionStorage.setItem('rjay_hotspot_context', JSON.stringify(Object.fromEntries(
    ['mac', 'link-login-only', 'link-orig'].map(key => [key, params.get(key) || ''])
  )))
  return params
}

export function preserveCaptiveContext(url: URL, params: URLSearchParams) {
  for (const key of ['mac', 'link-login-only', 'link-orig']) {
    const value = params.get(key)
    if (value) url.searchParams.set(key, value)
  }
  return url
}

export function hotspotReturnUrl(params: URLSearchParams, identity: { voucherUuid: string } | { orderUuid: string }) {
  const url = preserveCaptiveContext(new URL(location.href), params)
  url.searchParams.set('connected', '1')
  if ('voucherUuid' in identity) {
    url.searchParams.delete('order')
    url.searchParams.set('voucher-return', '1')
    url.searchParams.set('voucher-uuid', identity.voucherUuid)
  } else {
    url.searchParams.delete('voucher-return')
    url.searchParams.delete('voucher-uuid')
    url.searchParams.set('order', identity.orderUuid)
  }
  return url
}

export function orderToRestore(params: URLSearchParams, storageKey: string) {
  if (params.has('voucher-return')) return null
  return params.get('order') || sessionStorage.getItem(storageKey)
}
