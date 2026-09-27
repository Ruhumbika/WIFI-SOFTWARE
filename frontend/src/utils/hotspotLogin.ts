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
