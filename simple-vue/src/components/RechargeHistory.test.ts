import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h } from 'vue'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import RechargeHistory from './RechargeHistory.vue'
import { getRecharge, getRechargeHistory, type RechargeOrder } from '../api/game'

vi.mock('../api/game', () => ({ getRecharge: vi.fn(), getRechargeHistory: vi.fn() }))
vi.mock('../stores/user', () => ({ useUserStore: () => ({ uniqueId: '1024' }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const order: RechargeOrder = {
  mgs_recharge_id: '1', recharge_no: 'MR-test', currency_code: 'INR', recharge_amount: '100.00000000',
  pay_currency_code: 'TRX', pay_amount: '3.1102', receive_address: 'test-address', status: 'pending',
  create_time: '2026-09-18T05:35:46.831Z', expire_time: '2026-09-18T05:50:46.831Z',
  server_time: '2026-09-18T05:37:31.952Z', credited_time: null,
}
let app: ReturnType<typeof createApp>
let root: HTMLDivElement
let client: QueryClient
function mount() {
  root = document.createElement('div')
  document.body.append(root)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  app = createApp(RechargeHistory).use(VueQueryPlugin, { queryClient: client })
  app.component('ElButton', defineComponent({ setup: (_, { attrs, slots }) => () => h('button', attrs, slots.default?.()) }))
  app.component('ElSkeleton', defineComponent({ setup: () => () => h('div') }))
  app.mount(root)
}
afterEach(() => { app.unmount(); client.clear(); root.remove(); vi.resetAllMocks() })

describe('recharge history', () => {
  it('loads more records and updates a pending row from the latest paid detail', async () => {
    let current = order
    vi.mocked(getRechargeHistory).mockImplementation(async (page) => ({
      list: page === 1 ? [current] : [{ ...order, mgs_recharge_id: '2', status: 'expired' }],
      page, has_more: page === 1,
    }))
    vi.mocked(getRecharge).mockImplementation(async () => {
      current = { ...order, status: 'paid', credited_time: order.server_time,
        transfers: [{ transaction_id: 'a'.repeat(64), block_number: 86345863, block_time: '2026-09-18 05:36:30' }] }
      return current
    })
    mount()
    await vi.waitFor(() => expect(root.querySelectorAll('.history-row')).toHaveLength(1))
    expect(getRecharge).not.toHaveBeenCalled()
    expect(root.textContent).toContain('100.00 INR')
    expect(root.textContent).toContain('3.1102 TRX')
    root.querySelector<HTMLButtonElement>('.history-summary')!.click()
    await vi.waitFor(() => expect(root.querySelector('.history-status')?.textContent).toContain('rechargeHistory.status.paid'))
    expect(getRecharge).toHaveBeenCalledWith('1')
    expect(root.querySelector('.history-transfer a')?.getAttribute('href')).toBe(`https://tronscan.org/#/transaction/${'a'.repeat(64)}`)
    root.querySelector<HTMLButtonElement>('.history-summary')!.click()
    await vi.waitFor(() => expect(root.querySelector('.history-detail')).toBeNull())
    expect(root.querySelector('.history-status')?.textContent).toContain('rechargeHistory.status.paid')
    root.querySelector<HTMLButtonElement>('.history-more button')!.click()
    await vi.waitFor(() => expect(root.querySelectorAll('.history-row')).toHaveLength(2))
    expect(getRechargeHistory).toHaveBeenLastCalledWith(2)
    expect(root.querySelector('.history-more')).toBeNull()
  })

  it('shows an empty history without querying a detail or another page', async () => {
    vi.mocked(getRechargeHistory).mockResolvedValue({ list: [], page: 1, has_more: false })
    mount()
    await vi.waitFor(() => expect(root.textContent).toContain('rechargeHistory.empty'))
    expect(getRechargeHistory).toHaveBeenCalledOnce()
    expect(getRecharge).not.toHaveBeenCalled()
    expect(root.querySelector('.history-more')).toBeNull()
  })
})
