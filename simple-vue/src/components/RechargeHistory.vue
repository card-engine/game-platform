<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/vue-query'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ReceiptText, RefreshCw } from '@lucide/vue'
import { getRecharge, getRechargeHistory } from '../api/game'
import { useUserStore } from '../stores/user'
import CurrencyIcon from './CurrencyIcon.vue'

const user = useUserStore()
const { t } = useI18n()
const selected = ref('')
const queryClient = useQueryClient()
const { data, isPending, isError, isFetching, fetchNextPage, hasNextPage, refetch } = useInfiniteQuery({
  queryKey: computed(() => ['recharge-history', user.uniqueId]),
  queryFn: ({ pageParam }) => getRechargeHistory(pageParam),
  initialPageParam: 1,
  getNextPageParam: (last) => last.has_more ? last.page + 1 : undefined,
})
const { data: detail, isPending: detailPending, isError: detailError, refetch: refreshDetail } = useQuery({
  queryKey: computed(() => ['recharge-detail', user.uniqueId, selected.value]),
  queryFn: () => getRecharge(selected.value),
  enabled: computed(() => !!selected.value),
})
const orders = computed(() => data.value?.pages.flatMap((page) => page.list) ?? [])
watch(detail, (order) => {
  if (order && orders.value.some((row) => row.mgs_recharge_id === order.mgs_recharge_id && row.status !== order.status)) {
    void queryClient.invalidateQueries({ queryKey: ['recharge-history', user.uniqueId] })
  }
})
// 金额保持字符串，只去除小数部分末尾的零。
const amount = (value: string) => value.replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1')
const time = (value: string) => value.replace('T', ' ').slice(0, 19) + ' UTC'
</script>

<template>
  <section class="recharge-history">
    <header><h2><ReceiptText :size="19" />{{ t('rechargeHistory.title') }}</h2><button type="button" :disabled="isFetching" :aria-label="t('recharge.refresh')" @click="refetch()"><RefreshCw :size="16" /></button></header>
    <el-skeleton v-if="isPending" :rows="3" animated />
    <div v-if="isError" class="history-message" role="alert">{{ t('recharge.failed') }} <el-button text @click="refetch()">{{ t('tryAgain') }}</el-button></div>
    <p v-else-if="!isPending && !orders.length" class="history-message">{{ t('rechargeHistory.empty') }}</p>
    <article v-for="order in orders" :key="order.mgs_recharge_id" class="history-row">
      <button type="button" class="history-summary" :aria-expanded="selected === order.mgs_recharge_id" @click="selected = selected === order.mgs_recharge_id ? '' : order.mgs_recharge_id">
        <span class="history-main"><strong><CurrencyIcon :code="order.currency_code" :size="20" />{{ amount(order.recharge_amount) }} {{ order.currency_code }}</strong><small>{{ t(order.status === 'paid' ? 'rechargeHistory.paidAmount' : 'rechargeHistory.payable') }} {{ amount(order.pay_amount) }} {{ order.pay_currency_code }}</small><small>{{ time(order.create_time) }}</small></span>
        <span class="history-status" :class="order.status"><span>{{ t(`rechargeHistory.status.${order.status}`) }}</span><small>{{ t('rechargeHistory.details') }} <ChevronDown :size="13" /></small></span>
      </button>
      <div v-if="selected === order.mgs_recharge_id" class="history-detail">
        <el-skeleton v-if="detailPending" :rows="2" animated />
        <p v-else-if="detailError" role="alert">{{ t('recharge.failed') }} <el-button text @click="refreshDetail()">{{ t('tryAgain') }}</el-button></p>
        <template v-else-if="detail">
          <dl><dt>{{ t('rechargeHistory.order') }}</dt><dd>{{ detail.recharge_no }}</dd><dt>{{ t('rechargeHistory.created') }}</dt><dd>{{ time(detail.create_time) }}</dd><dt>{{ t('rechargeHistory.credited') }}</dt><dd>{{ detail.credited_time ? time(detail.credited_time) : t('rechargeHistory.notCredited') }}</dd></dl>
          <div v-for="transfer in detail.transfers" :key="transfer.transaction_id" class="history-transfer"><span>{{ t('rechargeHistory.transaction') }}</span><a :href="`https://tronscan.org/#/transaction/${transfer.transaction_id}`" target="_blank" rel="noopener noreferrer">{{ transfer.transaction_id }} ↗</a><a :href="`https://tronscan.org/#/block/${transfer.block_number}`" target="_blank" rel="noopener noreferrer">{{ t('rechargeHistory.block') }} #{{ transfer.block_number }} ↗</a></div>
          <p v-if="detail.status === 'expired'">{{ t('rechargeHistory.expiredHint') }}</p>
        </template>
      </div>
    </article>
    <div v-if="hasNextPage" class="history-more"><el-button :loading="isFetching" @click="fetchNextPage()">{{ t('rechargeHistory.more') }}</el-button></div>
  </section>
</template>

<style scoped>
.recharge-history { padding: 20px; margin-bottom: 24px; border: 1px solid var(--line); border-radius: 14px; background: var(--surface); }
header, h2, .history-summary, .history-main strong, .history-status small { display: flex; align-items: center; gap: 8px; }
header { justify-content: space-between; margin-bottom: 12px; }
h2 { font-size: 17px; margin: 0; }
button { font: inherit; color: inherit; background: transparent; border: 0; cursor: pointer; }
header button { padding: 10px; }
button:disabled { opacity: .5; cursor: default; }
.history-row { border-top: 1px solid var(--line); }
.history-summary { width: 100%; padding: 16px 0; justify-content: space-between; text-align: left; }
.history-main { display: grid; gap: 5px; min-width: 0; }
.history-main strong { font-size: 17px; font-weight: 600; flex-wrap: wrap; }
small, .history-message, .history-detail { color: var(--muted); font-size: 12px; }
.history-status { text-align: right; flex-shrink: 0; font-size: 13px; }
.history-status small { justify-content: flex-end; margin-top: 7px; }
.history-status.paid { color: var(--success, #29966b); }
.history-status.pending, .history-status.review { color: var(--warning, #b88120); }
.history-detail { padding-bottom: 18px; overflow-wrap: anywhere; }
dl { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 8px 16px; margin: 0; }
dd { margin: 0; text-align: right; color: var(--text); }
.history-transfer { display: grid; gap: 6px; margin-top: 12px; }
a { color: var(--accent); }
.history-more { border-top: 1px solid var(--line); padding-top: 16px; text-align: center; }
@media (max-width: 400px) { .recharge-history { padding: 14px; } .history-main strong { font-size: 15px; } }
</style>
