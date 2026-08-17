<template>
  <div class="art-full-height">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--date">
        <el-form-item :label="$t('game.date')" prop="dateRange">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            :range-separator="$t('game.to')"
            :start-placeholder="$t('game.startDate')"
            :end-placeholder="$t('game.endDate')"
          />
        </el-form-item>
      </el-col>
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
              :label="item.name"
              :value="item.id"
            />
          </el-select>
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
        <template #game="{ row }"
          ><div>{{ row.game_name }}</div
          ><div class="text-xs text-g-500"
            >{{ row.brand_name }} · {{ row.platform_code }}</div
          ></template
        >
        <template #volume="{ row }"
          ><div
            >{{ $t('game.debit') }} {{ money(row.bet_amount) }} · {{ $t('game.credit') }}
            {{ money(row.win_amount) }}</div
          ><div class="text-xs text-g-500"
            >{{ $t('game.rollback') }} {{ money(row.rollback_amount) }}</div
          ></template
        >
        <template #ggr="{ row }"
          ><div :class="Number(row.ggr_amount) < 0 ? 'text-danger' : ''"
            >{{ $t('game.original') }} {{ money(row.ggr_amount) }}</div
          ><div class="text-xs text-g-500"
            >{{ $t('game.billable') }} {{ money(row.billable_ggr_amount) }}</div
          ></template
        >
        <template #rtp="{ row }">{{ Number(row.rtp).toFixed(2) }}%</template>
        <template #fee="{ row }"
          ><div>{{ $t('game.merchant') }} {{ money(row.merchant_fee) }}</div
          ><div class="text-xs text-g-500"
            >{{ $t('game.provider') }} {{ money(row.upstream_fee) }}</div
          ></template
        >
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import merchantApi from '@/api/game/merchant'
  import api from '@/api/game/operations'
  import { money } from '@/utils/game/amount'
  import { mittBus } from '@/utils/sys'
  import { recentMonthRange } from '@/utils/game/date'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const merchants = ref<any[]>([])
  const filters = reactive<any>({ merchant_ids: [] })
  const dateRange = ref(recentMonthRange())
  const search = () => {
    Object.assign(searchParams, filters, {
      date_start: dateRange.value?.[0] || '',
      date_end: dateRange.value?.[1] || ''
    })
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { merchant_ids: [] })
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
        { prop: 'business_date', label: t('game.businessDate'), width: 110 },
        { prop: 'merchant_name', label: t('game.merchantParam'), minWidth: 150 },
        { prop: 'game', label: t('game.game'), minWidth: 190, useSlot: true },
        { prop: 'currency_code', label: t('game.currency'), width: 75 },
        { prop: 'user_count', label: t('game.users'), width: 75 },
        { prop: 'bet_count', label: t('game.bets'), width: 75 },
        { prop: 'bill_count', label: t('game.bills'), width: 75 },
        { prop: 'volume', label: t('game.fundSummary'), minWidth: 245, useSlot: true },
        { prop: 'rtp', label: 'RTP', width: 85, align: 'right', useSlot: true },
        { prop: 'ggr', label: 'GGR', width: 145, useSlot: true },
        { prop: 'fee', label: t('game.fee'), width: 145, useSlot: true },
        { prop: 'exception_count', label: t('game.exception'), width: 75 }
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
