<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import type { RecentRecharge } from '@/api/mgs/recharges'

  defineProps<{ rows: RecentRecharge[] }>()
  const { t } = useI18n()
  const time = (value: string) => value.replace(' ', 'T').slice(11, 19)
  const short = (value: string) => `${value.slice(0, 8)}…${value.slice(-6)}`
</script>

<template>
  <ElCard class="recharge-card" shadow="never">
    <template #header>
      <div class="recharge-heading">
        <strong>{{ t('tronScan.recentRecharges') }}</strong>
        <RouterLink to="/mgs/recharges" class="text-primary"
          >{{ t('tronScan.allRecharges') }} →</RouterLink
        >
      </div>
    </template>
    <div class="recharge-table-wrap">
      <table class="recharge-table">
        <thead>
          <tr>
            <th>{{ t('mgsRecharge.rechargeNo') }}</th>
            <th>{{ t('mgs.user') }}</th>
            <th class="numeric">{{ t('mgsRecharge.creditAmount') }}</th>
            <th class="numeric">{{ t('mgsRecharge.payAmount') }}</th>
            <th>{{ t('tronScan.creditedTime') }}</th>
            <th>{{ t('mgsRecharge.transaction') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td>
              <RouterLink
                :to="{ path: '/mgs/recharges', query: { keyword: row.recharge_no } }"
                class="text-primary"
                >{{ row.recharge_no }}</RouterLink
              >
            </td>
            <td>{{ row.user_id }}</td>
            <td class="numeric">{{ row.recharge_amount }} {{ row.currency_code }}</td>
            <td class="numeric">{{ row.pay_amount }} {{ row.pay_currency_code }}</td>
            <td>{{ time(row.credited_time) }} UTC</td>
            <td>
              <template v-for="transfer in row.transfers" :key="transfer.transaction_id">
                <ElLink
                  :href="`https://tronscan.org/#/transaction/${transfer.transaction_id}`"
                  :title="transfer.transaction_id"
                  target="_blank"
                  rel="noopener noreferrer"
                  type="primary"
                  >{{ short(transfer.transaction_id) }} ↗</ElLink
                >
                <ElLink
                  :href="`https://tronscan.org/#/block/${transfer.block_number}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  >#{{ transfer.block_number }}</ElLink
                >
              </template>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="6" class="empty">—</td>
          </tr>
        </tbody>
      </table>
    </div>
  </ElCard>
</template>

<style scoped>
  .recharge-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }
  .recharge-table-wrap {
    overflow-x: auto;
  }
  .recharge-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    line-height: 1.35;
    white-space: nowrap;
  }
  .recharge-table th {
    color: var(--el-text-color-secondary);
    font-weight: 500;
  }
  .recharge-table th,
  .recharge-table td {
    height: 28px;
    padding: 4px 8px;
    border-top: 1px solid var(--el-border-color-lighter);
    text-align: left;
  }
  .recharge-table th:first-child,
  .recharge-table td:first-child {
    padding-left: 0;
  }
  .recharge-table th:last-child,
  .recharge-table td:last-child {
    padding-right: 0;
  }
  .recharge-table .numeric {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }
  .recharge-table td:last-child :deep(.el-link) + :deep(.el-link) {
    margin-left: 8px;
  }
  .empty {
    color: var(--el-text-color-secondary);
    text-align: center !important;
  }
</style>
