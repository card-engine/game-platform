<template>
  <div class="art-full-height">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--date">
        <el-form-item :label="$t('game.date')">
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
      <el-col class="game-search-item game-search-item--status">
        <el-form-item :label="$t('game.status')" prop="status">
          <el-select v-model="filters.status" clearable :placeholder="$t('game.selectStatus')">
            <el-option :label="$t('game.pending')" :value="0" />
            <el-option :label="$t('game.betting')" :value="1" />
            <el-option :label="$t('game.settled')" :value="2" />
            <el-option :label="$t('game.exception')" :value="4" />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--keyword">
        <el-form-item :label="$t('game.keyword')" prop="keyword">
          <el-input v-model="filters.keyword" clearable :placeholder="$t('game.bet')" />
        </el-form-item>
      </el-col>
    </GameSearchBar>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
      </ArtTableHeader>
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
          ><ElButton link type="primary" @click="showActions(row)">{{ row.bet_no }}</ElButton
          ><div class="text-xs text-g-500">{{ row.provider_round_id }}</div></template
        >
        <template #player="{ row }"
          ><div>{{ row.nickname || row.merchant_user_id }}</div
          ><div class="text-xs text-g-500">{{ row.merchant_name }}</div></template
        >
        <template #amounts="{ row }"
          ><div
            >{{ $t('game.debit') }} {{ money(row.bet_amount) }} · {{ $t('game.credit') }}
            {{ money(row.win_amount) }}</div
          ><div class="text-xs text-g-500"
            >{{ $t('game.betRollback') }} {{ money(row.bet_rollback_amount) }} ·
            {{ $t('game.winRollback') }} {{ money(row.win_rollback_amount) }}</div
          ></template
        >
        <template #ggr="{ row }"
          ><div :class="Number(row.ggr_amount) < 0 ? 'text-danger' : ''">{{
            money(row.ggr_amount)
          }}</div
          ><div class="text-xs text-g-500">
            {{ $t('game.billable') }} {{ money(row.billable_ggr_amount) }}
          </div></template
        >
        <template #fee="{ row }"
          ><div>{{ money(row.merchant_fee) }}</div
          ><div class="text-xs text-g-500">{{ percent(row.merchant_rate_value) }}</div></template
        >
        <template #status="{ row }"
          ><ElTag :type="row.status === 2 ? 'success' : row.status === 4 ? 'danger' : 'warning'">{{
            betStatuses[row.status]
          }}</ElTag></template
        >
      </ArtTable>
    </ElCard>

    <ElDrawer v-model="drawer" :title="$t('game.betActions')" size="min(680px, 92vw)">
      <ElDescriptions v-if="current" :column="2" border class="mb-4"
        ><ElDescriptionsItem :label="$t('game.betNo')">{{ current.bet_no }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('game.player')">{{
          current.merchant_user_id
        }}</ElDescriptionsItem
        ><ElDescriptionsItem :label="$t('game.game')">{{ current.game_name }}</ElDescriptionsItem
        ><ElDescriptionsItem label="GGR">{{
          money(current.ggr_amount)
        }}</ElDescriptionsItem></ElDescriptions
      >
      <ElTable :data="current?.actions || []"
        ><ElTableColumn prop="time" :label="$t('game.time')" width="190" /><ElTableColumn
          :label="$t('game.actions')"
          width="100"
          ><template #default="{ row }">{{
            actionNames[row.type] || row.type
          }}</template></ElTableColumn
        ><ElTableColumn :label="$t('game.amount')" width="120"
          ><template #default="{ row }">{{ money(row.amount) }}</template></ElTableColumn
        ><ElTableColumn
          prop="source_no"
          :label="$t('game.upstreamTransaction')"
          min-width="160" /><ElTableColumn prop="bill_no" :label="$t('game.bill')" min-width="190"
      /></ElTable>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import merchantApi from '@/api/game/merchant'
  import api from '@/api/game/operations'
  import { money } from '@/utils/game/amount'
  import { mittBus } from '@/utils/sys'
  import { currentMonthRange } from '@/utils/game/date'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const merchants = ref<any[]>([])
  const dateRange = ref(currentMonthRange())
  const filters = reactive<any>({
    merchant_ids: [],
    status: '',
    keyword: ''
  })
  const current = ref<any>()
  const drawer = ref(false)
  const actionNames = computed<Record<string, string>>(() => ({
    debit: t('game.debit'),
    credit: t('game.credit'),
    rollback_debit: t('game.betRollback'),
    rollback_credit: t('game.winRollback')
  }))
  const betStatuses = computed<Record<number, string>>(() => ({
    0: t('game.pending'),
    1: t('game.betting'),
    2: t('game.settled'),
    3: t('game.cancelled'),
    4: t('game.exception')
  }))
  const percent = (value?: string) => `${Number(value || 0) * 100}%`
  const showActions = (row: any) => {
    current.value = row
    drawer.value = true
  }
  const search = () => {
    Object.assign(searchParams, filters, {
      date_start: dateRange.value[0],
      date_end: dateRange.value[1]
    })
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { merchant_ids: [], status: '', keyword: '' })
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
        { prop: 'bet', label: t('game.bet'), minWidth: 210, useSlot: true },
        {
          prop: 'player',
          label: `${t('game.player')} / ${t('game.merchant')}`,
          minWidth: 170,
          useSlot: true
        },
        { prop: 'game_name', label: t('game.game'), minWidth: 150 },
        { prop: 'currency_code', label: t('game.currency'), width: 75 },
        { prop: 'amounts', label: t('game.fundSummary'), minWidth: 230, useSlot: true },
        { prop: 'ggr', label: 'GGR', width: 125, useSlot: true },
        { prop: 'fee', label: t('game.merchantFee'), width: 120, useSlot: true },
        { prop: 'business_date', label: t('game.businessDate'), width: 110 },
        { prop: 'status', label: t('game.status'), width: 90, fixed: 'right', useSlot: true }
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
