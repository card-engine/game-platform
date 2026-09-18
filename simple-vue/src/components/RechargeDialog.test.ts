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

async function settle() {
  for (let i = 0; i < 8; i++) await Promise.resolve()
  await nextTick()
}

function mount() {
  root = document.createElement('div')
  document.body.append(root)
  app = createApp(RechargeDialog, { currency: 'INR', userId: 'player' })
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
  vi.clearAllMocks()
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
    expect(localStorage.getItem('mgs-recharge:player:INR:order')).toBe('1')
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

  it('can recover when the locally saved order no longer exists', async () => {
    localStorage.setItem('mgs-recharge:player:INR:order', 'missing-order')
    vi.mocked(getRecharge).mockRejectedValueOnce(new Error('not found'))
    mount()
    await settle()
    const reset = [...root.querySelectorAll('button')].find((button) => button.textContent === 'recharge.newOrder')!
    reset.click()
    await settle()
    expect(localStorage.getItem('mgs-recharge:player:INR:order')).toBeNull()
    expect(root.textContent).toContain('1.25 USDT')
    expect(createRecharge).not.toHaveBeenCalled()
  })
})
