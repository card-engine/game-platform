export const formatMoney = (
  value?: string | number | null,
  mode: 'round' | 'truncate' = 'round'
) => {
  const raw = String(value ?? 0)
  const negative = raw.startsWith('-')
  const [integer = '0', fraction = ''] = raw.replace(/^[+-]/, '').split('.')
  let cents = BigInt(`${integer || '0'}${fraction.padEnd(2, '0').slice(0, 2)}`)

  if (mode === 'round' && Number(fraction[2] || 0) >= 5) cents += 1n

  const fixed = cents.toString().padStart(3, '0')
  const whole = fixed.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${negative && cents ? '-' : ''}${whole}.${fixed.slice(-2)}`
}

export const money = (value?: string | number | null) => formatMoney(value)
export const merchantMoney = (value?: string | number | null) => formatMoney(value, 'truncate')
