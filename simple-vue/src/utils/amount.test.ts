import { describe, expect, it } from 'vitest'
import { formatAmount } from './amount'

describe('formatAmount', () => {
  it.each([
    ['3.11020000', '3.1102'],
    ['100.00000000', '100.00'],
    ['0.00010000', '0.0001'],
    ['0.00000000', '0.00'],
    ['-0.00010000', '-0.0001'],
    ['-0.00000000', '0.00'],
  ])('formats %s as %s', (value, expected) => expect(formatAmount(value)).toBe(expected))
})
