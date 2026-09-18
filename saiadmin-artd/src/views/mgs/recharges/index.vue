<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { useSaiAdmin } from '@/composables/useSaiAdmin'
  import api from '@/api/mgs/recharges'
  import TableSearch from './modules/table-search.vue'
  const { t, locale } = useI18n()
  const route = useRoute()
  const filters = ref({
    keyword: typeof route.query.keyword === 'string' ? route.query.keyword : '',
    status: '',
    currency_code: ''
  })
  const { dialogVisible, dialogData, showDialog } = useSaiAdmin()
  const {
    data,
    loading,
    columns,
    columnChecks,
    searchParams,
    getData,
    refreshData,
    pagination,
    handleSizeChange,
    handleCurrentChange,
    resetColumns
  } = useTable({
    core: {
      apiFn: api.list,
      apiParams: filters.value,
      columnsFactory: () => [
        { prop: 'recharge_no', label: t('mgsRecharge.rechargeNo'), minWidth: 240, useSlot: true },
        { prop: 'user_id', label: t('mgs.user'), width: 110 },
        {
          prop: 'recharge_amount',
          label: t('mgsRecharge.creditAmount'),
          minWidth: 160,
          useSlot: true
        },
        { prop: 'pay_amount', label: t('mgsRecharge.payAmount'), minWidth: 160, useSlot: true },
        { prop: 'status', label: t('mgs.status'), width: 110, useSlot: true },
        { prop: 'create_time', label: t('mgs.createTime'), width: 180 }
      ]
    }
  })
  function search() {
    Object.assign(searchParams, filters.value)
    getData()
  }
  function reset() {
    filters.value = { keyword: '', status: '', currency_code: '' }
    search()
  }
  async function detail(id: string) {
    showDialog('view', await api.read(id))
  }
  watch(
    () => route.query.keyword,
    (keyword) => {
      if (route.path !== '/mgs/recharges') return
      filters.value.keyword = typeof keyword === 'string' ? keyword : ''
      search()
    }
  )
  watch(locale, () => resetColumns?.())
</script>

<template>
  <div class="art-full-height">
    <TableSearch v-model="filters" @search="search" @reset="reset" />
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
      <ArtTable
        row-key="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #recharge_no="{ row }"
          ><ElButton link type="primary" @click="detail(row.id)">{{
            row.recharge_no
          }}</ElButton></template
        >
        <template #recharge_amount="{ row }"
          >{{ row.recharge_amount }} {{ row.currency_code }}</template
        >
        <template #pay_amount="{ row }">{{ row.pay_amount }} {{ row.pay_currency_code }}</template>
        <template #status="{ row }">{{ t(`mgsRecharge.${row.status}`) }}</template>
      </ArtTable>
    </ElCard>
    <ElDialog v-model="dialogVisible" :title="t('mgsRecharge.detail')" width="min(90vw, 800px)">
      <pre class="whitespace-pre-wrap break-all">{{ JSON.stringify(dialogData, null, 2) }}</pre>
    </ElDialog>
  </div>
</template>
