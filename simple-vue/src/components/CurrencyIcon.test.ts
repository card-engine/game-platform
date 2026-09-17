import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h, nextTick, reactive } from 'vue'
import CurrencyIcon from './CurrencyIcon.vue'

let app: ReturnType<typeof createApp>
let root: HTMLDivElement
afterEach(() => { app?.unmount(); root?.remove() })

describe('currency sprite', () => {
  it('uses one shared asset and switches the symbol when currency changes', async () => {
    const props = reactive({ code: 'inr' })
    root = document.createElement('div')
    app = createApp({ render: () => h(CurrencyIcon, props) })
    app.mount(root)
    const href = root.querySelector('use')!.getAttribute('href')!
    expect(href).toMatch(/currencies\.svg.*#INR$/)
    expect(root.querySelector('svg')!.getAttribute('aria-label')).toBe('INR')
    expect(root.querySelector('text')).toBeNull()
    props.code = 'TRX'
    await nextTick()
    expect(root.querySelector('use')!.getAttribute('href')!.split('#')[0]).toBe(href.split('#')[0])
    expect(root.querySelector('use')!.getAttribute('href')).toMatch(/#TRX$/)
  })

  it('renders any other currency with the shared badge and a readable code', () => {
    root = document.createElement('div')
    app = createApp(CurrencyIcon, { code: 'BDT' })
    app.mount(root)
    expect(root.querySelector('use')!.getAttribute('href')).toMatch(/#coin$/)
    expect(root.querySelector('text')!.textContent).toBe('BDT')
    expect(root.querySelector('img')).toBeNull()
  })
})
