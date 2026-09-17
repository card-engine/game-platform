import request from '@/utils/http'

export interface RechargeRow {
  id: string
  recharge_no: string
  user_id: string
  currency_code: string
  recharge_amount: string
  pay_currency_code: string
  pay_amount: string
  status: string
  create_time: string
}

export interface TransferRow {
  id: string
  recharge_id: string | null
  transaction_id: string
  currency_code: string
  amount: string
  receive_address: string
  block_time: string
  status: string
  remark: string | null
}

export default {
  list: (params: Record<string, unknown>) =>
    request.get<Api.Common.ApiPage<RechargeRow>>({ url: '/mgs/recharges', params }),
  read: (id: string) => request.get<Record<string, unknown>>({ url: `/mgs/recharges/${id}` }),
  transfers: (params: Record<string, unknown>) =>
    request.get<Api.Common.ApiPage<TransferRow>>({ url: '/mgs/transfers', params }),
  transfer: (id: string) => request.get<Record<string, unknown>>({ url: `/mgs/transfers/${id}` }),
  credit: (id: string, data: { mgs_recharge_id: string; remark: string }) =>
    request.post({ url: `/mgs/transfers/${id}/credit`, data }),
  review: (id: string, data: { status: string; remark: string }) =>
    request.put({ url: `/mgs/transfers/${id}/review`, data }),
  scan: () =>
    request.get<{
      ready: boolean
      checkpoint: Record<string, unknown> | null
      health: Record<string, unknown> | null
      gaps: Record<string, unknown>
    }>({ url: '/mgs/recharge-scan' })
}
