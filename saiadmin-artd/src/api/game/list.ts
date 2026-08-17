import request from '@/utils/http'

export default {
  brands: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/brands', params }),
  uniqueBrands: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/unique-brands', params }),
  list: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/lists', params }),
  trial: (data: { game_id: number; merchant_id: number; currency: string }) =>
    request.post<{ game_url: string }>({ url: '/game/trial', data }),
  sync: (platform_code: string) =>
    request.post<any>({ url: '/game/sync', data: { platform_code } }),
  status: (ids: number[], status: number) =>
    request.put({ url: '/game/status', data: { ids, status } }),
  brandImpact: (brand_id: number, unique_brand_id: number) =>
    request.get<any>({ url: '/game/brand-impact', params: { brand_id, unique_brand_id } }),
  mapBrand: (data: Record<string, any>) => request.put({ url: '/game/brand-map', data }),
  brandMode: (data: Record<string, any>) => request.put({ url: '/game/brand-mode', data })
}
