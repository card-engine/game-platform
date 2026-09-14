export type GameType = 'slot' | 'fish' | 'table' | 'poker' | 'sport'

export interface BrandStat {
  gameBrand: string
  gameType: GameType
  count: number
}

export interface GameItem {
  gameId: string
  gameName: string
  gameFullName: string
  gameType: GameType
  gameBrand: string
  gameIcon: string
  proxyModel: string
  currencyCodes?: string[]
  status?: number
}

export interface Balance {
  balance: number | string
  currency?: string
  currency_code?: string
}

export interface ApiResponse<T> {
  code: number
  data: T
  message?: string
}
