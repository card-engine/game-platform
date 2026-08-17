<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <ElTabs v-model="tab" class="bills-tabs">
        <ElTabPane :label="$t('game.playerBills')" name="player">
          <GameSearchBar
            v-model="playerFilters"
            :show-expand="false"
            @search="searchPlayers"
            @reset="resetPlayers"
          >
            <el-col class="game-search-item game-search-item--date">
              <el-form-item :label="$t('game.date')">
                <el-date-picker
                  v-model="playerDateRange"
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
                  v-model="playerFilters.merchant_ids"
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
                <el-select
                  v-model="playerFilters.status"
                  clearable
                  :placeholder="$t('game.selectStatus')"
                >
                  <el-option :label="$t('game.processing')" :value="1" />
                  <el-option :label="$t('game.success')" :value="2" />
                  <el-option :label="$t('game.failed')" :value="3" />
                  <el-option :label="$t('game.unknown')" :value="4" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col class="game-search-item game-search-item--keyword">
              <el-form-item :label="$t('game.keyword')" prop="keyword">
                <el-input
                  v-model="playerFilters.keyword"
                  clearable
                  :placeholder="$t('game.bill')"
                />
              </el-form-item>
            </el-col>
          </GameSearchBar>
          <ArtTable
            class="compact-table"
            size="small"
            row-key="bill_no"
            :loading="playerLoading"
            :data="playerData"
            :columns="playerColumns"
            :pagination="playerPagination"
            :show-table-header="false"
            @pagination:size-change="playerSize"
            @pagination:current-change="playerPage"
          >
            <template #bill="{ row }"
              ><div class="font-medium">{{ row.bill_no }}</div
              ><div class="text-xs text-g-500">{{ row.bet_no }}</div></template
            >
            <template #subject="{ row }"
              ><div>{{ row.nickname || row.merchant_user_id }}</div
              ><div class="text-xs text-g-500"
                >{{ row.game_name }} · {{ row.merchant_name }}</div
              ></template
            >
            <template #type="{ row }"
              ><ElTag size="small">{{ billTypes[row.type] || row.type }}</ElTag></template
            >
            <template #amount="{ row }">{{ money(row.amount) }}</template>
            <template #status="{ row }"
              ><ElTag :type="billStatusType[row.status]">{{
                billStatuses[row.status] || row.status
              }}</ElTag></template
            >
            <template #detail="{ row }"
              ><ElButton link @click="showData(row)">{{ $t('game.view') }}</ElButton></template
            >
          </ArtTable>
        </ElTabPane>
        <ElTabPane :label="$t('game.merchantBills')" name="merchant">
          <GameSearchBar
            v-model="merchantFilters"
            :show-expand="false"
            @search="searchMerchants"
            @reset="resetMerchants"
          >
            <el-col class="game-search-item game-search-item--date">
              <el-form-item :label="$t('game.date')">
                <el-date-picker
                  v-model="merchantDateRange"
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
                  v-model="merchantFilters.merchant_ids"
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
            <el-col class="game-search-item game-search-item--keyword">
              <el-form-item :label="$t('game.keyword')" prop="keyword">
                <el-input
                  v-model="merchantFilters.keyword"
                  clearable
                  :placeholder="$t('game.bill')"
                />
              </el-form-item>
            </el-col>
          </GameSearchBar>
          <ArtTable
            class="compact-table"
            size="small"
            row-key="id"
            :loading="merchantLoading"
            :data="merchantData"
            :columns="merchantColumns"
            :pagination="merchantPagination"
            :show-table-header="false"
            @pagination:size-change="merchantSize"
            @pagination:current-change="merchantPage"
          >
            <template #direction="{ row }"
              ><ElTag :type="row.direction === 1 ? 'success' : 'warning'">{{
                row.direction === 1 ? $t('game.increase') : $t('game.deduct')
              }}</ElTag></template
            >
            <template #source="{ row }"
              ><div>{{ sourceNames[row.source] || row.source }}</div
              ><div class="text-xs text-g-500">{{ row.source_no }}</div></template
            >
            <template #merchantAmount="{ row }">{{ merchantMoney(row.amount) }}</template>
            <template #merchantBefore="{ row }">{{ merchantMoney(row.before_amount) }}</template>
            <template #merchantAfter="{ row }">{{ merchantMoney(row.after_amount) }}</template>
          </ArtTable>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <ElDrawer v-model="drawer" :title="$t('game.billDetail')" size="min(620px, 92vw)">
      <pre class="whitespace-pre-wrap break-all rounded bg-g-100 p-4 text-xs">{{
        JSON.stringify(current?.data || {}, null, 2)
      }}</pre>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import merchantApi from '@/api/game/merchant'
  import api from '@/api/game/operations'
  import { merchantMoney, money } from '@/utils/game/amount'
  import { mittBus } from '@/utils/sys'
  import { currentMonthRange, recentMonthRange } from '@/utils/game/date'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const tab = ref('player')
  const merchants = ref<any[]>([])
  const playerDateRange = ref(currentMonthRange())
  const playerFilters = reactive<any>({
    merchant_ids: [],
    status: '',
    keyword: ''
  })
  const merchantFilters = reactive<any>({ merchant_ids: [], keyword: '' })
  const merchantDateRange = ref(recentMonthRange())
  const current = ref<any>()
  const drawer = ref(false)
  const billTypes = computed<Record<number, string>>(() => ({
    1: t('game.debit'),
    2: t('game.credit'),
    3: t('game.betRollback'),
    4: t('game.winRollback')
  }))
  const billStatuses = computed<Record<number, string>>(() => ({
    0: t('game.pending'),
    1: t('game.processing'),
    2: t('game.success'),
    3: t('game.failed'),
    4: t('game.unknown')
  }))
  const billStatusType: Record<number, 'primary' | 'success' | 'warning' | 'danger' | 'info'> = {
    0: 'info',
    1: 'warning',
    2: 'success',
    3: 'danger',
    4: 'warning'
  }
  const sourceNames = computed<Record<string, string>>(() => ({
    manual: t('game.manualAdjustment'),
    bet: t('game.betSettlement'),
    bet_adjustment: t('game.postSettlementCharge')
  }))
  const showData = (row: any) => {
    current.value = row
    drawer.value = true
  }
  const {
    columns: playerColumns,
    data: playerData,
    loading: playerLoading,
    getData: getPlayers,
    searchParams: playerParams,
    resetSearchParams: resetPlayerParams,
    pagination: playerPagination,
    handleSizeChange: playerSize,
    handleCurrentChange: playerPage,
    resetColumns: resetPlayerColumns
  } = useTable({
    core: {
      apiFn: api.bills,
      apiParams: { date_start: playerDateRange.value[0], date_end: playerDateRange.value[1] },
      columnsFactory: () => [
        {
          prop: 'bill',
          label: `${t('game.bill')} / ${t('game.bet')}`,
          minWidth: 205,
          useSlot: true
        },
        {
          prop: 'subject',
          label: `${t('game.player')} / ${t('game.game')}`,
          minWidth: 200,
          useSlot: true
        },
        { prop: 'type', label: t('game.type'), width: 95, useSlot: true },
        { prop: 'amount', label: t('game.amount'), width: 120, useSlot: true },
        { prop: 'currency_code', label: t('game.currency'), width: 75 },
        { prop: 'source', label: t('game.source'), width: 100 },
        { prop: 'received_time', label: t('game.receivedAt'), width: 190 },
        { prop: 'status', label: t('game.status'), width: 105, useSlot: true },
        { prop: 'detail', label: '', width: 70, fixed: 'right', useSlot: true }
      ]
    }
  })
  const {
    columns: merchantColumns,
    data: merchantData,
    loading: merchantLoading,
    getData: getMerchants,
    searchParams: merchantParams,
    resetSearchParams: resetMerchantParams,
    pagination: merchantPagination,
    handleSizeChange: merchantSize,
    handleCurrentChange: merchantPage,
    resetColumns: resetMerchantColumns
  } = useTable({
    core: {
      apiFn: api.merchantBills,
      apiParams: {
        date_start: merchantDateRange.value[0],
        date_end: merchantDateRange.value[1]
      },
      columnsFactory: () => [
        { prop: 'bill_no', label: t('game.bill'), minWidth: 190 },
        { prop: 'merchant_name', label: t('game.merchantParam'), minWidth: 150 },
        { prop: 'currency_code', label: t('game.currency'), width: 80 },
        { prop: 'direction', label: t('game.type'), width: 85, useSlot: true },
        { prop: 'merchantAmount', label: t('game.amount'), width: 120, useSlot: true },
        { prop: 'merchantBefore', label: t('game.before'), width: 120, useSlot: true },
        { prop: 'merchantAfter', label: t('game.after'), width: 120, useSlot: true },
        { prop: 'source', label: t('game.source'), minWidth: 160, useSlot: true },
        { prop: 'remark', label: t('game.remark'), minWidth: 140 },
        { prop: 'create_time', label: t('game.time'), width: 190 }
      ]
    }
  })
  const searchPlayers = () => {
    Object.assign(playerParams, playerFilters, {
      date_start: playerDateRange.value[0],
      date_end: playerDateRange.value[1]
    })
    getPlayers()
  }
  const searchMerchants = () => {
    Object.assign(merchantParams, merchantFilters, {
      date_start: merchantDateRange.value[0],
      date_end: merchantDateRange.value[1]
    })
    getMerchants()
  }
  const resetPlayers = () => {
    Object.assign(playerFilters, {
      merchant_ids: [],
      status: '',
      keyword: ''
    })
    playerDateRange.value = currentMonthRange()
    resetPlayerParams()
  }
  const resetMerchants = () => {
    Object.assign(merchantFilters, { merchant_ids: [], keyword: '' })
    merchantDateRange.value = recentMonthRange()
    resetMerchantParams()
  }
  const reload = () => {
    getPlayers()
    getMerchants()
  }
  onMounted(async () => {
    merchants.value = (await merchantApi.options()).merchants
    mittBus.on('gameMerchantChanged', reload)
  })
  onUnmounted(() => mittBus.off('gameMerchantChanged', reload))
  watch(locale, () => {
    resetPlayerColumns?.()
    resetMerchantColumns?.()
  })
</script>

<style lang="scss" scoped>
  .bills-tabs {
    height: 100%;
    display: flex;
    flex-direction: column;

    :deep(.el-tabs__content) {
      flex: 1;
      min-height: 0;
    }

    :deep(.el-tab-pane) {
      height: 100%;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
  }

  .compact-table {
    --compact-body-font-size: clamp(12px, calc(0.5vh + 8.5px), 13px);
    --compact-body-line-height: clamp(14px, calc(0.5vh + 10.5px), 15px);
  }

  .compact-table :deep(.el-table__cell) {
    padding: clamp(1px, calc(0.5vh - 2px), 2px) 0;
  }

  .compact-table :deep(.el-table__body .cell) {
    font-size: var(--compact-body-font-size);
    line-height: var(--compact-body-line-height);
  }

  .compact-table :deep(.el-table__body .text-xs) {
    font-size: clamp(11px, calc(1vh + 3px), 12px);
    line-height: var(--compact-body-line-height);
  }
</style>
