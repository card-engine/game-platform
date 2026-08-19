<template>
  <div class="mgs-overview" v-loading="loading">
    <div class="overview-toolbar">
      <div>
        <h2>{{ $t('mgs.overview') }}</h2>
        <p>{{ $t('mgs.overviewHint') }}</p>
      </div>
      <ElSelect v-model="currency" class="w-28" :placeholder="$t('mgs.currency')">
        <ElOption v-for="item in currencies" :key="item" :label="item" :value="item" />
      </ElSelect>
    </div>

    <section class="metric-grid">
      <ElCard v-for="item in metrics" :key="item.label" shadow="never">
        <div class="metric-title"
          ><span>{{ item.label }}</span
          ><ArtSvgIcon :icon="item.icon"
        /></div>
        <strong>{{ item.value }}</strong>
        <p>{{ item.detail }}</p>
      </ElCard>
    </section>

    <section class="chart-grid">
      <ElCard shadow="never">
        <template #header
          ><b>{{ $t('mgs.hourlyTrend') }}</b></template
        >
        <ArtLineChart
          height="250px"
          :data="hourlySeries"
          :x-axis-data="hours"
          :colors="chartColors"
          :show-area-color="true"
          :show-legend="true"
          legend-position="top"
        />
      </ElCard>
      <ElCard shadow="never">
        <template #header
          ><b>{{ $t('mgs.monthlyTrend') }}</b></template
        >
        <ArtBarChart
          height="250px"
          :data="monthlySeries"
          :x-axis-data="months.map((item) => item.slice(2))"
          :colors="chartColors"
          :show-legend="true"
          legend-position="top"
        />
      </ElCard>
    </section>

    <ElCard class="art-table-card" shadow="never">
      <template #header
        ><b>{{ $t('mgs.todayStats') }}</b></template
      >
      <ArtTable
        row-key="currency_code"
        :data="data.daily || []"
        :columns="dailyColumns"
        :show-table-header="false"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useChartOps } from '@/hooks/core/useChart'
  import { useSettingStore } from '@/store/modules/setting'
  import { money } from '@/utils/game/amount'
  import api from '@/api/mgs'

  const { t, locale } = useI18n()
  const { isDark } = storeToRefs(useSettingStore())
  const loading = ref(false)
  const data = reactive<any>({ wallets: [], daily: [], hourly: [], monthly: [] })
  const currency = ref('')
  const currencies = computed(() =>
    Array.from(
      new Set([
        ...data.wallets.map((item: any) => item.currency_code),
        ...data.daily.map((item: any) => item.currency_code),
        ...data.hourly.map((item: any) => item.currency_code),
        ...data.monthly.map((item: any) => item.currency_code)
      ])
    )
  )
  const chartColors = computed(() => {
    const colors = useChartOps().colors
    return [colors[0], colors[1], isDark.value ? '#B7C0CF' : '#667085']
  })
  const hours = Array.from({ length: 24 }, (_, hour) => `${String(hour).padStart(2, '0')}:00`)
  const months = Array.from({ length: 12 }, (_, index) => {
    const date = new Date()
    date.setUTCDate(1)
    date.setUTCMonth(date.getUTCMonth() - 11 + index)
    return date.toISOString().slice(0, 7)
  })
  const today = computed(
    () => data.daily.find((item: any) => item.currency_code === currency.value) || {}
  )
  const wallet = computed(
    () => data.wallets.find((item: any) => item.currency_code === currency.value) || {}
  )
  const metrics = computed(() => [
    {
      label: t('mgs.users'),
      value: data.user_count || 0,
      detail: t('mgs.registeredUsers'),
      icon: 'ri:user-3-line'
    },
    {
      label: t('mgs.games'),
      value: `${data.active_game_count || 0} / ${data.game_count || 0}`,
      detail: t('mgs.enabledTotal'),
      icon: 'ri:gamepad-line'
    },
    {
      label: t('mgs.walletBalance'),
      value: money(wallet.value.balance),
      detail: `${wallet.value.wallet_count || 0} ${t('mgs.wallets')} · ${currency.value || '-'}`,
      icon: 'ri:wallet-3-line'
    },
    {
      label: t('mgs.todayBets'),
      value: today.value.bet_count || 0,
      detail: `${money(today.value.bet_amount)} ${currency.value || ''}`,
      icon: 'ri:file-list-3-line'
    }
  ])
  const hourlySeries = computed(() => {
    const rows = new Map<number, any>(
      data.hourly
        .filter((item: any) => item.currency_code === currency.value)
        .map((item: any) => [Number(item.stat_hour), item])
    )
    return [
      {
        name: t('mgs.activeUsers'),
        data: hours.map((_, hour) => Number(rows.get(hour)?.active_user_count || 0))
      },
      {
        name: t('mgs.betCount'),
        data: hours.map((_, hour) => Number(rows.get(hour)?.bet_count || 0))
      },
      {
        name: t('mgs.betAmount'),
        data: hours.map((_, hour) => Number(rows.get(hour)?.bet_amount || 0))
      }
    ]
  })
  const monthlySeries = computed(() => {
    const rows = new Map<string, any>(
      data.monthly
        .filter((item: any) => item.currency_code === currency.value)
        .map((item: any) => [item.stat_month, item])
    )
    return [
      {
        name: t('mgs.monthlyActiveUsers'),
        data: months.map((month) => Number(rows.get(month)?.active_user_count || 0))
      },
      {
        name: t('mgs.betCount'),
        data: months.map((month) => Number(rows.get(month)?.bet_count || 0))
      },
      {
        name: t('mgs.betAmount'),
        data: months.map((month) => Number(rows.get(month)?.bet_amount || 0))
      }
    ]
  })
  const dailyColumns = computed(() => [
    { prop: 'currency_code', label: t('mgs.currency'), width: 90 },
    { prop: 'active_user_count', label: t('mgs.activeUsers'), width: 100 },
    { prop: 'bet_count', label: t('mgs.betCount'), width: 100 },
    {
      prop: 'bet_amount',
      label: t('mgs.betAmount'),
      minWidth: 130,
      formatter: (row: any) => money(row.bet_amount)
    },
    {
      prop: 'win_amount',
      label: t('mgs.winAmount'),
      minWidth: 130,
      formatter: (row: any) => money(row.win_amount)
    },
    {
      prop: 'ggr_amount',
      label: 'GGR',
      minWidth: 130,
      formatter: (row: any) => money(row.ggr_amount)
    },
    {
      prop: 'platform_fee',
      label: t('mgs.platformFee'),
      minWidth: 130,
      formatter: (row: any) => money(row.platform_fee)
    }
  ])
  const load = async () => {
    loading.value = true
    try {
      Object.assign(data, await api.overview())
      if (!currencies.value.includes(currency.value)) currency.value = currencies.value[0] || ''
    } finally {
      loading.value = false
    }
  }
  onMounted(load)
  watch(locale, load)
</script>

<style scoped lang="scss">
  .mgs-overview {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .overview-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }
  .overview-toolbar h2 {
    margin: 0;
    font-size: 20px;
  }
  .overview-toolbar p {
    margin: 4px 0 0;
    color: var(--el-text-color-secondary);
  }
  .metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .metric-title {
    display: flex;
    justify-content: space-between;
    color: var(--el-text-color-secondary);
  }
  .metric-grid strong {
    display: block;
    margin-top: 10px;
    font-size: 24px;
  }
  .metric-grid p {
    margin: 4px 0 0;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
  .chart-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }
  @media (max-width: 900px) {
    .metric-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .chart-grid {
      grid-template-columns: 1fr;
    }
  }
  @media (max-width: 520px) {
    .metric-grid {
      grid-template-columns: 1fr;
    }
    .overview-toolbar {
      align-items: flex-start;
    }
  }
</style>
