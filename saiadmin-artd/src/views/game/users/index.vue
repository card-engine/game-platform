<template>
  <div class="art-full-height">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--select">
        <el-form-item :label="$t('game.merchant')" prop="merchant_ids">
          <el-select
            v-model="filters.merchant_ids"
            multiple
            collapse-tags
            :max-collapse-tags="2"
            clearable
            filterable
            :placeholder="$t('game.selectMerchant')"
          >
            <el-option
              v-for="item in merchants"
              :key="item.id"
              :label="`${item.name} · ${item.mch_id}`"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--status">
        <el-form-item :label="$t('game.status')" prop="status">
          <el-select v-model="filters.status" clearable :placeholder="$t('game.selectStatus')">
            <el-option :label="$t('game.normal')" :value="1" />
            <el-option :label="$t('game.frozen')" :value="2" />
            <el-option :label="$t('game.closed')" :value="3" />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--keyword">
        <el-form-item :label="$t('game.keyword')" prop="keyword">
          <el-input v-model="filters.keyword" clearable :placeholder="$t('game.player')" />
        </el-form-item>
      </el-col>
    </GameSearchBar>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
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
        <template #player="{ row }"
          ><div class="font-medium">{{ row.nickname || row.merchant_user_id }}</div
          ><div class="text-xs text-g-500">{{ row.merchant_user_id }}</div></template
        >
        <template #merchant="{ row }"
          ><div>{{ row.merchant?.name }}</div
          ><div class="text-xs text-g-500">{{ row.merchant?.mch_id }}</div></template
        >
        <template #status="{ row }"
          ><ElTag :type="row.status === 1 ? 'success' : row.status === 2 ? 'warning' : 'info'">{{
            userStatuses[row.status]
          }}</ElTag></template
        >
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import merchantApi from '@/api/game/merchant'
  import api from '@/api/game/operations'
  import { mittBus } from '@/utils/sys'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const merchants = ref<any[]>([])
  const userStatuses = computed<Record<number, string>>(() => ({
    1: t('game.normal'),
    2: t('game.frozen'),
    3: t('game.closed')
  }))
  const filters = reactive<any>({ merchant_ids: [], status: '', keyword: '' })
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { merchant_ids: [], status: '', keyword: '' })
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
        { prop: 'id', label: 'ID', width: 90 },
        { prop: 'player', label: t('game.player'), minWidth: 190, useSlot: true },
        { prop: 'merchant', label: t('game.merchantParam'), minWidth: 180, useSlot: true },
        { prop: 'last_ip', label: t('game.lastIp'), minWidth: 130 },
        { prop: 'last_launch_time', label: t('game.lastLaunch'), width: 170 },
        { prop: 'create_time', label: t('game.firstSync'), width: 170 },
        { prop: 'status', label: t('game.status'), width: 90, useSlot: true }
      ]
    }
  })
  onMounted(async () => {
    merchants.value = (await merchantApi.options()).merchants
    mittBus.on('gameMerchantChanged', refreshData)
  })
  onUnmounted(() => mittBus.off('gameMerchantChanged', refreshData))
  watch(locale, () => resetColumns?.())
</script>
