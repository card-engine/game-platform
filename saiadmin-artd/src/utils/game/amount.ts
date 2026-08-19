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

const formatScaled = (value: bigint, scale: number) => {
  const negative = value < 0n
  const digits = (negative ? -value : value).toString().padStart(scale + 1, '0')
  const integer = digits.slice(0, -scale) || '0'
  const fraction = digits.slice(-scale).replace(/0+$/, '')
  return `${negative ? '-' : ''}${integer}${fraction ? `.${fraction}` : ''}`
}

export const rateToPercent = (value?: string | number | null) => {
  const [integer, fraction = ''] = String(value ?? 0).split('.')
  const scaled = BigInt(`${integer || 0}${fraction.padEnd(10, '0').slice(0, 10)}`) * 100n
  return formatScaled(scaled, 10)
}

export const percentToRate = (value?: string | number | null) => {
  const [integer, fraction = ''] = String(value ?? 0).split('.')
  const scaled = BigInt(`${integer || 0}${fraction.padEnd(10, '0').slice(0, 10)}`) / 100n
  return formatScaled(scaled, 10)
}
