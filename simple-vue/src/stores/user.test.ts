import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { useUserStore } from './user'

describe('user store', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('duozhi-player-id', 'player')
    setActivePinia(createPinia())
  })

  it('restores the selected language', async () => {
    useUserStore().language = 'zh-CN'
    await nextTick()

    setActivePinia(createPinia())
    expect(useUserStore().language).toBe('zh-CN')
  })
})
