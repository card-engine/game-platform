export function formatAmount(value: string | number | null | undefined) {
  const text = String(value ?? 0).trim()
  const match = text.match(/^([+-]?)(\d+)(?:\.(\d+))?$/)
  if (!match) return text
  const fraction = (match[3] || '').replace(/0+$/, '')
  const integer = match[2].replace(/^0+(?=\d)/, '')
  return `${match[1] === '-' && (integer !== '0' || fraction !== '') ? '-' : ''}${integer}.${(fraction || '').padEnd(2, '0')}`
}
