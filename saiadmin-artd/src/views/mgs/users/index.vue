<template>
  <div class="art-full-height">
    <TableSearch v-model="filters" @search="search" @reset="resetSearch">
      <el-col class="mgs-search-keyword"
        ><el-form-item :label="$t('mgs.keyword')"
          ><el-input
            v-model="filters.keyword"
            clearable
            :placeholder="$t('mgs.userKeyword')" /></el-form-item
      ></el-col>
      <el-col class="mgs-search-status"
        ><el-form-item :label="$t('mgs.status')"
          ><el-select v-model="filters.status" clearable
            ><el-option :label="$t('mgs.enabled')" :value="1" /><el-option
              :label="$t('mgs.disabled')"
              :value="0" /></el-select></el-form-item
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
        <template #user="{ row }"
          ><div class="font-medium">{{ row.nickname || row.user_no }}</div
          ><div class="text-xs text-g-500">{{ row.user_no }}</div></template
        >
        <template #wallets="{ row }"
          ><div v-for="item in row.wallets || []" :key="item.id" class="leading-6"
            ><span class="mr-2 text-xs text-g-500">{{ item.currency_code }}</span
            >{{ money(item.balance) }}</div
          ><span v-if="!row.wallets?.length">-</span></template
        >
        <template #access="{ row }"
          ><div>{{ row.last_login_time || '-' }}</div
          ><div class="text-xs text-g-500">{{ row.last_ip || '-' }}</div></template
        >
        <template #status="{ row }"
          ><ElTag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? $t('mgs.enabled') : $t('mgs.disabled')
          }}</ElTag></template
        >
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { money } from '@/utils/game/amount'
  import api from '@/api/mgs'
  import TableSearch from '../modules/table-search.vue'

  const { t, locale } = useI18n()
  const filters = reactive<any>({ keyword: '', status: '' })
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { keyword: '', status: '' })
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
      apiFn: api.users,
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 80 },
        { prop: 'user', label: t('mgs.user'), minWidth: 190, useSlot: true },
        { prop: 'wallets', label: t('mgs.walletBalance'), minWidth: 150, useSlot: true },
        { prop: 'language', label: t('mgs.language'), width: 90 },
        { prop: 'access', label: t('mgs.lastLogin'), minWidth: 170, useSlot: true },
        { prop: 'last_launch_time', label: t('mgs.lastLaunch'), width: 170 },
        { prop: 'create_time', label: t('mgs.createTime'), width: 170 },
        { prop: 'status', label: t('mgs.status'), width: 90, fixed: 'right', useSlot: true }
      ]
    }
  })
  watch(locale, () => resetColumns?.())
</script>

<style scoped>
  .mgs-search-keyword {
    flex: 0 0 280px;
    max-width: 280px;
  }
  .mgs-search-status {
    flex: 0 0 170px;
    max-width: 170px;
  }
  .mgs-search-keyword :deep(.el-input),
  .mgs-search-status :deep(.el-select) {
    width: 100%;
  }
  @media (max-width: 767px) {
    .mgs-search-keyword,
    .mgs-search-status {
      flex-basis: 100%;
      max-width: 100%;
    }
  }
</style>
