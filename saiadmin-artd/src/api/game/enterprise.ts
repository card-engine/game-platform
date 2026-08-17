import request from '@/utils/http'

export default {
  list: (params: Record<string, any>) =>
    request.get<Api.Common.ApiPage>({ url: '/game/enterprise/index', params }),
  save: (data: Record<string, any>) => request.post({ url: '/game/enterprise/save', data }),
  update: (data: Record<string, any>) => request.put({ url: '/game/enterprise/update', data }),
  users: (enterprise_id: number) =>
    request.get<any[]>({ url: '/game/enterprise/users', params: { enterprise_id } }),
  saveUser: (data: Record<string, any>) => request.post({ url: '/game/enterprise/user', data }),
  userStatus: (data: Record<string, any>) =>
    request.put({ url: '/game/enterprise/user/status', data }),
  userMerchants: (data: Record<string, any>) =>
    request.put({ url: '/game/enterprise/user/merchants', data }),
  userPassword: (data: Record<string, any>) =>
    request.put({ url: '/game/enterprise/user/password', data }),
  roles: () => request.get<any[]>({ url: '/game/enterprise/roles' })
}
