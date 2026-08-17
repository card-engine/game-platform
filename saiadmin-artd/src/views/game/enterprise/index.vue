<template>
  <div class="art-full-height">
    <GameSearchBar v-model="searchForm" :show-expand="false" @search="search" @reset="resetSearch">
      <el-col class="game-search-item game-search-item--keyword">
        <el-form-item :label="$t('game.enterpriseName')" prop="name">
          <el-input v-model="searchForm.name" :placeholder="$t('game.enterpriseName')" clearable />
        </el-form-item>
      </el-col>
      <el-col class="game-search-item game-search-item--status">
        <el-form-item :label="$t('game.status')" prop="status">
          <el-select v-model="searchForm.status" clearable :placeholder="$t('game.selectStatus')">
            <el-option :label="$t('game.enabled')" :value="1" />
            <el-option :label="$t('game.disabled')" :value="0" />
          </el-select>
        </el-form-item>
      </el-col>
    </GameSearchBar>
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElButton
            v-permission="'app:game:enterprise:save'"
            class="relative !overflow-visible"
            @click="showDialog('add')"
          >
            <ArtSvgIcon icon="ri:add-line" />
            {{ $t('game.newEnterprise') }}
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
        <template #status="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status === 1 ? $t('game.enabled') : $t('game.disabled')
          }}</ElTag>
        </template>
        <template #operation="{ row }">
          <ElSpace>
            <ElButton link type="primary" @click="openUsers(row)">{{
              $t('game.account')
            }}</ElButton>
            <ElButton
              v-permission="'app:game:enterprise:update'"
              class="relative !overflow-visible"
              link
              @click="showDialog('edit', row)"
              >{{ $t('game.edit') }} <SuperBadge
            /></ElButton>
          </ElSpace>
        </template>
      </ArtTable>
    </ElCard>

    <EditDialog
      v-model="dialogVisible"
      :dialog-type="dialogType"
      :data="dialogData"
      :timezones="timezones"
      @success="refreshData"
    />
    <UserDialog v-model="userVisible" :enterprise="currentEnterprise" />
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import { useSaiAdmin } from '@/composables/useSaiAdmin'
  import api from '@/api/game/enterprise'
  import EditDialog from './modules/edit-dialog.vue'
  import UserDialog from './modules/user-dialog.vue'
  import merchantApi from '@/api/game/merchant'
  import SuperBadge from '@/components/business/super-badge.vue'
  import { useI18n } from 'vue-i18n'

  const { t, locale } = useI18n()
  const searchForm = reactive<{ name: string; status: number | '' }>({ name: '', status: '' })
  const search = () => {
    Object.assign(searchParams, searchForm)
    getData()
  }
  const resetSearch = () => {
    Object.assign(searchForm, { name: '', status: '' })
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
        { prop: 'id', label: 'ID', width: 70 },
        { prop: 'name', label: t('game.enterpriseName'), minWidth: 180 },
        { prop: 'merchant_limit', label: t('game.merchantLimit'), width: 100 },
        { prop: 'merchants_count', label: t('game.merchantCount'), width: 90 },
        { prop: 'users_count', label: t('game.accountCount'), width: 90 },
        { prop: 'timezone', label: t('game.timezone'), minWidth: 140 },
        { prop: 'default_language', label: t('game.defaultLanguage'), width: 100 },
        { prop: 'status', label: t('game.status'), width: 90, useSlot: true },
        { prop: 'create_time', label: t('game.createdAt'), width: 170 },
        { prop: 'operation', label: t('game.operation'), width: 130, fixed: 'right', useSlot: true }
      ]
    }
  })
  const { dialogType, dialogVisible, dialogData, showDialog } = useSaiAdmin()
  const userVisible = ref(false)
  const timezones = ref<{ value: string; label: string }[]>([
    { value: 'UTC', label: 'UTC+00:00 · UTC' }
  ])
  const currentEnterprise = ref<any>()
  const openUsers = (row: any) => {
    currentEnterprise.value = row
    userVisible.value = true
  }
  onMounted(async () => (timezones.value = (await merchantApi.options()).timezones))
  watch(locale, () => resetColumns?.())
</script>
