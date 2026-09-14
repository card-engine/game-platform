export type IosBrowser = 'safari' | 'chrome'

export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export function getIosBrowser(userAgent: string, platform: string, maxTouchPoints: number): IosBrowser | undefined {
  const ios = /iPad|iPhone|iPod/i.test(userAgent) || (platform === 'MacIntel' && maxTouchPoints > 1)
  if (!ios) return
  return /CriOS|FxiOS|EdgiOS|OPiOS|GSA/i.test(userAgent) ? 'chrome' : 'safari'
}

export function isPwaStandalone() {
  return window.matchMedia?.('(display-mode: standalone)').matches
    || (navigator as Navigator & { standalone?: boolean }).standalone === true
}
