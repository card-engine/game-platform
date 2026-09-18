<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useTable } from '@/hooks/core/useTable'
  import { useSaiAdmin } from '@/composables/useSaiAdmin'
  import api from '@/api/mgs/recharges'
  import { displayAmount } from '@/utils/game/amount'
  import TableSearch from './modules/table-search.vue'
  import EditDialog from './modules/edit-dialog.vue'
  const { t, locale } = useI18n()
  const filters = ref({ keyword: '', status: '', currency_code: '' })
  const { dialogVisible, dialogData, showDialog } = useSaiAdmin()
  const detailVisible = ref(false)
  const detailData = ref<Record<string, unknown>>()
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
      apiFn: api.transfers,
      columnsFactory: () => [
        {
          prop: 'transaction_id',
          label: t('mgsRecharge.transaction'),
          minWidth: 210,
          useSlot: true
        },
        { prop: 'recharge_id', label: t('mgsRecharge.rechargeId'), width: 110 },
        { prop: 'amount', label: t('mgsRecharge.payAmount'), minWidth: 150, useSlot: true },
        { prop: 'status', label: t('mgs.status'), width: 120, useSlot: true },
        { prop: 'remark', label: t('mgsRecharge.reason'), minWidth: 220 },
        { prop: 'block_time', label: t('mgsRecharge.blockTime'), width: 180 },
        { prop: 'operation', label: t('mgsRecharge.review'), width: 100, useSlot: true }
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
    detailData.value = await api.transfer(id)
    detailVisible.value = true
  }
  async function review(id: string) {
    showDialog('edit', await api.transfer(id))
  }
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
        <template #transaction_id="{ row }"
          ><ElButton link type="primary" @click="detail(row.id)"
            >{{ row.transaction_id.slice(0, 20) }}…</ElButton
          ></template
        >
        <template #amount="{ row }"
          >{{ displayAmount(row.amount) }} {{ row.currency_code }}</template
        >
        <template #status="{ row }">{{ t(`mgsRecharge.${row.status}`) }}</template>
        <template #operation="{ row }">
          <ElButton
            v-if="['review', 'ignored'].includes(row.status)"
            v-permission="'app:mgs:recharge:review'"
            link
            @click="review(row.id)"
            >{{ t('mgsRecharge.review') }}</ElButton
          >
          <ElButton
            v-if="row.status === 'review'"
            v-permission="'app:mgs:recharge:credit'"
            link
            @click="review(row.id)"
            >{{ t('mgsRecharge.credit') }}</ElButton
          >
        </template>
      </ArtTable>
    </ElCard>
    <EditDialog v-model="dialogVisible" :data="dialogData" @success="refreshData" />
    <ElDialog v-model="detailVisible" :title="t('mgsRecharge.detail')" width="min(90vw, 800px)">
      <pre class="whitespace-pre-wrap break-all">{{ JSON.stringify(detailData, null, 2) }}</pre>
    </ElDialog>
  </div>
</template>
