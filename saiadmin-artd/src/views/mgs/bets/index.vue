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
      <el-col class="mgs-search-status"
        ><el-form-item :label="$t('mgs.status')"
          ><ElSelect v-model="filters.status" clearable
            ><ElOption
              v-for="(label, value) in statuses"
              :key="value"
              :label="label"
              :value="Number(value)" /></ElSelect></el-form-item
      ></el-col>
      <el-col class="mgs-search-keyword"
        ><el-form-item :label="$t('mgs.keyword')"
          ><ElInput
            v-model="filters.keyword"
            clearable
            :placeholder="$t('mgs.betKeyword')" /></el-form-item
      ></el-col>
    </TableSearch>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
      <ArtTable
        row-key="bet_no"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #bet="{ row }"
          ><ElButton link type="primary" @click="showDetail(row)">{{ row.bet_no }}</ElButton
          ><div class="text-xs text-g-500">{{ row.platform_round_id }}</div></template
        >
        <template #user="{ row }"
          ><div>{{ row.nickname || row.user_no }}</div
          ><div class="text-xs text-g-500">{{ row.user_no }}</div></template
        >
        <template #amounts="{ row }"
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
        <template #fee="{ row }"
          ><div>{{ money(row.platform_fee) }}</div
          ><div class="text-xs text-g-500">{{ percent(row.rate_value) }}</div></template
        >
        <template #status="{ row }"
          ><ElTag :type="statusTypes[row.status]">{{
            statuses[row.status] || row.status
          }}</ElTag></template
        >
      </ArtTable>
    </ElCard>
    <ElDrawer v-model="drawer" :title="$t('mgs.betDetail')" size="min(720px, 94vw)">
      <ElDescriptions v-if="current" :column="2" border class="mb-4"
        ><ElDescriptionsItem :label="$t('mgs.betNo')">{{ current.bet_no }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.user')">{{ current.user_no }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.game')">{{ current.game_name }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.currency')">{{
          current.currency_code
        }}</ElDescriptionsItem></ElDescriptions
      >
      <ArtTable
        row-key="transaction_id"
        :data="current?.actions || []"
        :columns="detailColumns"
        :show-table-header="true"
      >
        <template #amount="{ row }">{{ money(row.amount) }}</template>
      </ArtTable>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { money } from '@/utils/game/amount'
  import { currentMonthRange } from '@/utils/game/date'
  import api from '@/api/mgs'
  import TableSearch from '../modules/table-search.vue'

  const { t, locale } = useI18n()
  const statuses = computed<Record<number, string>>(() => ({
    1: t('mgs.inProgress'),
    2: t('mgs.settled'),
    3: t('mgs.cancelled'),
    4: t('mgs.exception')
  }))
  const statusTypes: Record<number, 'success' | 'warning' | 'danger' | 'info'> = {
    1: 'warning',
    2: 'success',
    3: 'info',
    4: 'danger'
  }
  const dateRange = ref(currentMonthRange())
  const filters = reactive<any>({ status: '', keyword: '' })
  const current = ref<any>()
  const drawer = ref(false)
  const percent = (value: string) => `${Number(value || 0) * 100}%`
  const rtp = (value: string | null) =>
    value == null ? '-' : `${(Number(value) * 100).toFixed(2)}%`
  const showDetail = (row: any) => {
    current.value = row
    drawer.value = true
  }
  const detailColumns = computed(() => [
    { prop: 'time', label: t('mgs.time'), width: 180 },
    { prop: 'type', label: t('mgs.type'), width: 120 },
    { prop: 'amount', label: t('mgs.amount'), width: 130, useSlot: true },
    { prop: 'transaction_id', label: t('mgs.transactionNo'), minWidth: 180 }
  ])
  const search = () => {
    Object.assign(searchParams, filters, {
      date_start: dateRange.value?.[0] || '',
      date_end: dateRange.value?.[1] || ''
    })
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { status: '', keyword: '' })
    dateRange.value = currentMonthRange()
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
      apiFn: api.bets,
      apiParams: { date_start: dateRange.value[0], date_end: dateRange.value[1] },
      columnsFactory: () => [
        { prop: 'bet', label: t('mgs.bet'), minWidth: 210, useSlot: true },
        { prop: 'user', label: t('mgs.user'), minWidth: 150, useSlot: true },
        { prop: 'game_name', label: t('mgs.game'), minWidth: 150 },
        { prop: 'currency_code', label: t('mgs.currency'), width: 80 },
        { prop: 'amounts', label: t('mgs.fundSummary'), minWidth: 260, useSlot: true },
        { prop: 'result', label: t('mgs.result'), width: 130, useSlot: true },
        { prop: 'fee', label: t('mgs.platformFee'), width: 120, useSlot: true },
        { prop: 'business_date', label: t('mgs.businessDate'), width: 110 },
        { prop: 'status', label: t('mgs.status'), width: 90, fixed: 'right', useSlot: true }
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
  .mgs-search-status {
    flex: 0 0 170px;
    max-width: 170px;
  }
  .mgs-search-keyword {
    flex: 0 0 260px;
    max-width: 260px;
  }
  .mgs-search-date :deep(.el-date-editor),
  .mgs-search-status :deep(.el-select),
  .mgs-search-keyword :deep(.el-input) {
    width: 100%;
  }
  @media (max-width: 767px) {
    .mgs-search-date,
    .mgs-search-status,
    .mgs-search-keyword {
      flex-basis: 100%;
      max-width: 100%;
    }
  }
</style>
