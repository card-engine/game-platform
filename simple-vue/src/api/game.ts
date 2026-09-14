import axios from 'axios'
import type { ApiResponse, Balance, BrandStat, GameType } from '../types/game'

const api = axios.create({ baseURL: '/api', timeout: 15000 })
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('mgs-player-token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

const result = <T>(response: ApiResponse<T>) => {
  if (response.code !== 200) throw new Error(response.message || '请求失败')
  return response.data
}

export async function createSession(token: string, language: string) {
  const { data } = await api.post<ApiResponse<{ token: string; default_currency_code: string; user: { mgs_user_id: number; unique_id: number; language: string }; wallets: Balance[] }>>('/session', { language }, { headers: { Authorization: `Bearer ${token}` } })
  return result(data)
}

export async function getBrandStats() {
  const { data } = await api.get<ApiResponse<{ categories: { code: string; count: number; brands: { code: string; name: string; count: number }[] }[] }>>('/brands', { params: { currency_code: localStorage.getItem('mgs-currency-code') || 'INR' } })
  return Object.fromEntries(result(data).categories.map((category) => [category.code, category.brands.map((item) => ({ gameBrand: item.code, gameType: category.code as GameType, count: item.count }))])) as Record<GameType, BrandStat[]>
}

export async function getGames(gameBrand: string, gameType: GameType) {
  const { data } = await api.get<ApiResponse<{ list: Array<{ mgs_game_id: number; name: string; icon_url: string; game_type: string; brand_code: string; currency_codes: string[]; status: number }> }>>('/games', { params: { brand_code: gameBrand, game_type: gameType, currency_code: localStorage.getItem('mgs-currency-code') || 'INR', page: 1, limit: 500 } })
  return result(data).list.map((game) => ({ gameId: String(game.mgs_game_id), gameName: game.name, gameFullName: game.name, gameType: game.game_type as GameType, gameBrand: game.brand_code, gameIcon: game.icon_url, proxyModel: 'mgs', currencyCodes: game.currency_codes, status: game.status }))
}

export async function getGameLink(payload: { gameId: string; language: string }) {
  const { data } = await api.post<ApiResponse<{ game_url: string }>>('/games/launch', { mgs_game_id: Number(payload.gameId), currency_code: localStorage.getItem('mgs-currency-code') || 'INR', language: payload.language })
  return result(data).game_url
}

export async function getBalance() {
  const { data } = await api.get<ApiResponse<Balance[]>>('/wallet')
  const wallet = result(data)[0]
  return wallet ? { balance: Number(wallet.balance), currency: wallet.currency_code || wallet.currency || 'INR' } : { balance: 0, currency: localStorage.getItem('mgs-currency-code') || 'INR' }
}
