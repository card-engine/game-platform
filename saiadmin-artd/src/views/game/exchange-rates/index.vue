<template>
  <div class="art-full-height">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--date">
        <el-form-item :label="$t('game.date')">
          <el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" />
        </el-form-item>
      </el-col>
    </GameSearchBar>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElButton
            type="primary"
            :loading="syncing"
            class="relative !overflow-visible"
            @click="sync"
          >
            <ArtSvgIcon icon="ri:refresh-line" />
            {{ $t('game.updateTodayRate') }}
            <SuperBadge />
          </ElButton>
        </template>
      </ArtTableHeader>
      <ArtTable
        row-key="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #rates="{ row }">
          <ElSpace wrap>
            <ElTag v-for="code in displayCodes" :key="code" size="small">
              {{ code }} {{ row.rate_json?.[code] ?? '-' }}
            </ElTag>
          </ElSpace>
        </template>
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { recentMonthRange } from '@/utils/game/date'
  import api from '@/api/game/settings'
  import SuperBadge from '@/components/business/super-badge.vue'

  const { t } = useI18n()

  const filters = reactive({})
  const dateRange = ref(recentMonthRange())
  const displayCodes = ref<string[]>(['USD', 'USDT', 'CNY', 'EUR', 'GBP', 'INR', 'PKR'])
  const syncing = ref(false)
  const search = () => {
    Object.assign(searchParams, { date_start: dateRange.value[0], date_end: dateRange.value[1] })
    getData()
  }
  const resetSearch = () => {
    dateRange.value = recentMonthRange()
    resetSearchParams()
  }
  const {
    columns,
    columnChecks,
    data,
    loading,
    getData,
    refreshData,
    searchParams,
    pagination,
    resetSearchParams,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: api.exchangeRates,
      apiParams: { date_start: dateRange.value[0], date_end: dateRange.value[1] },
      columnsFactory: () => [
        { prop: 'rate_date', label: t('game.utcDate'), width: 120 },
        { prop: 'base_currency_code', label: t('game.baseCurrency'), width: 80 },
        { prop: 'rates', label: t('game.displayedRates'), minWidth: 620, useSlot: true },
        { prop: 'source', label: t('game.source'), width: 120 },
        { prop: 'source_update_time', label: t('game.sourceUpdateUtc'), width: 190 }
      ]
    }
  })
  const sync = async () => {
    syncing.value = true
    try {
      await api.syncExchangeRate()
      ElMessage.success(t('game.rateUpdated'))
      refreshData()
    } finally {
      syncing.value = false
    }
  }
  onMounted(async () => {
    const config = (await api.configs()).find((item) => item.code === 'exchange_rate_display_codes')
    if (config?.value?.length) displayCodes.value = config.value
  })
</script>
