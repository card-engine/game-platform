import request from '@/utils/http'

export default {
  overview: () => request.get<any>({ url: '/game/operations/overview' }),
  users: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/operations/users', params }),
  bets: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/operations/bets', params }),
  bills: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/operations/bills', params }),
  merchantBills: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/operations/merchant-bills', params }),
  reports: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/operations/reports', params })
}
