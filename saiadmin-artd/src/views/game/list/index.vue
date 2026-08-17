<template>
  <div class="art-full-height">
    <ElTabs v-model="tab" class="game-list-tabs">
      <ElTabPane :label="$t('game.gameResources')" name="games">
        <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
          <el-col class="game-search-item game-search-item--select">
            <el-form-item :label="$t('game.brand')" prop="brand_id">
              <el-select
                v-model="filters.brand_id"
                clearable
                filterable
                :placeholder="$t('game.brand')"
              >
                <el-option
                  v-for="item in brands"
                  :key="item.id"
                  :label="item.name"
                  :value="item.id"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col class="game-search-item game-search-item--select">
            <el-form-item :label="$t('game.uniqueBrand')" prop="unique_brand_id">
              <el-select
                v-model="filters.unique_brand_id"
                clearable
                filterable
                :placeholder="$t('game.uniqueBrand')"
              >
                <el-option
                  v-for="item in uniqueBrands"
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
                <el-option :label="$t('game.enabled')" :value="1" />
                <el-option :label="$t('game.disabled')" :value="0" />
                <el-option :label="$t('game.upstreamOffline')" :value="3" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col class="game-search-item game-search-item--keyword">
            <el-form-item :label="$t('game.keyword')" prop="keyword">
              <el-input v-model="filters.keyword" clearable :placeholder="$t('game.game')" />
            </el-form-item>
          </el-col>
        </GameSearchBar>
        <ElCard class="art-table-card" shadow="never">
          <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
            <template #left>
              <div class="platform-toolbar">
                <div ref="platformSwitcher" class="platform-toolbar__viewport">
                  <ElRadioGroup
                    v-model="filters.platform_code"
                    class="platform-toolbar__switcher"
                    size="small"
                    @change="changePlatform"
                  >
                    <ElRadioButton v-for="item in platforms" :key="item.value" :value="item.value">
                      {{ item.label }}
                    </ElRadioButton>
                  </ElRadioGroup>
                </div>
                <ElTag class="platform-brand-count" size="small" type="info">
                  {{ $t('game.brandResource') }} {{ brands.length }}
                </ElTag>
                <ElButton
                  v-permission="'app:game:list:sync'"
                  class="platform-sync-button relative !overflow-visible"
                  type="success"
                  size="small"
                  :loading="syncing"
                  @click="sync"
                >
                  <template #icon><ArtSvgIcon icon="ri:refresh-line" /></template>
                  {{ $t('game.syncCurrent') }}
                  <SuperBadge />
                </ElButton>
              </div>
            </template>
          </ArtTableHeader>
          <ArtTable
            class="compact-table"
            size="small"
            row-key="id"
            :loading="loading"
            :data="data"
            :columns="columns"
            :pagination="pagination"
            @pagination:size-change="handleSizeChange"
            @pagination:current-change="handleCurrentChange"
          >
            <template #icon="{ row }"
              ><ElImage v-if="row.icon_url" :src="row.icon_url" fit="contain" class="game-icon" lazy
                ><template #error><div class="game-icon bg-g-100" /></template></ElImage
              ><div v-else class="game-icon bg-g-100"
            /></template>
            <template #game="{ row }"
              ><div class="font-medium leading-4">{{ row.name }}</div
              ><div class="text-xs leading-4 text-g-500">{{ row.game_code }}</div></template
            >
            <template #currencies="{ row }"
              ><ElSpace wrap
                ><ElTooltip
                  v-for="item in row.currency_codes"
                  :key="item"
                  :content="$t('game.clickToTrial')"
                >
                  <ElTag
                    class="cursor-pointer"
                    size="small"
                    :disable-transitions="true"
                    @click="trial(row, item)"
                  >
                    {{ item }}
                    <ArtSvgIcon icon="ri:play-mini-fill" class="ml-0.5 inline-block align-[-2px]" />
                  </ElTag> </ElTooltip></ElSpace
            ></template>
            <template #capabilities="{ row }"
              ><ElTag v-if="row.support_demo" size="small" type="info">{{ $t('game.demo') }}</ElTag
              ><ElTag v-if="row.support_rtp" size="small" type="warning">RTP</ElTag
              ><span v-if="!row.support_demo && !row.support_rtp" class="text-g-500"
                >-</span
              ></template
            >
            <template #status="{ row }">
              <template v-if="row.id">
                <span
                  v-if="row.status !== 3"
                  v-permission="'app:game:list:update'"
                  class="relative inline-flex !overflow-visible"
                >
                  <ElSwitch
                    v-model="row.status"
                    :active-value="1"
                    :inactive-value="0"
                    @change="(value) => setStatus(row, Number(value))"
                  />
                  <SuperBadge />
                </span>
                <ElTag v-else type="danger">{{ $t('game.upstreamOffline') }}</ElTag>
              </template>
            </template>
          </ArtTable>
        </ElCard>
      </ElTabPane>
      <ElTabPane :label="$t('game.brandResource')" name="brands" lazy><BrandResources /></ElTabPane>
    </ElTabs>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import api from '@/api/game/list'
  import BrandResources from './modules/brand-resources.vue'
  import SuperBadge from '@/components/business/super-badge.vue'
  import { useGameStore } from '@/store/modules/game'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const gameStore = useGameStore()
  const tab = ref('games')
  const platforms = [
    { label: 'WXGAME', value: 'wxgame' },
    { label: 'AceWin', value: 'acewin' },
    { label: 'TADA', value: 'tada' },
    { label: 'GoldenGateX', value: 'goldengatex' }
  ]
  const filters = reactive<any>({
    platform_code: 'wxgame',
    brand_id: undefined,
    unique_brand_id: undefined,
    status: '',
    keyword: ''
  })
  const brands = ref<any[]>([])
  const uniqueBrands = ref<any[]>([])
  const syncing = ref(false)
  const platformSwitcher = ref<HTMLElement>()
  const loadBrands = async () => {
    const [resources, unique] = await Promise.all([
      api.brands({ platform_code: filters.platform_code, page: 1, limit: 500 }),
      api.uniqueBrands({ page: 1, limit: 500 })
    ])
    brands.value = resources.data
    uniqueBrands.value = unique.data
  }
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, {
      brand_id: undefined,
      unique_brand_id: undefined,
      status: '',
      keyword: ''
    })
    Object.assign(searchParams, filters)
    getData()
  }
  const changePlatform = async () => {
    filters.brand_id = undefined
    await nextTick()
    platformSwitcher.value
      ?.querySelector('.el-radio-button.is-active')
      ?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' })
    await loadBrands()
    search()
  }
  const sync = async () => {
    syncing.value = true
    try {
      await api.sync(filters.platform_code)
      ElMessage.success(t('game.syncQueued'))
    } finally {
      syncing.value = false
    }
  }
  const trial = async (game: any, currency: string) => {
    if (!gameStore.merchantId) return ElMessage.warning(t('game.selectMerchantFirst'))
    const page = window.open('about:blank', '_blank')
    try {
      const data = await api.trial({
        game_id: game.id,
        merchant_id: gameStore.merchantId,
        currency
      })
      if (page) page.location.href = data.game_url
      else ElMessage.warning(t('game.popupBlocked'))
    } catch (error) {
      page?.close()
      throw error
    }
  }
  const setStatus = async (row: any, status: number) => {
    try {
      await api.status([row.id], status)
      ElMessage.success(t('game.statusUpdated'))
    } catch (error) {
      row.status = status === 1 ? 0 : 1
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
    handleSizeChange,
    handleCurrentChange,
    resetColumns
  } = useTable({
    core: {
      apiFn: api.list,
      apiParams: { platform_code: 'wxgame' },
      columnsFactory: () => [
        { prop: 'icon', label: '', width: 64, useSlot: true },
        { prop: 'game', label: t('game.game'), minWidth: 210, useSlot: true },
        { prop: 'brand.unique_brand.name', label: t('game.uniqueBrand'), minWidth: 140 },
        { prop: 'brand.name', label: t('game.brandResource'), minWidth: 140 },
        {
          prop: 'provider_game_code',
          label: t('game.upstreamCode'),
          minWidth: 150,
          showOverflowTooltip: true
        },
        { prop: 'currencies', label: t('game.currency'), minWidth: 130, useSlot: true },
        { prop: 'capabilities', label: t('game.capability'), width: 105, useSlot: true },
        { prop: 'last_sync_time', label: t('game.lastSync'), width: 170 },
        { prop: 'status', label: t('game.status'), width: 105, fixed: 'right', useSlot: true }
      ]
    }
  })
  onMounted(loadBrands)
  watch(locale, () => resetColumns?.())
</script>

<style lang="scss" scoped>
  .game-list-tabs {
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

  .platform-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
  }

  .platform-toolbar__viewport {
    width: 400px;
    max-width: 100%;
    overflow-x: auto;
    scrollbar-width: none;

    &::-webkit-scrollbar {
      display: none;
    }
  }

  .platform-toolbar__switcher {
    display: flex;
    width: max-content;

    :deep(.el-radio-button__inner) {
      min-width: 78px;
      padding: 6px 12px;
      border-color: var(--art-gray-300);
    }
  }

  .platform-brand-count,
  .platform-sync-button {
    flex: none;
  }

  .platform-brand-count {
    height: var(--el-component-size-small);
    padding: 0 11px;
    border-radius: var(--el-border-radius-base);
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

  .game-icon {
    width: clamp(24px, calc(1vh + 18px), 27px);
    height: clamp(24px, calc(1vh + 18px), 27px);
  }

  @media (max-width: 767px) {
    .platform-toolbar {
      width: 100%;
      flex-wrap: wrap;
    }

    .platform-toolbar__switcher {
      max-width: none;
    }

    .platform-toolbar__viewport {
      width: 100%;
    }
  }
</style>
