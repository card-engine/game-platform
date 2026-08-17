import request from '@/utils/http'

export default {
  list: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/platform-admin/index', params }),
  options: () => request.get<any>({ url: '/game/platform-admin/options' }),
  save: (data: Record<string, any>) => request.post({ url: '/game/platform-admin/save', data }),
  update: (data: Record<string, any>) => request.put({ url: '/game/platform-admin/update', data }),
  password: (data: Record<string, any>) =>
    request.put({ url: '/game/platform-admin/password', data }),
  delete: (id: number) => request.del({ url: '/game/platform-admin/destroy', data: { id } })
}
