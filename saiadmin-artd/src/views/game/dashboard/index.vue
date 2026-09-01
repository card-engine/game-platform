<template>
  <div class="dashboard-page" v-loading="loading">
    <ElAlert
      v-if="
        data.is_super_admin &&
        ['queued', 'running', 'failed'].includes(data.platform_stats_rebuild?.status)
      "
      :type="data.platform_stats_rebuild.status === 'failed' ? 'error' : 'warning'"
      :title="`${data.platform_stats_rebuild.message}（${data.platform_stats_rebuild.progress || 0}%）`"
      :closable="false"
      show-icon
    />
    <section class="metric-grid">
      <div v-for="item in summary" :key="item.label" class="metric-card">
        <div class="metric-card__header">
          <span>{{ item.label }}</span>
          <ArtSvgIcon :icon="item.icon" />
        </div>
        <div class="metric-card__value">
          <strong>{{ item.primary }}</strong
          ><span>/</span><strong>{{ item.secondary }}</strong>
        </div>
        <div class="metric-card__detail">{{ item.detail }}</div>
      </div>
    </section>

    <section class="trend-grid">
      <ElCard class="dashboard-card" shadow="never">
        <template #header
          ><div class="card-title">{{ $t('game.flowTrend') }}</div></template
        >
        <ArtBarChart
          height="230px"
          :data="hourlySeries"
          :x-axis-data="hourLabels"
          :colors="hourlyChartColors"
          :stack="true"
          bar-width="32%"
          :show-legend="true"
          legend-position="top"
        />
      </ElCard>
      <ElCard class="dashboard-card" shadow="never">
        <template #header
          ><div class="card-title">{{ $t('game.monthlyVisits') }}</div></template
        >
        <ArtLineChart
          height="230px"
          :data="monthlySeries"
          :x-axis-data="monthLabels"
          :colors="chartColors"
          :show-legend="true"
          legend-position="top"
        />
      </ElCard>
    </section>

    <ElCard class="dashboard-card" shadow="never">
      <template #header>
        <div class="card-title">
          <span>{{ $t('game.todayAccounting') }}</span>
          <ElTag size="small" type="info">{{ data.business_date_label || '-' }}</ElTag>
        </div>
      </template>
      <ElTable :data="data.today" size="small" :empty-text="$t('game.noTransactionsToday')">
        <ElTableColumn prop="currency_code" :label="$t('game.currency')" width="72" />
        <ElTableColumn :label="`${$t('game.player')} / ${$t('game.bet')}`" width="105">
          <template #default="{ row }">{{ row.user_count }} / {{ row.bet_count }}</template>
        </ElTableColumn>
        <ElTableColumn :label="$t('game.betWin')" min-width="175">
          <template #default="{ row }">
            <div>{{ money(row.bet_amount) }} / {{ money(row.win_amount) }}</div>
            <div class="cell-sub">{{ $t('game.rollback') }} {{ money(row.rollback_amount) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="$t('game.ggrBilling')" min-width="165">
          <template #default="{ row }">
            <div :class="{ 'text-danger': Number(row.ggr_amount) < 0 }">{{
              money(row.ggr_amount)
            }}</div>
            <div class="cell-sub">{{ money(row.billable_ggr_amount) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="data.is_super_admin"
          :label="`${$t('game.merchantFee')} / ${$t('game.provider')}`"
          min-width="185"
        >
          <template #default="{ row }">
            <div>{{ money(row.merchant_fee) }} / {{ money(row.upstream_fee) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="data.is_super_admin"
          :label="$t('game.platformProfit')"
          min-width="120"
        >
          <template #default="{ row }">
            <strong :class="Number(row.platform_profit) < 0 ? 'text-danger' : 'text-success'">
              {{ money(row.platform_profit) }}
            </strong>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="`${$t('game.bill')} / ${$t('game.exception')}`" width="105">
          <template #default="{ row }">
            {{ row.bill_count }} /
            <span :class="{ 'text-danger': Number(row.exception_count) > 0 }">{{
              row.exception_count
            }}</span>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <section class="detail-grid">
      <ElCard class="dashboard-card" shadow="never">
        <template #header
          ><div class="card-title">{{ $t('game.gamePlatform') }}</div></template
        >
        <ElTable :data="data.platforms" size="small" height="284">
          <ElTableColumn prop="platform_code" :label="$t('game.platform')" min-width="120" />
          <ElTableColumn :label="$t('game.enabledTotalGames')" width="120">
            <template #default="{ row }">{{ row.enabled_count }} / {{ row.game_count }}</template>
          </ElTableColumn>
          <ElTableColumn prop="last_sync_time" :label="$t('game.lastSync')" min-width="165" />
        </ElTable>
      </ElCard>

      <ElCard class="dashboard-card" shadow="never">
        <template #header>
          <div class="card-title">
            <span>{{ $t('game.merchantCredit') }}</span>
            <span class="card-title__hint">{{ $t('game.creditSortHint') }}</span>
          </div>
        </template>
        <ElTable
          :data="data.credits"
          size="small"
          height="284"
          :empty-text="$t('game.noEnabledCurrency')"
        >
          <ElTableColumn :label="$t('game.merchant')" min-width="150" show-overflow-tooltip>
            <template #default="{ row }">{{ row.merchant?.name }}</template>
          </ElTableColumn>
          <ElTableColumn prop="currency_code" :label="$t('game.currency')" width="66" />
          <ElTableColumn :label="$t('game.available')" min-width="105" align="right">
            <template #default="{ row }">{{ merchantMoney(row.available_amount) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="$t('game.reserved')" min-width="105" align="right">
            <template #default="{ row }">{{ merchantMoney(row.reserved_amount) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="$t('game.payable')" min-width="105" align="right">
            <template #default="{ row }">{{ merchantMoney(row.payable_amount) }}</template>
          </ElTableColumn>
        </ElTable>
      </ElCard>
    </section>
  </div>
</template>

<script setup lang="ts">
  import api from '@/api/game/operations'
  import { useChartOps } from '@/hooks/core/useChart'
  import { useSettingStore } from '@/store/modules/setting'
  import { hexToRgba } from '@/utils/ui'
  import { merchantMoney, money } from '@/utils/game/amount'
  import { mittBus } from '@/utils/sys'
  import { useI18n } from 'vue-i18n'

  const { t } = useI18n()
  const { isDark } = storeToRefs(useSettingStore())
  const chartColors = computed(() => {
    const colors = useChartOps().colors
    return [colors[0], colors[1], isDark.value ? '#B7C0CF' : '#667085']
  })
  const hourlyChartColors = computed(() => [
    ...chartColors.value,
    ...chartColors.value.map((color) => hexToRgba(color, 0.45).rgba)
  ])
  const loading = ref(false)
  const data = reactive<any>({ today: [], credits: [], platforms: [] })
  const hourLabels = Array.from(
    { length: 24 },
    (_, index) => `${String(index).padStart(2, '0')}:00`
  )
  const hourlySeries = computed(() => {
    const today = new Map<number, any>(
      (data.hourly || []).map((item: any) => [Number(item.stat_hour), item])
    )
    const yesterday = new Map<number, any>(
      (data.hourly_yesterday || []).map((item: any) => [Number(item.stat_hour), item])
    )
    const values = (rows: Map<number, any>, field: string, length: number) =>
      Array.from({ length }, (_, hour) => Number(rows.get(hour)?.[field] || 0))
    const metrics = [
      ['active_user_count', t('game.activeUsers')],
      ['bet_count', t('game.betCount')],
      ['converted_bet_amount', t('game.convertedBetAmount')]
    ]
    return [
      ...metrics.map(([field, name]) => ({
        name: `${t('game.today')}${name}`,
        data: values(today, field, 24),
        stack: 'today'
      })),
      ...metrics.map(([field, name]) => ({
        name: `${t('game.yesterday')}${name}`,
        data: values(yesterday, field, 24),
        stack: 'yesterday'
      }))
    ]
  })
  const months = Array.from({ length: 12 }, (_, index) => {
    const date = new Date()
    date.setUTCDate(1)
    date.setUTCMonth(date.getUTCMonth() - 11 + index)
    return date.toISOString().slice(0, 7)
  })
  const monthLabels = months.map((month) => month.slice(2))
  const monthlySeries = computed(() => {
    const rows = new Map<string, any>(
      (data.monthly || []).map((item: any) => [item.stat_month, item])
    )
    return [
      {
        name: t('game.monthlyActiveUsers'),
        data: months.map((month) => Number(rows.get(month)?.active_user_count || 0))
      },
      {
        name: t('game.betCount'),
        data: months.map((month) => Number(rows.get(month)?.bet_count || 0))
      },
      {
        name: t('game.convertedBetAmount'),
        data: months.map((month) => Number(rows.get(month)?.converted_bet_amount || 0))
      }
    ]
  })
  const summary = computed(() => [
    {
      label: t('game.enterpriseMerchant'),
      primary: data.enterprise_count || 0,
      secondary: data.merchant_count || 0,
      detail: t('game.activeSummary', {
        enterprise: data.active_enterprise_count || 0,
        merchant: data.active_merchant_count || 0
      }),
      icon: 'ri:building-2-line'
    },
    {
      label: t('game.playerActive'),
      primary: data.user_count || 0,
      secondary: data.today_user_count || 0,
      detail: t('game.activePlayerHint'),
      icon: 'ri:user-3-line'
    },
    {
      label: t('game.platformGames'),
      primary: data.platform_count || 0,
      secondary: data.game_count || 0,
      detail: t('game.gameResourceCount', { count: data.total_game_count || 0 }),
      icon: 'ri:gamepad-line'
    },
    {
      label: t('game.pendingException'),
      primary: data.unknown_bill_count || 0,
      secondary: data.today_exception_count || 0,
      detail: t('game.pendingExceptionHint'),
      icon: 'ri:alarm-warning-line'
    }
  ])

  const load = async () => {
    loading.value = true
    try {
      Object.assign(data, await api.overview())
    } finally {
      loading.value = false
    }
  }
  onMounted(() => {
    load()
    mittBus.on('gameMerchantChanged', load)
  })
  onUnmounted(() => mittBus.off('gameMerchantChanged', load))
</script>

<style lang="scss" scoped>
  .dashboard-page {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }

  .metric-card {
    min-height: 108px;
    padding: 14px 16px;
    border: 1px solid var(--art-gray-300);
    border-radius: 8px;
    background: var(--default-box-color);
  }

  .metric-card__header,
  .card-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    color: var(--art-gray-700);
    font-size: 13px;
    font-weight: 600;
  }

  .metric-card__header .art-svg-icon {
    color: var(--theme-color);
    font-size: 18px;
  }

  .metric-card__value {
    display: flex;
    align-items: baseline;
    gap: 6px;
    margin-top: 7px;

    strong {
      color: var(--art-gray-900);
      font-size: 24px;
      line-height: 1;
    }

    span {
      color: var(--art-gray-400);
    }
  }

  .metric-card__detail,
  .cell-sub,
  .card-title__hint {
    color: var(--art-gray-500);
    font-size: 12px;
  }

  .metric-card__detail {
    margin-top: 8px;
  }

  .detail-grid {
    display: grid;
    grid-template-columns: minmax(360px, 0.8fr) minmax(560px, 1.2fr);
    gap: 12px;
  }

  .trend-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .dashboard-card {
    border-radius: 8px;

    :deep(.el-card__header) {
      padding: 11px 14px;
    }

    :deep(.el-card__body) {
      padding: 0 14px 12px;
    }
  }

  @media (max-width: 1199px) {
    .metric-grid,
    .detail-grid,
    .trend-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 767px) {
    .metric-grid,
    .detail-grid,
    .trend-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
