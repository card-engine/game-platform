import request from '@/utils/http'

export interface Settlement {
  id: number
  settlement_no: string
  settlement_month: string
  currency_code: string
  platform_fee: string
  ggr_amount: string
  status: number
  confirmed_by: number | null
  confirmed_time: string | null
  paid_by: number | null
  paid_time: string | null
  payment_reference: string | null
  remark: string | null
  data: {
    timezone: string
    source: {
      bill_no: string
      rules_snapshot: { rates: { merchant_rate_value: string; ggr_amount: string }[] }
    }
  }
}
export default {
  read: (id: number) => request.get<Settlement>({ url: `/mgs/settlements/${id}` }),
  confirm: (id: number, data: { remark: string }) =>
    request.put({ url: `/mgs/settlements/${id}/confirm`, data }),
  reopen: (id: number, data: { remark: string }) =>
    request.put({ url: `/mgs/settlements/${id}/reopen`, data }),
  pay: (id: number, data: { remark: string; payment_reference: string; paid_time: string }) =>
    request.put({ url: `/mgs/settlements/${id}/pay`, data })
}
