<template>
  <div class="art-full-height">
    <TableSearch v-model="filters" @search="search" @reset="resetSearch">
      <el-col class="mgs-search-date"
        ><el-form-item :label="$t('mgs.date')"
          ><ElDatePicker
            v-model="dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            :range-separator="$t('mgs.to')"
            :start-placeholder="$t('mgs.startDate')"
            :end-placeholder="$t('mgs.endDate')" /></el-form-item
      ></el-col>
      <el-col class="mgs-search-select"
        ><el-form-item :label="$t('mgs.currency')"
          ><ElInput v-model="filters.currency_code" clearable placeholder="USD" /></el-form-item
      ></el-col>
      <el-col class="mgs-search-keyword"
        ><el-form-item :label="$t('mgs.game')"
          ><ElInput
            v-model="filters.keyword"
            clearable
            :placeholder="$t('mgs.gameKeyword')" /></el-form-item
      ></el-col>
    </TableSearch>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
      <ArtTable
        row-key="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #activity="{ row }"
          ><div>{{ $t('mgs.activeUsers') }} {{ row.active_user_count }}</div
          ><div class="text-xs text-g-500"
            >{{ $t('mgs.betCount') }} {{ row.bet_count }}</div
          ></template
        >
        <template #volume="{ row }"
          ><div
            >{{ $t('mgs.betAmount') }} {{ money(row.bet_amount) }} · {{ $t('mgs.winAmount') }}
            {{ money(row.win_amount) }}</div
          ><div class="text-xs text-g-500"
            >{{ $t('mgs.rollback') }} {{ money(row.rollback_amount) }}</div
          ></template
        >
        <template #result="{ row }"
          ><div :class="Number(row.ggr_amount) < 0 ? 'text-danger' : ''"
            >GGR {{ money(row.ggr_amount) }}</div
          ><div class="text-xs text-g-500">RTP {{ rtp(row.rtp_value) }}</div></template
        >
        <template #fee="{ row }">{{ money(row.platform_fee) }}</template>
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { money } from '@/utils/game/amount'
  import { recentMonthRange } from '@/utils/game/date'
  import api from '@/api/mgs'
  import TableSearch from '../modules/table-search.vue'

  const { t, locale } = useI18n()
  const dateRange = ref(recentMonthRange())
  const filters = reactive<any>({ currency_code: '', keyword: '' })
  const rtp = (value: string | null) =>
    value == null ? '-' : `${(Number(value) * 100).toFixed(2)}%`
  const search = () => {
    Object.assign(searchParams, filters, {
      date_start: dateRange.value?.[0] || '',
      date_end: dateRange.value?.[1] || ''
    })
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { currency_code: '', keyword: '' })
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
    resetColumns,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: api.reports,
      apiParams: { date_start: dateRange.value[0], date_end: dateRange.value[1] },
      columnsFactory: () => [
        { prop: 'stat_date', label: t('mgs.date'), width: 115 },
        { prop: 'game_name', label: t('mgs.game'), minWidth: 180 },
        { prop: 'currency_code', label: t('mgs.currency'), width: 85 },
        { prop: 'activity', label: t('mgs.activity'), minWidth: 135, useSlot: true },
        { prop: 'volume', label: t('mgs.fundSummary'), minWidth: 275, useSlot: true },
        { prop: 'result', label: t('mgs.result'), width: 150, useSlot: true },
        { prop: 'fee', label: t('mgs.platformFee'), width: 130, useSlot: true },
        { prop: 'update_time', label: t('mgs.updateTime'), width: 175 }
      ]
    }
  })
  watch(locale, () => resetColumns?.())
</script>

<style scoped>
  .mgs-search-date {
    flex: 0 0 330px;
    max-width: 330px;
  }
  .mgs-search-select {
    flex: 0 0 150px;
    max-width: 150px;
  }
  .mgs-search-keyword {
    flex: 0 0 240px;
    max-width: 240px;
  }
  .mgs-search-date :deep(.el-date-editor),
  .mgs-search-select :deep(.el-input),
  .mgs-search-keyword :deep(.el-input) {
    width: 100%;
  }
  @media (max-width: 767px) {
    .mgs-search-date,
    .mgs-search-select,
    .mgs-search-keyword {
      flex-basis: 100%;
      max-width: 100%;
    }
  }
</style>
