import axios from 'axios'
import type { ApiResponse, Balance, BrandStat, GameItem, GameRecommendations, GameType, UserProfile } from '../types/game'

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
  const { data } = await api.post<ApiResponse<{ token: string; default_currency_code: string; user: UserProfile; wallets: Balance[] }>>('/session', { language }, { headers: { Authorization: `Bearer ${token}` } })
  return result(data)
}

export async function getBrandStats() {
  const { data } = await api.get<ApiResponse<{ categories: { code: string; count: number; brands: { code: string; name: string; count: number }[] }[] }>>('/brands', { params: { currency_code: localStorage.getItem('mgs-currency-code') || 'INR' } })
  return Object.fromEntries(result(data).categories.map((category) => [category.code, category.brands.map((item) => ({ gameBrand: item.code, gameType: category.code as GameType, count: item.count }))])) as Record<GameType, BrandStat[]>
}

interface ApiGame {
  mgs_game_id: number
  name: string
  icon_url: string
  game_type: string
  brand_code: string
  currency_codes: string[]
  status: number
}

const mapGame = (game: ApiGame): GameItem => ({
  gameId: String(game.mgs_game_id), gameName: game.name, gameFullName: game.name,
  gameType: game.game_type as GameType, gameBrand: game.brand_code, gameIcon: game.icon_url,
  proxyModel: 'mgs', currencyCodes: game.currency_codes, status: game.status,
})

export async function getGames(gameBrand: string, gameType: GameType) {
  const { data } = await api.get<ApiResponse<{ list: ApiGame[] }>>('/games', { params: { brand_code: gameBrand, game_type: gameType, currency_code: localStorage.getItem('mgs-currency-code') || 'INR', page: 1, limit: 500 } })
  return result(data).list.map(mapGame)
}

export async function getUser() {
  const { data } = await api.get<ApiResponse<UserProfile>>('/user')
  return result(data)
}

export async function updateUser(nickname: string) {
  const { data } = await api.put<ApiResponse<UserProfile>>('/user', { nickname })
  return result(data)
}

export async function getUserGames(seed: number): Promise<GameRecommendations> {
  const { data } = await api.get<ApiResponse<{ recent: ApiGame[]; hot: ApiGame[]; discover: ApiGame[] }>>('/user/games', { params: { currency_code: localStorage.getItem('mgs-currency-code') || 'INR', seed } })
  const groups = result(data)
  return { recent: groups.recent.map(mapGame), hot: groups.hot.map(mapGame), discover: groups.discover.map(mapGame) }
}

export async function getGameLink(payload: { gameId: string; language: string }) {
  const { data } = await api.post<ApiResponse<{ game_url: string }>>('/games/launch', { mgs_game_id: Number(payload.gameId), currency_code: localStorage.getItem('mgs-currency-code') || 'INR', language: payload.language })
  return result(data).game_url
}

export async function getBalance() {
  const { data } = await api.get<ApiResponse<Balance[]>>('/wallet')
  const currency = localStorage.getItem('mgs-currency-code') || 'INR'
  const wallet = result(data).find((item) => item.currency_code === currency)
  return { balance: Number(wallet?.balance || 0), currency }
}

export interface RechargePayment {
  pay_currency_code: 'USDT' | 'TRX'
  quote_key: string
  amounts: Record<string, string>
}

export interface RechargeOptions {
  currency_code: string
  amounts: number[]
  default_amount: number
  available: boolean
  unavailable_reason: string | null
  payments: RechargePayment[]
}

export interface RechargeOrder {
  mgs_recharge_id: string
  recharge_no: string
  currency_code: string
  recharge_amount: string
  pay_currency_code: 'USDT' | 'TRX'
  pay_amount: string
  receive_address: string
  status: 'pending' | 'review' | 'expired' | 'closed' | 'paid'
  expire_time: string
  server_time: string
  create_time: string
  credited_time: string | null
  transfers?: { transaction_id: string; block_number: number; block_time: string }[]
}

export async function getRechargeOptions(currencyCode: string) {
  const { data } = await api.get<ApiResponse<RechargeOptions>>('/recharges/options', { params: { currency_code: currencyCode } })
  return result(data)
}

export async function createRecharge(payload: { currency_code: string; recharge_amount: number; pay_currency_code: string; request_id: string; quote_key: string }) {
  const { data } = await api.post<ApiResponse<RechargeOrder>>('/recharges', payload)
  return result(data)
}

export async function getCurrentRecharge(currencyCode: string) {
  const { data } = await api.get<ApiResponse<{ order: RechargeOrder | null }>>('/recharges/current', { params: { currency_code: currencyCode } })
  return result(data).order
}

export async function getRecharge(id: string) {
  const { data } = await api.get<ApiResponse<RechargeOrder>>(`/recharges/${encodeURIComponent(id)}`)
  return result(data)
}

export async function getRechargeHistory(page: number) {
  const { data } = await api.get<ApiResponse<{ list: RechargeOrder[]; page: number; has_more: boolean }>>('/recharges', { params: { page } })
  return result(data)
}
