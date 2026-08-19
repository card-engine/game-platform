import request from '@/utils/http'

export default {
  overview: () => request.get<any>({ url: '/mgs/overview' }),
  games: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/mgs/games', params }),
  sync: () => request.post<any>({ url: '/mgs/games/sync' }),
  gameStatus: (data: { id: number; status: number }) =>
    request.put({ url: '/mgs/games/status', data }),
  gameConfig: (data: Record<string, any>) => request.put({ url: '/mgs/games/config', data }),
  users: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/mgs/users', params }),
  bets: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/mgs/bets', params }),
  bills: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/mgs/bills', params }),
  reports: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/mgs/reports', params }),
  settlements: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/mgs/settlements', params }),
  generateSettlement: (month: string) =>
    request.post<{ count: number }>({ url: '/mgs/settlements/generate', data: { month } })
}
