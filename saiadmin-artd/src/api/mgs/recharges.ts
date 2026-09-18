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

export interface TronBlock {
  height: number
  hash: string
  parent_hash: string
  block_time: string
  transactions: number
  transfers: number
  duration_ms: number
}

export interface RecentRecharge extends Omit<RechargeRow, 'status' | 'create_time'> {
  credited_time: string
  transfers: { transaction_id: string; block_number: number }[]
}

export interface TronStatus {
  ready: boolean
  checkpoint: {
    next_block_number: number
    last_success_time: number
    last_block_hash: string
    start_block_number: number
  } | null
  health: {
    solid_number: number
    solid_time: number
    heartbeat_time: number
    error: string | null
  } | null
  gaps: Record<
    string,
    {
      from: number
      to: number
      next: number
      attempts: number
      retry_time: number
      error: string | null
    }
  >
  scanned_number: number | null
  lag_blocks: number | null
  gap_blocks: number
  recent_blocks: TronBlock[]
  recent_recharges: RecentRecharge[] | null
  server_time: number
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
  scan: (signal?: AbortSignal) =>
    request.get<TronStatus>({ url: '/mgs/tron-scan', signal, showErrorMessage: false })
}
