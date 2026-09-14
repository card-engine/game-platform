import gamePlaceholder from './assets/images/game-placeholder.webp'

export const GAME_PLACEHOLDER = gamePlaceholder

export function finishImageLoading(event: Event) {
  (event.currentTarget as HTMLImageElement).parentElement?.classList.add('image-loaded')
}

export function setGamePlaceholder(event: Event) {
  (event.currentTarget as HTMLImageElement).src = GAME_PLACEHOLDER
}
