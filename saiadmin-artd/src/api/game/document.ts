import request from '@/utils/http'

export default {
  read: (merchant_id?: number) =>
    request.get<{
      content: string
      merchant_id?: number
      merchants: { id: number; label: string }[]
      notice?: string
    }>({
      url: '/game/document',
      params: { merchant_id }
    })
}
