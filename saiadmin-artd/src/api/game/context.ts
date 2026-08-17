import request from '@/utils/http'

export default {
  read: () => request.get<any>({ url: '/game/context' })
}
