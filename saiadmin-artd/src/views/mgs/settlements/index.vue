<template>
  <div class="art-full-height">
    <TableSearch v-model="filters" @search="search" @reset="resetSearch">
      <el-col class="mgs-search-select"
        ><el-form-item :label="$t('mgs.month')"
          ><ElDatePicker
            v-model="filters.settlement_month"
            type="month"
            value-format="YYYY-MM"
            clearable /></el-form-item
      ></el-col>
      <el-col class="mgs-search-select"
        ><el-form-item :label="$t('mgs.currency')"
          ><ElInput v-model="filters.currency_code" clearable placeholder="USD" /></el-form-item
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
    </TableSearch>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left
          ><ElSpace wrap
            ><ElDatePicker
              v-model="generateMonth"
              type="month"
              value-format="YYYY-MM"
              class="!w-36"
            /><ElButton
              v-permission="'app:mgs:settlement:update'"
              :loading="generating"
              @click="generate"
              ><template #icon><ArtSvgIcon icon="ri:file-add-line" /></template
              >{{ $t('mgs.generateSettlement') }}</ElButton
            ></ElSpace
          ></template
        >
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
        <template #settlement="{ row }"
          ><div class="font-medium">{{ row.settlement_no }}</div
          ><div class="text-xs text-g-500">{{ row.settlement_month }}</div></template
        >
        <template #volume="{ row }"
          ><div
            >{{ $t('mgs.betAmount') }} {{ money(row.bet_amount) }} · {{ $t('mgs.winAmount') }}
            {{ money(row.win_amount) }}</div
          ><div class="text-xs text-g-500">GGR {{ money(row.ggr_amount) }}</div></template
        >
        <template #fee="{ row }"
          ><div>{{ money(row.platform_fee) }}</div
          ><div class="text-xs text-g-500">{{ percent(row.rate_value) }}</div></template
        >
        <template #net="{ row }">{{ money(row.mgs_net_amount) }}</template>
        <template #status="{ row }"
          ><ElTag :type="statusTypes[row.status]">{{
            statuses[row.status] || row.status
          }}</ElTag></template
        >
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { money } from '@/utils/game/amount'
  import api from '@/api/mgs'
  import TableSearch from '../modules/table-search.vue'

  const { t, locale } = useI18n()
  const previousMonth = () => {
    const date = new Date()
    date.setUTCDate(1)
    date.setUTCMonth(date.getUTCMonth() - 1)
    return date.toISOString().slice(0, 7)
  }
  const statuses = computed<Record<number, string>>(() => ({
    0: t('mgs.pendingConfirmation'),
    1: t('mgs.confirmed'),
    2: t('mgs.paid')
  }))
  const statusTypes: Record<number, 'warning' | 'success' | 'info'> = {
    0: 'warning',
    1: 'success',
    2: 'info'
  }
  const filters = reactive<any>({ settlement_month: '', currency_code: '', status: '' })
  const generateMonth = ref(previousMonth())
  const generating = ref(false)
  const percent = (value: string) => `${Number(value || 0) * 100}%`
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { settlement_month: '', currency_code: '', status: '' })
    resetSearchParams()
  }
  const generate = async () => {
    generating.value = true
    try {
      const result = await api.generateSettlement(generateMonth.value)
      ElMessage.success(t('mgs.generatedCount', { count: result.count }))
      refreshData()
    } finally {
      generating.value = false
    }
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
      apiFn: api.settlements,
      columnsFactory: () => [
        { prop: 'settlement', label: t('mgs.settlement'), minWidth: 210, useSlot: true },
        { prop: 'currency_code', label: t('mgs.currency'), width: 85 },
        { prop: 'volume', label: t('mgs.fundSummary'), minWidth: 270, useSlot: true },
        { prop: 'fee', label: t('mgs.platformFee'), width: 140, useSlot: true },
        { prop: 'net', label: t('mgs.mgsNet'), width: 140, useSlot: true },
        { prop: 'paid_time', label: t('mgs.paidTime'), width: 175 },
        { prop: 'create_time', label: t('mgs.createTime'), width: 175 },
        { prop: 'status', label: t('mgs.status'), width: 100, fixed: 'right', useSlot: true }
      ]
    }
  })
  watch(locale, () => resetColumns?.())
</script>

<style scoped>
  .mgs-search-select {
    flex: 0 0 180px;
    max-width: 180px;
  }
  .mgs-search-select :deep(.el-input),
  .mgs-search-select :deep(.el-select),
  .mgs-search-select :deep(.el-date-editor) {
    width: 100%;
  }
  @media (max-width: 767px) {
    .mgs-search-select {
      flex-basis: 100%;
      max-width: 100%;
    }
  }
</style>
