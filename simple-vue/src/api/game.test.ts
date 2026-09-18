import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getBalance, getCurrentRecharge, getUserGames, updateUser } from './game'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('axios', () => ({ default: { create: () => ({ get, put, interceptors: { request: { use: vi.fn() } } }) } }))

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

  it('maps all recommendation groups to lobby games', async () => {
    const game = { mgs_game_id: 8, name: 'Demo', icon_url: '/demo.webp', game_type: 'slot', brand_code: 'pg', currency_codes: ['INR'], status: 1 }
    get.mockResolvedValue({ data: { code: 200, data: { recent: [game], hot: [], discover: [game] } } })
    const groups = await getUserGames(2)
    expect(groups.recent[0]).toMatchObject({ gameId: '8', gameName: 'Demo', gameBrand: 'pg' })
    expect(groups.discover[0].gameType).toBe('slot')
  })

  it('updates the nickname through the user endpoint', async () => {
    put.mockResolvedValue({ data: { code: 200, data: { mgs_user_id: 1, unique_id: 2, nickname: 'M', language: 'en-US' } } })
    expect((await updateUser('M')).nickname).toBe('M')
    expect(put).toHaveBeenCalledWith('/user', { nickname: 'M' })
  })
})
