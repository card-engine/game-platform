import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAccessStore } from './access'

describe('access store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('rejects an incorrect password', () => {
    const access = useAccessStore()
    expect(access.verify('wrong-password')).toBe(false)
    expect(access.hasAccess()).toBe(false)
  })

  it('persists access after a successful verification', () => {
    const access = useAccessStore()
    expect(access.verify('mgames')).toBe(true)
    expect(access.hasAccess()).toBe(true)
  })
})
