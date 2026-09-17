import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getBalance, getCurrentRecharge } from './game'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('axios', () => ({ default: { create: () => ({ get, interceptors: { request: { use: vi.fn() } } }) } }))

beforeEach(() => {
  localStorage.clear()
  localStorage.setItem('mgs-currency-code', 'INR')
})

describe('wallet currency', () => {
  it('unwraps an empty current recharge without inventing an order', async () => {
    get.mockResolvedValue({ data: { code: 200, data: { order: null } } })
    expect(await getCurrentRecharge('INR')).toBeNull()
  })
  it('selects the active wallet instead of the first wallet', async () => {
    get.mockResolvedValue({ data: { code: 200, data: [{ currency_code: 'USD', balance: '999.00' }, { currency_code: 'INR', balance: '100.00' }] } })
    expect(await getBalance()).toEqual({ currency: 'INR', balance: 100 })
  })

  it('does not label a different wallet balance as the active currency', async () => {
    get.mockResolvedValue({ data: { code: 200, data: [{ currency_code: 'USD', balance: '999.00' }] } })
    expect(await getBalance()).toEqual({ currency: 'INR', balance: 0 })
  })
})
