<template>
  <div class="art-full-height">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--keyword">
        <el-form-item :label="$t('game.keyword')" prop="keyword">
          <el-input v-model="filters.keyword" clearable :placeholder="$t('game.accountKeyword')" />
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--select">
        <el-form-item :label="$t('game.accountType')" prop="role_code">
          <el-select v-model="filters.role_code" clearable>
            <el-option
              v-for="item in options.roles"
              :key="item.value"
              :label="roleLabel(item.value)"
              :value="item.value"
            />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--select">
        <el-form-item :label="$t('game.enterprise')" prop="enterprise_id">
          <el-select v-model="filters.enterprise_id" clearable filterable>
            <el-option
              v-for="item in options.enterprises"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--status">
        <el-form-item :label="$t('game.status')" prop="status">
          <el-select v-model="filters.status" clearable>
            <el-option :label="$t('game.enabled')" :value="1" />
            <el-option :label="$t('game.disabled')" :value="2" />
          </el-select>
        </el-form-item>
      </el-col>
    </GameSearchBar>

    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElButton
            v-permission="'app:game:platform-admin:save'"
            class="relative !overflow-visible"
            @click="showDialog('add')"
          >
            <ArtSvgIcon icon="ri:add-line" />
            {{ $t('game.newPlatformAccount') }}
            <SuperBadge />
          </ElButton>
        </template>
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
        <template #account="{ row }">
          <div class="font-medium">{{ row.username }}</div>
          <div class="text-xs text-g-500">{{ row.realname || '-' }}</div>
        </template>
        <template #role="{ row }">
          <ElTag
            :type="
              row.role_code === 'game_super_admin'
                ? 'danger'
                : row.role_code === 'enterprise_owner'
                  ? 'primary'
                  : 'info'
            "
          >
            {{ roleLabel(row.role_code) }}
          </ElTag>
          <ElTooltip v-if="row.protected" :content="$t('game.primarySuperAdmin')">
            <ArtSvgIcon class="ml-1 text-g-500" icon="ri:lock-line" />
          </ElTooltip>
        </template>
        <template #merchants="{ row }">
          <ElPopover
            v-if="merchantScope(row).length"
            placement="bottom-start"
            trigger="hover"
            :width="460"
            :show-after="100"
          >
            <template #reference>
              <div class="merchant-summary">
                <span class="merchant-summary__title">{{ scopeTitle(row) }}</span>
                <span class="merchant-summary__meta">{{
                  $t('game.merchantItems', { count: merchantScope(row).length })
                }}</span>
              </div>
            </template>
            <ElScrollbar max-height="360px">
              <ElDescriptions :column="1" border size="small">
                <ElDescriptionsItem
                  v-for="item in merchantScope(row)"
                  :key="item.id"
                  :label="item.enterprise?.name || '-'"
                >
                  <div class="font-medium">{{ item.name }}</div>
                  <div class="text-xs text-g-500">{{ item.mch_id }}</div>
                </ElDescriptionsItem>
              </ElDescriptions>
            </ElScrollbar>
          </ElPopover>
          <span v-else>-</span>
        </template>
        <template #status="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : 'info'">
            {{ row.status === 1 ? $t('game.enabled') : $t('game.disabled') }}
          </ElTag>
        </template>
        <template #time="{ row }">
          <div>{{ row.login_time || '-' }}</div>
          <div class="text-xs text-g-500">{{ row.create_time }}</div>
        </template>
        <template #operation="{ row }">
          <ElSpace>
            <ElButton
              v-permission="'app:game:platform-admin:update'"
              link
              type="primary"
              @click="showDialog('edit', row)"
              >{{ $t('game.edit') }}</ElButton
            >
            <ElButton
              v-permission="'app:game:platform-admin:update'"
              link
              type="primary"
              @click="changePassword(row)"
              >{{ $t('game.changePassword') }}</ElButton
            >
            <ElButton
              v-if="!row.protected && !row.current"
              v-permission="'app:game:platform-admin:delete'"
              link
              type="danger"
              @click="remove(row)"
              >{{ $t('game.delete') }}</ElButton
            >
          </ElSpace>
        </template>
      </ArtTable>
    </ElCard>

    <EditDialog
      v-model="dialogVisible"
      :dialog-type="dialogType"
      :data="dialogData"
      :options="options"
      @success="saved"
    />
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { useSaiAdmin } from '@/composables/useSaiAdmin'
  import api from '@/api/game/platform-admin'
  import EditDialog from './modules/edit-dialog.vue'
  import SuperBadge from '@/components/business/super-badge.vue'

  const { t, locale } = useI18n()
  const filters = reactive<any>({ keyword: '', role_code: '', enterprise_id: '', status: '' })
  const options = reactive<{ roles: any[]; enterprises: any[]; merchants: any[] }>({
    roles: [],
    enterprises: [],
    merchants: []
  })
  const loadOptions = async () => Object.assign(options, await api.options())
  const roleLabel = (code: string) =>
    code === 'game_super_admin'
      ? t('game.platformSuperAdmin')
      : code === 'enterprise_owner'
        ? t('game.enterpriseOwner')
        : t('game.enterpriseStaff')
  const merchantScope = (row: any) =>
    row.role_code === 'game_super_admin'
      ? options.merchants
      : row.role_code === 'enterprise_owner'
        ? options.merchants.filter(
            (item) => Number(item.enterprise_id) === Number(row.enterprise_id)
          )
        : row.merchant_ids
            .map((id: number) => options.merchants.find((item) => item.id === id))
            .filter(Boolean)
  const scopeTitle = (row: any) => {
    const merchants = merchantScope(row)
    if (row.role_code === 'game_super_admin') return t('game.allPlatformMerchants')
    if (row.role_code === 'enterprise_owner') return t('game.allEnterpriseMerchants')
    return (
      merchants
        .slice(0, 2)
        .map((item: any) => item.name)
        .join('，') || '-'
    )
  }
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { keyword: '', role_code: '', enterprise_id: '', status: '' })
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
      apiFn: api.list,
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 60 },
        { prop: 'account', label: t('game.account'), minWidth: 150, useSlot: true },
        { prop: 'role', label: t('game.accountType'), width: 130, useSlot: true },
        { prop: 'enterprise_name', label: t('game.ownerEnterprise'), minWidth: 140 },
        { prop: 'merchants', label: t('game.accessibleMerchants'), minWidth: 190, useSlot: true },
        { prop: 'status', label: t('game.status'), width: 80, useSlot: true },
        { prop: 'time', label: t('game.time'), width: 190, useSlot: true },
        { prop: 'operation', label: t('game.operation'), width: 150, fixed: 'right', useSlot: true }
      ]
    }
  })
  const { dialogType, dialogVisible, dialogData, showDialog } = useSaiAdmin()
  const saved = async () => {
    ElMessage.success(t('game.saveSuccess'))
    await Promise.all([refreshData(), loadOptions()])
  }
  const changePassword = async (row: any) => {
    const { value } = await ElMessageBox.prompt(
      t('game.setPassword', { account: row.username }),
      t('game.changePassword'),
      {
        inputType: 'password',
        inputValidator: (password) => password.length >= 6 || t('game.passwordMinSix')
      }
    )
    await api.password({ id: row.id, password: value })
    ElMessage.success(t('game.passwordUpdated'))
  }
  const remove = async (row: any) => {
    await ElMessageBox.confirm(
      row.role_code === 'enterprise_owner'
        ? t('game.deleteOwnerConfirm', { account: row.username })
        : t('game.deleteAccountConfirm', { account: row.username }),
      t('game.delete'),
      { type: 'warning' }
    )
    await api.delete(row.id)
    ElMessage.success(t('game.deleteSuccess'))
    await Promise.all([refreshData(), loadOptions()])
  }

  onMounted(loadOptions)
  watch(locale, () => resetColumns?.())
</script>

<style scoped>
  .merchant-summary {
    display: flex;
    min-width: 0;
    cursor: pointer;
    flex-direction: column;
    gap: 4px;
    border-radius: 4px;
    background: var(--el-fill-color-light);
    padding: 7px 10px;
  }

  .merchant-summary__title {
    overflow: hidden;
    color: var(--el-text-color-primary);
    font-size: 12px;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .merchant-summary__meta {
    color: var(--el-text-color-placeholder);
    font-size: 12px;
  }
</style>
