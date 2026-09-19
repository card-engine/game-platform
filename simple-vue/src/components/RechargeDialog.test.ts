import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, nextTick } from 'vue'
import RechargeDialog from './RechargeDialog.vue'
import { createRecharge, getCurrentRecharge, getRecharge, getRechargeOptions } from '../api/game'

vi.mock('../api/game', () => ({ createRecharge: vi.fn(), getCurrentRecharge: vi.fn(), getRecharge: vi.fn(), getRechargeOptions: vi.fn() }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const options = { currency_code: 'INR', amounts: [10, 20, 50, 100, 200, 300, 500, 1000, 2000, 5000, 10000, 50000, 100000],
  default_amount: 100, available: true, unavailable_reason: null,
  payments: [{ pay_currency_code: 'USDT' as const, quote_key: 'quote', amounts: { '100': '1.25' } }] }
const order = { mgs_recharge_id: '1', recharge_no: 'MR-test', create_time: '2026-09-18T05:00:00.000Z', credited_time: null, currency_code: 'INR', recharge_amount: '100.00000000', pay_currency_code: 'USDT' as const,
  pay_amount: '1.2501', receive_address: 'test-only-address', status: 'pending' as const,
  expire_time: '2026-09-17T01:15:00.000Z', server_time: '2026-09-17T01:00:00.000Z' }
let app: ReturnType<typeof createApp>
let root: HTMLDivElement
const paid = vi.fn()

async function settle() {
  for (let i = 0; i < 8; i++) await Promise.resolve()
  await nextTick()
}

function mount() {
  root = document.createElement('div')
  document.body.append(root)
  app = createApp(RechargeDialog, { currency: 'INR', userId: 'player', onPaid: paid })
  const container = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) })
  app.component('el-dialog', container)
  app.component('el-radio-group', container)
  app.component('el-radio', container)
  app.component('el-alert', defineComponent({ props: ['title'], setup: (props) => () => h('p', String(props.title)) }))
  app.component('el-button', defineComponent({ props: ['disabled', 'loading'], emits: ['click'],
    setup: (props, { slots, emit }) => () => h('button', { disabled: props.disabled || props.loading, onClick: () => emit('click') }, slots.default?.()) }))
  app.mount(root)
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.resetAllMocks()
  localStorage.clear()
  Object.defineProperty(document, 'hidden', { configurable: true, value: false })
  vi.mocked(getCurrentRecharge).mockResolvedValue(null)
  vi.mocked(getRechargeOptions).mockResolvedValue(options)
  vi.mocked(createRecharge).mockResolvedValue(order)
  vi.mocked(getRecharge).mockResolvedValue(order)
})

afterEach(() => {
  if (root.childNodes.length) app.unmount()
  root.remove()
  vi.useRealTimers()
})

describe('recharge dialog', () => {
  it('uses server amounts and prices, and disables submission when unavailable', async () => {
    vi.mocked(getRechargeOptions).mockResolvedValue({ ...options, available: false, payments: [], unavailable_reason: '充值暂未开放' })
    mount()
    await settle()
    expect(root.querySelectorAll('.recharge-amounts button')).toHaveLength(13)
    const submit = [...root.querySelectorAll('button')].find((button) => button.textContent === 'recharge.create')!
    expect(submit.disabled).toBe(true)
    submit.click()
    expect(createRecharge).not.toHaveBeenCalled()
    expect(root.textContent).toContain('充值暂未开放')
  })

  it('reuses request id after a timeout and submits only once while busy', async () => {
    vi.mocked(createRecharge).mockRejectedValueOnce(new Error('timeout'))
    mount()
    await settle()
    expect(root.textContent).toContain('1.25 USDT')
    const submit = [...root.querySelectorAll('button')].find((button) => button.textContent === 'recharge.create')!
    submit.click()
    submit.click()
    await settle()
    expect(createRecharge).toHaveBeenCalledTimes(1)
    submit.click()
    await settle()
    const calls = vi.mocked(createRecharge).mock.calls
    expect(calls[0][0].request_id).toBe(calls[1][0].request_id)
    expect(calls[1][0].quote_key).toBe('quote')
    expect(root.textContent).toContain('1.2501 USDT')
    expect(localStorage.getItem('mgs-recharge:player:INR:order')).toBeNull()
  })

  it('restores the active order and stops polling while hidden or unmounted', async () => {
    vi.mocked(getCurrentRecharge).mockResolvedValue(order)
    mount()
    await settle()
    expect(root.textContent).toContain('MR-test')
    await vi.advanceTimersByTimeAsync(5000)
    expect(getRecharge).toHaveBeenCalledTimes(1)
    Object.defineProperty(document, 'hidden', { configurable: true, value: true })
    document.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(15000)
    expect(getRecharge).toHaveBeenCalledTimes(1)
    Object.defineProperty(document, 'hidden', { configurable: true, value: false })
    document.dispatchEvent(new Event('visibilitychange'))
    await settle()
    expect(getRecharge).toHaveBeenCalledTimes(2)
    app.unmount()
    await vi.advanceTimersByTimeAsync(10000)
    expect(getRecharge).toHaveBeenCalledTimes(2)
  })

  it.each(['paid', 'expired', 'closed', 'missing'] as const)('ignores the old %s order when the server has no active order', async (status) => {
    localStorage.setItem('mgs-recharge:player:INR:order', status)
    localStorage.setItem('mgs-recharge:player:INR:request:100:USDT', 'old-request')
    localStorage.setItem('mgs-recharge:other:INR:order', 'other-order')
    mount()
    await settle()
    expect(getCurrentRecharge).toHaveBeenCalledWith('INR')
    expect(getRecharge).not.toHaveBeenCalled()
    expect(root.textContent).not.toContain('MR-test')
    expect(root.querySelector('.recharge-amounts')).not.toBeNull()
    expect(localStorage.getItem('mgs-recharge:player:INR:order')).toBeNull()
    expect(localStorage.getItem('mgs-recharge:other:INR:order')).toBe('other-order')
    expect(createRecharge).not.toHaveBeenCalled()
    const submit = [...root.querySelectorAll('button')].find(button => button.textContent === 'recharge.create')!
    submit.click()
    await settle()
    expect(vi.mocked(createRecharge).mock.calls[0][0].request_id).not.toBe('old-request')
  })

  it('restores a review order without showing payment instructions or creating another order', async () => {
    vi.mocked(getCurrentRecharge).mockResolvedValue({ ...order, status: 'review' })
    mount()
    await settle()
    expect(root.textContent).toContain('MR-test')
    expect(root.textContent).toContain('recharge.review')
    expect(root.querySelector('.recharge-qr')).toBeNull()
    expect(root.textContent).not.toContain('recharge.newOrder')
    expect(createRecharge).not.toHaveBeenCalled()
  })

  it.each(['paid', 'expired', 'closed'] as const)('starts a fresh order after %s and reopening', async (status) => {
    mount()
    await settle()
    const submit = [...root.querySelectorAll('button')].find(button => button.textContent === 'recharge.create')!
    submit.click()
    await settle()
    const firstRequest = vi.mocked(createRecharge).mock.calls[0][0].request_id
    vi.mocked(getRecharge).mockResolvedValue({ ...order, status })
    await vi.advanceTimersByTimeAsync(5000)
    await settle()
    expect(root.textContent).toContain(`recharge.${status}`)
    expect(root.querySelector('.recharge-qr')).toBeNull()
    expect(paid).toHaveBeenCalledTimes(status === 'paid' ? 1 : 0)
    await vi.advanceTimersByTimeAsync(10000)
    expect(getRecharge).toHaveBeenCalledTimes(1)
    app.unmount()
    root.remove()
    mount()
    await settle()
    expect(root.textContent).not.toContain('MR-test')
    expect(root.querySelector('.recharge-amounts')).not.toBeNull()
    const next = [...root.querySelectorAll('button')].find(button => button.textContent === 'recharge.create')!
    next.click()
    await settle()
    expect(vi.mocked(createRecharge).mock.calls[1][0].request_id).not.toBe(firstRequest)
  })

  it('allows a new order immediately when the displayed countdown expires', async () => {
    vi.mocked(getCurrentRecharge).mockResolvedValueOnce({ ...order, expire_time: '2026-09-17T01:00:01.000Z' })
    mount()
    await settle()
    await vi.advanceTimersByTimeAsync(1000)
    expect(root.querySelector('.recharge-qr')).toBeNull()
    const next = [...root.querySelectorAll('button')].find(button => button.textContent === 'recharge.newOrder')!
    expect(next).toBeDefined()
    next.click()
    await settle()
    expect(root.querySelector('.recharge-amounts')).not.toBeNull()
    expect(createRecharge).not.toHaveBeenCalled()
  })

  it('keeps creation disabled if current-order lookup fails, and recovers on retry', async () => {
    vi.mocked(getCurrentRecharge).mockRejectedValueOnce(new Error('offline'))
    mount()
    await settle()
    expect(root.textContent).toContain('offline')
    const submit = [...root.querySelectorAll('button')].find(button => button.textContent === 'recharge.create')!
    expect(submit.disabled).toBe(true)
    expect(getRechargeOptions).not.toHaveBeenCalled()
    const retry = [...root.querySelectorAll('button')].find(button => button.textContent === 'recharge.refresh')!
    retry.click()
    await settle()
    expect(submit.disabled).toBe(false)
    expect(createRecharge).not.toHaveBeenCalled()
  })
})
