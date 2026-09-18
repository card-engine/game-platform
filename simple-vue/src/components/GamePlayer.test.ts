import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import GamePlayer from './GamePlayer.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

let app: ReturnType<typeof createApp>
let root: HTMLDivElement

async function mount(mobile = true) {
  vi.useFakeTimers()
  vi.stubGlobal('matchMedia', () => ({ matches: mobile }))
  root = document.createElement('div')
  document.body.append(root)
  const visible = ref(true)
  app = createApp({ render: () => visible.value ? h(GamePlayer, {
    url: 'about:blank',
    game: { gameId: 'test', gameName: 'Test', gameFullName: 'Test', gameBrand: 'test', gameType: 'slot', gameIcon: '', proxyModel: '' },
    onClose: () => { visible.value = false },
  }) : null })
  app.mount(root)
  await nextTick()
}

afterEach(() => {
  app?.unmount()
  root?.remove()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('game player controls', () => {
  it('shows two mobile actions, excludes collapsed actions from interaction and collapses after 3 seconds', async () => {
    await mount()
    const handle = root.querySelector<HTMLButtonElement>('.game-player__handle')!
    const actions = root.querySelector('.game-player__actions')!
    expect(actions.children).toHaveLength(2)
    expect(actions.hasAttribute('inert')).toBe(true)
    handle.click()
    await nextTick()
    expect(handle.getAttribute('aria-expanded')).toBe('true')
    expect(actions.hasAttribute('inert')).toBe(false)
    vi.advanceTimersByTime(3000)
    await nextTick()
    expect(handle.getAttribute('aria-expanded')).toBe('false')
    expect(actions.hasAttribute('inert')).toBe(true)
  })

  it('refreshes the iframe and destroys it on close; outside clicks collapse the menu', async () => {
    await mount()
    const handle = root.querySelector<HTMLButtonElement>('.game-player__handle')!
    handle.click()
    await nextTick()
    const frame = root.querySelector('iframe')
    root.querySelector<HTMLButtonElement>('[aria-label="player.reload"]')!.click()
    await nextTick()
    expect(root.querySelector('iframe')).not.toBe(frame)
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await nextTick()
    expect(handle.getAttribute('aria-expanded')).toBe('false')
    handle.click()
    await nextTick()
    root.querySelector<HTMLButtonElement>('[aria-label="player.close"]')!.click()
    await nextTick()
    expect(root.querySelector('iframe')).toBeNull()
  })

  it('keeps all four desktop actions available without a handle', async () => {
    await mount(false)
    expect(root.querySelector('.game-player__handle')).toBeNull()
    expect(root.querySelectorAll('.game-player__action')).toHaveLength(4)
    expect(root.querySelector('.game-player__actions')!.hasAttribute('inert')).toBe(false)
  })
})
