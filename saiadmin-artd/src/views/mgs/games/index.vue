<template>
  <div class="art-full-height">
    <TableSearch v-model="filters" @search="search" @reset="resetSearch">
      <el-col class="mgs-search-item mgs-search-keyword"
        ><el-form-item :label="$t('mgs.keyword')"
          ><el-input
            v-model="filters.keyword"
            clearable
            :placeholder="$t('mgs.gameKeyword')" /></el-form-item
      ></el-col>
      <el-col class="mgs-search-item"
        ><el-form-item :label="$t('mgs.status')"
          ><el-select v-model="filters.status" clearable
            ><el-option :label="$t('mgs.enabled')" :value="1" /><el-option
              :label="$t('mgs.disabled')"
              :value="0" /></el-select></el-form-item
      ></el-col>
    </TableSearch>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left
          ><ElButton v-permission="'app:mgs:game:sync'" :loading="syncing" @click="sync"
            ><template #icon><ArtSvgIcon icon="ri:refresh-line" /></template
            >{{ $t('mgs.syncGames') }}</ElButton
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
        <template #game="{ row }"
          ><div class="font-medium">{{ row.name }}</div
          ><div class="text-xs text-g-500">{{ row.platform_game_code }}</div></template
        >
        <template #brand="{ row }"
          ><div>{{ row.brand?.name || '-' }}</div
          ><div class="text-xs text-g-500">{{ row.platform_brand_code }}</div></template
        >
        <template #currencies="{ row }"
          ><ElSpace wrap
            ><ElTag v-for="item in row.currency_codes || []" :key="item" size="small">{{
              item
            }}</ElTag></ElSpace
          ></template
        >
        <template #tags="{ row }"
          ><ElSpace
            ><ElTag v-if="row.is_hot" type="danger" size="small">{{ $t('mgs.hot') }}</ElTag
            ><ElTag v-if="row.is_new" type="success" size="small">{{ $t('mgs.new') }}</ElTag
            ><span v-if="!row.is_hot && !row.is_new">-</span></ElSpace
          ></template
        >
        <template #rate="{ row }">{{ percent(row.rate_value) }}</template>
        <template #status="{ row }"
          ><ElSwitch
            v-permission="'app:mgs:game:update'"
            v-model="row.status"
            :active-value="1"
            :inactive-value="0"
            @change="(value) => updateStatus(row, Number(value))"
        /></template>
        <template #operation="{ row }"
          ><SaButton
            v-permission="'app:mgs:game:update'"
            type="secondary"
            @click="showDialog('edit', row)"
        /></template>
      </ArtTable>
    </ElCard>
    <EditDialog
      v-model="dialogVisible"
      :dialog-type="dialogType"
      :data="dialogData"
      @success="refreshData"
    />
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { useSaiAdmin } from '@/composables/useSaiAdmin'
  import api from '@/api/mgs'
  import TableSearch from '../modules/table-search.vue'
  import EditDialog from './modules/edit-dialog.vue'

  const { t, locale } = useI18n()
  const filters = reactive<any>({ keyword: '', status: '' })
  const syncing = ref(false)
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { keyword: '', status: '' })
    resetSearchParams()
  }
  const percent = (value: string) => `${Number(value || 0) * 100}%`
  const sync = async () => {
    syncing.value = true
    try {
      await api.sync()
      ElMessage.success(t('mgs.syncQueued'))
    } finally {
      syncing.value = false
    }
  }
  const updateStatus = async (row: any, status: number) => {
    try {
      await api.gameStatus({ id: row.id, status })
      ElMessage.success(t('mgs.saved'))
    } catch (error) {
      row.status = status ? 0 : 1
      throw error
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
      apiFn: api.games,
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 70 },
        { prop: 'game', label: t('mgs.game'), minWidth: 190, useSlot: true },
        { prop: 'brand', label: t('mgs.brand'), minWidth: 150, useSlot: true },
        { prop: 'currencies', label: t('mgs.currency'), minWidth: 120, useSlot: true },
        { prop: 'game_type', label: t('mgs.gameType'), width: 110 },
        { prop: 'tags', label: t('mgs.tags'), width: 110, useSlot: true },
        { prop: 'default_rtp', label: 'RTP', width: 90 },
        { prop: 'rate', label: t('mgs.rate'), width: 90, useSlot: true },
        { prop: 'last_sync_time', label: t('mgs.lastSync'), width: 170 },
        { prop: 'status', label: t('mgs.status'), width: 80, useSlot: true },
        { prop: 'operation', label: t('mgs.operation'), width: 80, fixed: 'right', useSlot: true }
      ]
    }
  })
  const { dialogType, dialogVisible, dialogData, showDialog } = useSaiAdmin()
  watch(locale, () => resetColumns?.())
</script>

<style scoped>
  .mgs-search-item {
    flex: 0 0 170px;
    max-width: 170px;
  }
  .mgs-search-keyword {
    flex-basis: 280px;
    max-width: 280px;
  }
  .mgs-search-item :deep(.el-input),
  .mgs-search-item :deep(.el-select) {
    width: 100%;
  }
  @media (max-width: 767px) {
    .mgs-search-item,
    .mgs-search-keyword {
      flex-basis: 100%;
      max-width: 100%;
    }
  }
</style>
