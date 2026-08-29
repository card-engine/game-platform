<template>
  <div class="art-full-height">
    <GameSearchBar v-model="filters" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--select">
        <el-form-item :label="$t('game.enterprise')" prop="enterprise_id">
          <el-select
            v-model="filters.enterprise_id"
            clearable
            filterable
            :placeholder="$t('game.enterprise')"
          >
            <el-option
              v-for="item in options.enterprises"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--keyword">
        <el-form-item :label="$t('game.merchant')" prop="name">
          <el-input v-model="filters.name" clearable :placeholder="$t('game.merchantParam')" />
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--status">
        <el-form-item :label="$t('game.status')" prop="status">
          <el-select v-model="filters.status" clearable :placeholder="$t('game.selectStatus')">
            <el-option :label="$t('game.enabled')" :value="1" />
            <el-option :label="$t('game.paused')" :value="2" />
            <el-option :label="$t('game.closed')" :value="3" />
          </el-select>
        </el-form-item>
      </el-col>
    </GameSearchBar>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElButton
            v-permission="'app:game:merchant:save'"
            class="relative !overflow-visible"
            @click="showDialog('add')"
          >
            <ArtSvgIcon icon="ri:add-line" />
            {{ $t('game.newMerchant') }}
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
        <template #merchant="{ row }">
          <div class="font-medium">{{ row.name }}</div>
          <div class="text-xs text-g-500">{{ row.mch_id }}</div>
        </template>
        <template #languages="{ row }">
          <ElSpace wrap
            ><ElTag v-for="lang in row.language_codes" :key="lang" size="small">{{
              lang
            }}</ElTag></ElSpace
          >
        </template>
        <template #credits="{ row }">
          <ElTag v-if="row.billing_mode === 2" type="warning" size="small">{{
            $t('game.tieredMonthly')
          }}</ElTag>
          <div
            v-else
            v-for="credit in row.credits.filter((item: any) => item.status === 1)"
            :key="credit.id"
            class="whitespace-nowrap text-xs leading-6"
          >
            {{ credit.currency_code }} ·
            <template v-if="credit.settlement_enabled">
              {{ $t('game.positiveGgr') }} {{ percent(credit.rate_value) }}
            </template>
            <span v-else class="text-g-500">{{ $t('game.freeUse') }}</span>
          </div>
          <span
            v-if="row.billing_mode === 1 && !row.credits.some((item: any) => item.status === 1)"
            class="text-g-500"
            >{{ $t('game.notConfigured') }}</span
          >
        </template>
        <template #wallet="{ row }">
          <ElTag type="success" size="small">{{
            row.wallet_mode === 1 ? $t('game.seamlessWallet') : $t('game.transferWallet')
          }}</ElTag>
        </template>
        <template #status="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : row.status === 2 ? 'warning' : 'info'">{{
            merchantStatus[row.status] || $t('game.closed')
          }}</ElTag>
        </template>
        <template #operation="{ row }">
          <ElSpace wrap>
            <ElButton
              v-permission="'app:game:merchant:secret'"
              link
              type="primary"
              @click="showSecret(row)"
              >{{ $t('game.secret') }}</ElButton
            >
            <ElButton link @click="openCredits(row)">{{ $t('game.billingSettings') }}</ElButton>
            <ElButton v-permission="'app:game:merchant:grant'" link @click="openGrants(row)">{{
              $t('game.gameGrant')
            }}</ElButton>
            <ElButton
              v-permission="'app:game:merchant:update'"
              link
              @click="showDialog('edit', row)"
              >{{ $t('game.edit') }}</ElButton
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
      @success="refreshData"
    />
    <CreditDialog
      v-model="creditVisible"
      :merchant="current"
      :role="options.role"
      @success="refreshData"
    />
    <GrantDialog v-model="grantVisible" :merchant="current" :brands="options.brands" />

    <ElDialog
      v-model="secretVisible"
      :title="$t('game.merchantSecret')"
      width="min(560px, 92vw)"
      destroy-on-close
    >
      <ElAlert type="warning" :closable="false" :title="$t('game.secretSafety')" class="mb-4" />
      <ElForm label-width="80px">
        <ElFormItem label="MCH ID"
          ><ElInput :model-value="secret.mch_id" readonly
            ><template #append
              ><ElButton
                :icon="CopyDocument"
                :title="$t('game.copy')"
                @click="copy(secret.mch_id)" /></template></ElInput
        ></ElFormItem>
        <ElFormItem label="Secret"
          ><ElInput
            :model-value="secret.secret"
            :type="showSecretValue ? 'text' : 'password'"
            readonly
            ><template #suffix
              ><ElButton
                text
                :icon="showSecretValue ? Hide : View"
                :title="showSecretValue ? $t('game.hide') : $t('game.show')"
                @click="showSecretValue = !showSecretValue" /></template
            ><template #append
              ><ElButton
                :icon="CopyDocument"
                :title="$t('game.copy')"
                @click="copy(secret.secret)" /></template></ElInput
        ></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :icon="RefreshRight" @click="resetSecret">{{ $t('game.resetSecret') }}</ElButton>
        <ElButton type="primary" @click="secretVisible = false">{{ $t('game.done') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { CopyDocument, Hide, RefreshRight, View } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import { useSaiAdmin } from '@/composables/useSaiAdmin'
  import api from '@/api/game/merchant'
  import EditDialog from './modules/edit-dialog.vue'
  import CreditDialog from './modules/credit-dialog.vue'
  import GrantDialog from './modules/grant-dialog.vue'
  import SuperBadge from '@/components/business/super-badge.vue'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const merchantStatus = computed<Record<number, string>>(() => ({
    0: t('game.pending'),
    1: t('game.enabled'),
    2: t('game.paused'),
    3: t('game.closed')
  }))
  const percent = (value?: string) => `${Number(value || 0) * 100}%`
  const filters = reactive<{
    enterprise_id: number | undefined
    name: string
    status: number | ''
  }>({ enterprise_id: undefined, name: '', status: '' })
  const options = reactive<any>({ enterprises: [], merchants: [], brands: [] })
  const search = () => {
    Object.assign(searchParams, filters)
    getData()
  }
  const resetSearch = () => {
    Object.assign(filters, { enterprise_id: undefined, name: '', status: '' })
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
        { prop: 'merchant', label: t('game.merchantParam'), minWidth: 180, useSlot: true },
        { prop: 'enterprise.name', label: t('game.enterprise'), minWidth: 150 },
        { prop: 'languages', label: t('game.language'), minWidth: 130, useSlot: true },
        { prop: 'timezone', label: t('game.timezone'), minWidth: 130 },
        { prop: 'wallet', label: t('game.walletMode'), width: 105, useSlot: true },
        { prop: 'credits', label: t('game.billable'), minWidth: 170, useSlot: true },
        {
          prop: 'callback_url',
          label: t('game.callbackUrl'),
          minWidth: 220,
          showOverflowTooltip: true
        },
        { prop: 'status', label: t('game.status'), width: 90, useSlot: true },
        { prop: 'operation', label: t('game.operation'), width: 255, fixed: 'right', useSlot: true }
      ]
    }
  })
  const { dialogType, dialogVisible, dialogData, showDialog } = useSaiAdmin()
  const current = ref<any>()
  const creditVisible = ref(false)
  const grantVisible = ref(false)
  const secretVisible = ref(false)
  const showSecretValue = ref(false)
  const secretMerchantId = ref(0)
  const secret = reactive({ mch_id: '', secret: '' })
  const openCredits = (row: any) => {
    current.value = row
    creditVisible.value = true
  }
  const openGrants = (row: any) => {
    current.value = row
    grantVisible.value = true
  }
  const showSecret = async (row: any) => {
    Object.assign(secret, await api.secret(row.id))
    secretMerchantId.value = row.id
    showSecretValue.value = false
    secretVisible.value = true
  }
  const resetSecret = async () => {
    try {
      await ElMessageBox.confirm(t('game.resetSecretConfirm'), t('game.resetSecret'), {
        type: 'warning'
      })
    } catch {
      return
    }
    Object.assign(secret, await api.resetSecret(secretMerchantId.value))
    showSecretValue.value = false
    ElMessage.success(t('game.secretReset'))
  }
  const copy = async (value: string) => {
    await navigator.clipboard.writeText(value)
    ElMessage.success(t('game.copied'))
  }
  onMounted(async () => Object.assign(options, await api.options()))
  watch(locale, () => resetColumns?.())
</script>
