export function formatPhoneInput(value: string): string {
  let digits = value.replace(/\D/g, '')
  if (digits.startsWith('0')) digits = `255${digits.slice(1)}`
  else if (/^[67]/.test(digits)) digits = `255${digits}`
  return digits.slice(0, 12).match(/.{1,3}/g)?.join(' ') || ''
}
