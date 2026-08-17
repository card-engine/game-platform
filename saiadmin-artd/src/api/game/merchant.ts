import request from '@/utils/http'

export default {
  list: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/merchant/index', params }),
  save: (data: Record<string, any>) => request.post({ url: '/game/merchant/save', data }),
  update: (data: Record<string, any>) => request.put({ url: '/game/merchant/update', data }),
  options: () => request.get<any>({ url: '/game/merchant/options' }),
  secret: (id: number) => request.get<any>({ url: '/game/merchant/secret', params: { id } }),
  resetSecret: (id: number) => request.put<any>({ url: '/game/merchant/secret', data: { id } }),
  grants: (id: number) => request.get<any>({ url: '/game/merchant/grants', params: { id } }),
  saveGrants: (data: Record<string, any>) => request.put({ url: '/game/merchant/grants', data }),
  saveCredits: (data: Record<string, any>) => request.put({ url: '/game/merchant/credits', data }),
  adjustCredit: (data: Record<string, any>) => request.post({ url: '/game/merchant/credit', data }),
  billing: (id: number) => request.get<any>({ url: '/game/merchant/billing', params: { id } }),
  saveBilling: (data: Record<string, any>) => request.put({ url: '/game/merchant/billing', data }),
  updateMonthlyBill: (data: Record<string, any>) =>
    request.put({ url: '/game/merchant/monthly-bill', data })
}
