import { describe, expect, it } from 'vitest'
import { getIosBrowser } from './pwa'

describe('getIosBrowser', () => {
  it('detects Safari and Chromium browsers on iOS', () => {
    expect(getIosBrowser('Mozilla/5.0 (iPhone) Version/18.0 Mobile Safari/604.1', 'iPhone', 5)).toBe('safari')
    expect(getIosBrowser('Mozilla/5.0 (iPhone) CriOS/130.0 Mobile Safari/604.1', 'iPhone', 5)).toBe('chrome')
  })

  it('detects touch iPads and excludes Android', () => {
    expect(getIosBrowser('Mozilla/5.0 Macintosh Safari/605.1.15', 'MacIntel', 5)).toBe('safari')
    expect(getIosBrowser('Mozilla/5.0 (Linux; Android 15) Chrome/130.0', 'Linux armv8l', 5)).toBeUndefined()
  })
})
