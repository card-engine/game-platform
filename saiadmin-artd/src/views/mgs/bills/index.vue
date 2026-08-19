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
        ><el-form-item :label="$t('mgs.type')"
          ><ElSelect v-model="filters.type" clearable
            ><ElOption
              v-for="(label, value) in types"
              :key="value"
              :label="label"
              :value="value" /></ElSelect></el-form-item
      ></el-col>
      <el-col class="mgs-search-select"
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
            :placeholder="$t('mgs.billKeyword')" /></el-form-item
      ></el-col>
    </TableSearch>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
      <ArtTable
        row-key="bill_no"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #bill="{ row }"
          ><ElButton link type="primary" @click="showDetail(row)">{{ row.bill_no }}</ElButton
          ><div class="text-xs text-g-500">{{ row.transaction_id }}</div></template
        >
        <template #user="{ row }"
          ><div>{{ row.nickname || row.user_no }}</div
          ><div class="text-xs text-g-500">{{ row.user_no }}</div></template
        >
        <template #type="{ row }"
          ><ElTag size="small">{{ types[row.type] || row.type }}</ElTag></template
        >
        <template #amount="{ row }"
          ><div :class="row.direction === 1 ? 'text-success' : ''"
            >{{ row.direction === 1 ? '+' : '-' }}{{ money(row.amount) }}
            {{ row.currency_code }}</div
          ><div class="text-xs text-g-500"
            >{{ money(row.before_balance) }} → {{ money(row.after_balance) }}</div
          ></template
        >
        <template #status="{ row }"
          ><ElTag :type="statusTypes[row.status]">{{
            statuses[row.status] || row.status
          }}</ElTag></template
        >
      </ArtTable>
    </ElCard>
    <ElDrawer v-model="drawer" :title="$t('mgs.billDetail')" size="min(620px, 94vw)">
      <ElDescriptions v-if="current" :column="1" border
        ><ElDescriptionsItem :label="$t('mgs.billNo')">{{ current.bill_no }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.betNo')">{{
          current.bet_no || '-'
        }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.transactionNo')">{{
          current.transaction_id
        }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.originalTransactionNo')">{{
          current.original_transaction_id || '-'
        }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.game')">{{
          current.game_name || '-'
        }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('mgs.createTime')">{{
          current.create_time
        }}</ElDescriptionsItem></ElDescriptions
      >
      <pre
        v-if="current?.data"
        class="mt-4 whitespace-pre-wrap break-all rounded bg-g-100 p-4 text-xs"
        >{{ JSON.stringify(current.data, null, 2) }}</pre
      >
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
  const types = computed<Record<string, string>>(() => ({
    bet: t('mgs.betDebit'),
    win: t('mgs.winCredit'),
    cancel: t('mgs.betCancel'),
    rollback: t('mgs.winRollback'),
    adjust: t('mgs.adjustment')
  }))
  const statuses = computed<Record<number, string>>(() => ({
    1: t('mgs.processing'),
    2: t('mgs.success'),
    3: t('mgs.failed'),
    4: t('mgs.unknown')
  }))
  const statusTypes: Record<number, 'success' | 'warning' | 'danger'> = {
    1: 'warning',
    2: 'success',
    3: 'danger',
    4: 'warning'
  }
  const dateRange = ref(currentMonthRange())
  const filters = reactive<any>({ type: '', status: '', keyword: '' })
  const current = ref<any>()
  const drawer = ref(false)
  const showDetail = (row: any) => {
    current.value = row
    drawer.value = true
  }
  const search = () => {
    Object.assign(searchParams, filters, {
      date_start: dateRange.value?.[0] || '',
      date_end: dateRange.value?.[1] || ''
    })
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { type: '', status: '', keyword: '' })
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
      apiFn: api.bills,
      apiParams: { date_start: dateRange.value[0], date_end: dateRange.value[1] },
      columnsFactory: () => [
        { prop: 'bill', label: t('mgs.bill'), minWidth: 215, useSlot: true },
        { prop: 'user', label: t('mgs.user'), minWidth: 150, useSlot: true },
        { prop: 'type', label: t('mgs.type'), width: 110, useSlot: true },
        { prop: 'amount', label: t('mgs.amountBalance'), minWidth: 190, useSlot: true },
        { prop: 'game_name', label: t('mgs.game'), minWidth: 140 },
        { prop: 'bet_no', label: t('mgs.betNo'), minWidth: 190, showOverflowTooltip: true },
        { prop: 'create_time', label: t('mgs.createTime'), width: 175 },
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
  .mgs-search-select {
    flex: 0 0 150px;
    max-width: 150px;
  }
  .mgs-search-keyword {
    flex: 0 0 240px;
    max-width: 240px;
  }
  .mgs-search-date :deep(.el-date-editor),
  .mgs-search-select :deep(.el-select),
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
