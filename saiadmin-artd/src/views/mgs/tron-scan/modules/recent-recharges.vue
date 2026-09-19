<script setup lang="ts">
  import { useGameTime } from '@/composables/useGameTime'
  import { useI18n } from 'vue-i18n'
  import type { RecentRecharge } from '@/api/mgs/recharges'
  import { displayAmount } from '@/utils/game/amount'

  defineProps<{ rows: RecentRecharge[] }>()
  const { t } = useI18n()
  const { timezone, formatTime } = useGameTime()
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
      <table class="recharge-table" :aria-label="t('tronScan.recentRecharges')">
        <thead>
          <tr>
            <th>{{ t('mgsRecharge.rechargeNo') }}</th>
            <th>{{ t('mgs.user') }}</th>
            <th class="numeric">{{ t('mgsRecharge.creditAmount') }}</th>
            <th class="numeric">{{ t('mgsRecharge.payAmount') }}</th>
            <th>{{ t('tronScan.creditedTime') }} · {{ timezone }}</th>
            <th>{{ t('mgsRecharge.transaction') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td class="order-cell">
              <RouterLink
                :to="{ path: '/mgs/recharges', query: { keyword: row.recharge_no } }"
                class="text-primary"
                >{{ row.recharge_no }}</RouterLink
              >
            </td>
            <td :data-label="t('mgs.user')">{{ row.user_id }}</td>
            <td class="numeric" :data-label="t('mgsRecharge.creditAmount')"
              >{{ displayAmount(row.recharge_amount) }} {{ row.currency_code }}</td
            >
            <td class="numeric" :data-label="t('mgsRecharge.payAmount')"
              >{{ displayAmount(row.pay_amount) }} {{ row.pay_currency_code }}</td
            >
            <td
              :title="formatTime(row.credited_time) + ' ' + timezone"
              :data-label="t('tronScan.creditedTime') + ' · ' + timezone"
              >{{ formatTime(row.credited_time, 'short') }}</td
            >
            <td class="transfer-cell">
              <template v-for="transfer in row.transfers" :key="transfer.transaction_id">
                <ElLink
                  :href="`https://tronscan.org/#/transaction/${transfer.transaction_id}`"
                  :title="transfer.transaction_id"
                  target="_blank"
                  rel="noopener noreferrer"
                  type="primary"
                  >{{ transfer.transaction_id.slice(0, 6) }}…{{
                    transfer.transaction_id.slice(-6)
                  }}
                  ↗</ElLink
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
  .recharge-card {
    min-width: 0;
  }
  .recharge-card :deep(.el-card__header) {
    padding: 10px 14px;
  }
  .recharge-card :deep(.el-card__body) {
    padding: 0 14px 8px;
  }
  .recharge-heading {
    font-size: 13px;
  }
  .recharge-table-wrap {
    overflow-x: auto;
  }
  .recharge-table :deep(.el-link) {
    font-size: 12px;
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
    height: 30px;
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
  .transfer-cell :deep(.el-link) {
    margin-right: 8px;
  }
  .empty {
    color: var(--el-text-color-secondary);
    text-align: center !important;
  }
  @media (min-width: 1101px) and (max-height: 760px) {
    .recharge-table th,
    .recharge-table td {
      height: 26px;
      padding-top: 3px;
      padding-bottom: 3px;
    }
  }
  @media (max-width: 700px) {
    .recharge-heading {
      flex-wrap: wrap;
    }
    .recharge-table,
    .recharge-table tbody {
      display: block;
      white-space: normal;
    }
    .recharge-table thead {
      display: none;
    }
    .recharge-table tr {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px 12px;
      border-top: 1px solid var(--el-border-color-lighter);
      padding: 12px 0;
    }
    .recharge-table td,
    .recharge-table .numeric {
      height: auto;
      padding: 0;
      border: 0;
      text-align: left;
      overflow-wrap: anywhere;
    }
    .order-cell,
    .transfer-cell,
    .empty {
      grid-column: 1 / -1;
    }
    td[data-label]::before {
      display: block;
      margin-bottom: 3px;
      color: var(--el-text-color-secondary);
      content: attr(data-label);
    }
  }
</style>
