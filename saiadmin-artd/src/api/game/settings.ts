import request from '@/utils/http'

export default {
  configs: () => request.get<any[]>({ url: '/game/settings' }),
  save: (values: Record<string, unknown>) =>
    request.put({ url: '/game/settings', data: { values } }),
  rebuildStatus: () => request.get<any>({ url: '/game/settings/rebuild-status' }),
  exchangeRates: (params: Record<string, unknown>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/exchange-rates', params }),
  syncExchangeRate: () => request.post({ url: '/game/exchange-rates/sync' })
}
