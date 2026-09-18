<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import type { RecentRecharge } from '@/api/mgs/recharges'
  defineProps<{ rows: RecentRecharge[] }>()
  const { t } = useI18n()
  const columns = computed(() => [
    { prop: 'recharge_no', label: t('mgsRecharge.rechargeNo'), minWidth: 245, useSlot: true },
    { prop: 'recharge_amount', label: t('mgsRecharge.creditAmount'), minWidth: 170, useSlot: true },
    { prop: 'pay_amount', label: t('mgsRecharge.payAmount'), minWidth: 170, useSlot: true },
    { prop: 'credited_time', label: t('tronScan.creditedTime'), minWidth: 180, useSlot: true },
    { prop: 'transaction_id', label: t('mgsRecharge.transaction'), minWidth: 160, useSlot: true }
  ])
</script>
<template>
  <ElCard shadow="never">
    <template #header
      ><div class="flex flex-wrap items-center justify-between gap-3"
        ><strong>{{ t('tronScan.recentRecharges') }}</strong
        ><RouterLink to="/mgs/recharges" class="text-sm text-primary"
          >{{ t('tronScan.allRecharges') }} →</RouterLink
        ></div
      ></template
    >
    <ArtTable :data="rows" :columns="columns" row-key="id">
      <template #recharge_no="{ row }"
        ><RouterLink
          :to="{ path: '/mgs/recharges', query: { keyword: row.recharge_no } }"
          class="text-primary"
          >{{ row.recharge_no }}</RouterLink
        ><div class="text-xs text-g-500">{{ t('mgs.user') }} {{ row.user_id }}</div></template
      >
      <template #recharge_amount="{ row }"
        >{{ row.recharge_amount }} {{ row.currency_code
        }}<div class="text-xs text-success">{{ t('mgsRecharge.credited') }}</div></template
      >
      <template #pay_amount="{ row }">{{ row.pay_amount }} {{ row.pay_currency_code }}</template>
      <template #credited_time="{ row }">{{ row.credited_time }} UTC</template>
      <template #transaction_id="{ row }"
        ><div v-for="transfer in row.transfers" :key="transfer.transaction_id"
          ><ElLink
            :href="`https://tronscan.org/#/transaction/${transfer.transaction_id}`"
            :title="transfer.transaction_id"
            target="_blank"
            rel="noopener noreferrer"
            type="primary"
            >{{ transfer.transaction_id.slice(0, 8) }}…{{
              transfer.transaction_id.slice(-6)
            }}
            ↗</ElLink
          ><div
            ><ElLink
              :href="`https://tronscan.org/#/block/${transfer.block_number}`"
              target="_blank"
              rel="noopener noreferrer"
              >#{{ transfer.block_number }}</ElLink
            ></div
          ></div
        ></template
      >
    </ArtTable>
  </ElCard>
</template>
