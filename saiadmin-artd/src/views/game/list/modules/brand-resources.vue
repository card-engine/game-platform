<template>
  <div class="brand-resources">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--platform">
        <el-form-item :label="$t('game.gamePlatform')" prop="platform_code">
          <el-select
            v-model="filters.platform_code"
            clearable
            :placeholder="$t('game.allPlatforms')"
          >
            <el-option
              v-for="item in platforms"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--status">
        <el-form-item :label="$t('game.mappingStatus')" prop="mapping_status">
          <el-select
            v-model="filters.mapping_status"
            clearable
            :placeholder="$t('game.allStatuses')"
          >
            <el-option :label="$t('game.pendingMapping')" :value="0" />
            <el-option :label="$t('game.autoMapping')" :value="1" />
            <el-option :label="$t('game.manualMapping')" :value="2" />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--keyword">
        <el-form-item :label="$t('game.keyword')" prop="keyword">
          <el-input v-model="filters.keyword" clearable :placeholder="$t('game.brandKeyword')" />
        </el-form-item>
      </el-col>
    </GameSearchBar>

    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
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
        <template #resource="{ row }">
          <div class="flex items-center gap-2">
            <span class="font-medium">{{ row.name }}</span>
            <span class="text-xs text-g-500">{{ row.provider_brand_code }}</span>
          </div>
        </template>
        <template #mapping="{ row }">
          <div v-if="row.unique_brand" class="flex items-center gap-2">
            <span>{{ row.unique_brand.name }}</span>
            <ElTag size="small" :type="row.mapping_status === 1 ? 'success' : 'primary'">
              {{ row.mapping_status === 1 ? $t('game.sameCodeMapping') : $t('game.manualMapping') }}
            </ElTag>
          </div>
          <ElTag v-else type="warning">{{ $t('game.pendingMapping') }}</ElTag>
        </template>
        <template #currency-header>
          <span class="inline-flex items-center">
            {{ $t('game.currencyMode') }}
            <SuperBadge v-if="canManage" inline />
          </span>
        </template>
        <template #currency="{ row }">
          <span
            v-permission="'app:game:list:update'"
            class="relative inline-flex !overflow-visible"
          >
            <ElSwitch
              v-model="row.is_gc"
              :active-value="true"
              :inactive-value="false"
              @change="(value) => setBrandMode(row, Boolean(value))"
            />
          </span>
          <span class="ml-2 text-xs">{{ row.is_gc ? 'SC / GC' : $t('game.singleCurrency') }}</span>
        </template>
        <template #operation="{ row }">
          <ElButton
            v-permission="'app:game:list:update'"
            class="relative !overflow-visible"
            link
            type="primary"
            @click="openMapping(row)"
          >
            {{ row.unique_brand ? $t('game.remap') : $t('game.mapBrand') }}
            <SuperBadge />
          </ElButton>
        </template>
      </ArtTable>
    </ElCard>

    <ElDialog
      v-model="visible"
      :title="`${current?.name || ''} · ${$t('game.brandMapping')}`"
      width="min(560px, 92vw)"
    >
      <ElForm label-width="100px">
        <ElFormItem :label="$t('game.brandResource')">
          {{ current?.name }} · {{ current?.platform_code }} · {{ current?.provider_brand_code }}
        </ElFormItem>
        <ElFormItem :label="$t('game.mappingMethod')">
          <ElRadioGroup v-model="mode">
            <ElRadioButton value="existing">{{ $t('game.selectExistingBrand') }}</ElRadioButton>
            <ElRadioButton value="create">{{ $t('game.createUnifiedBrand') }}</ElRadioButton>
          </ElRadioGroup>
        </ElFormItem>
        <template v-if="mode === 'existing'">
          <ElFormItem :label="$t('game.uniqueBrand')">
            <ElSelect
              v-model="form.unique_brand_id"
              filterable
              class="w-full"
              :placeholder="$t('game.searchUnifiedBrand')"
            >
              <ElOption
                v-for="item in uniqueBrands"
                :key="item.id"
                :label="`${item.name} · ${item.code}`"
                :value="item.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElAlert
            v-if="impact && (impact.merchant_count || impact.game_count)"
            :closable="false"
            type="warning"
            :title="
              $t('game.mappingImpact', {
                merchants: impact.merchant_count,
                games: impact.game_count
              })
            "
          />
        </template>
        <template v-else>
          <ElFormItem :label="$t('game.name')"><ElInput v-model="form.name" /></ElFormItem>
          <ElFormItem label="Code"><ElInput v-model="form.code" /></ElFormItem>
        </template>
      </ElForm>
      <template #footer>
        <ElButton @click="visible = false">{{ $t('game.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="submit">{{
          $t('game.confirmMapping')
        }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import api from '@/api/game/list'
  import SuperBadge from '@/components/business/super-badge.vue'
  import { checkAuth } from '@/utils/tool'

  const { t, locale } = useI18n()
  const canManage = computed(() => checkAuth('app:game:list:update'))

  const platforms = [
    { label: 'WXGAME', value: 'wxgame' },
    { label: 'AceWin', value: 'acewin' },
    { label: 'TADA', value: 'tada' },
    { label: 'GoldenGateX', value: 'goldengatex' }
  ]
  const filters = reactive<any>({ platform_code: '', mapping_status: '', keyword: '' })
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { platform_code: '', mapping_status: '', keyword: '' })
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
      apiFn: api.brands,
      columnsFactory: () => [
        { prop: 'resource', label: t('game.brandResource'), minWidth: 180, useSlot: true },
        { prop: 'platform_code', label: t('game.gamePlatform'), width: 130 },
        { prop: 'mapping', label: t('game.uniqueBrand'), minWidth: 190, useSlot: true },
        { prop: 'games_count', label: t('game.gameCount'), width: 90 },
        {
          prop: 'currency',
          label: t('game.currencyMode'),
          width: 125,
          useSlot: true,
          useHeaderSlot: true
        },
        { prop: 'last_sync_time', label: t('game.lastSync'), width: 170 },
        { prop: 'operation', label: t('game.operation'), width: 100, fixed: 'right', useSlot: true }
      ]
    }
  })
  const visible = ref(false)
  const saving = ref(false)
  const current = ref<any>()
  const mode = ref<'existing' | 'create'>('existing')
  const uniqueBrands = ref<any[]>([])
  const impact = ref<any>()
  const form = reactive<any>({ unique_brand_id: undefined, name: '', code: '' })
  const openMapping = async (row: any) => {
    current.value = row
    mode.value = 'existing'
    impact.value = undefined
    Object.assign(form, {
      unique_brand_id: row.unique_brand_id || undefined,
      name: row.name,
      code: row.provider_brand_code.toLowerCase()
    })
    uniqueBrands.value = (await api.uniqueBrands({ page: 1, limit: 500 })).data
    visible.value = true
  }
  watch(
    () => form.unique_brand_id,
    async (value) => {
      impact.value =
        value && current.value ? await api.brandImpact(current.value.id, value) : undefined
    }
  )
  const submit = async () => {
    if (mode.value === 'existing' && !form.unique_brand_id)
      return ElMessage.warning(t('game.selectUnifiedBrand'))
    if (mode.value === 'create' && (!form.name || !form.code))
      return ElMessage.warning(t('game.enterBrandIdentity'))
    saving.value = true
    try {
      await api.mapBrand({
        brand_id: current.value.id,
        ...(mode.value === 'existing'
          ? { unique_brand_id: form.unique_brand_id }
          : { name: form.name, code: form.code })
      })
      ElMessage.success(t('game.brandCodeQueued'))
      visible.value = false
      refreshData()
    } finally {
      saving.value = false
    }
  }
  const setBrandMode = async (row: any, isGc: boolean) => {
    try {
      await api.brandMode({ id: row.id, is_gc: isGc ? 1 : 0 })
      ElMessage.success(t('game.currencyModeUpdated'))
    } catch {
      row.is_gc = !isGc
    }
  }
  watch(locale, () => resetColumns?.())
</script>

<style lang="scss" scoped>
  .brand-resources {
    height: 100%;
    display: flex;
    flex-direction: column;
    min-height: 0;
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
