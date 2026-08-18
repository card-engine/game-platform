<template>
  <div class="mgs-page" v-loading="loading">
    <ElCard shadow="never" class="mb-4">
      <div class="mgs-head">
        <div>
          <h2>{{ $t('mgs.title') }}</h2>
          <p>{{ $t('mgs.subtitle') }}</p>
        </div>
        <div class="mgs-actions">
          <ElButton
            v-if="active === 'games'"
            v-permission="'app:mgs:game:sync'"
            type="primary"
            :loading="syncing"
            @click="sync"
          >
            {{ $t('mgs.sync') }}
          </ElButton>
          <template v-if="active === 'settlements'">
            <ElDatePicker v-model="settlementMonth" type="month" value-format="YYYY-MM" />
            <ElButton
              v-permission="'app:mgs:settlement:update'"
              type="primary"
              :loading="generating"
              @click="generateSettlement"
            >
              {{ $t('mgs.generateSettlement') }}
            </ElButton>
          </template>
        </div>
      </div>
      <ElTabs v-model="active" @tab-change="load">
        <ElTabPane name="overview" :label="$t('mgs.overview')" />
        <ElTabPane name="games" :label="$t('mgs.games')" />
        <ElTabPane name="users" :label="$t('mgs.users')" />
        <ElTabPane name="bets" :label="$t('mgs.bets')" />
        <ElTabPane name="bills" :label="$t('mgs.bills')" />
        <ElTabPane name="reports" :label="$t('mgs.reports')" />
        <ElTabPane name="settlements" :label="$t('mgs.settlements')" />
      </ElTabs>
    </ElCard>

    <template v-if="active === 'overview'">
      <div class="mgs-metrics">
        <ElCard v-for="item in metrics" :key="item.label" shadow="never">
          <span>{{ item.label }}</span
          ><strong>{{ item.value }}</strong>
        </ElCard>
      </div>
      <ElCard shadow="never" :header="$t('mgs.today')">
        <ElTable :data="overview.daily || []" size="small">
          <ElTableColumn prop="currency_code" :label="$t('mgs.currency')" width="110" />
          <ElTableColumn prop="active_user_count" :label="$t('mgs.activeUsers')" />
          <ElTableColumn prop="bet_count" :label="$t('mgs.betCount')" />
          <ElTableColumn prop="bet_amount" :label="$t('mgs.betAmount')" />
          <ElTableColumn prop="win_amount" :label="$t('mgs.winAmount')" />
          <ElTableColumn prop="ggr_amount" label="GGR" />
          <ElTableColumn prop="platform_fee" :label="$t('mgs.platformFee')" />
        </ElTable>
      </ElCard>
    </template>

    <ElCard v-else shadow="never">
      <template v-if="active === 'games'">
        <ElTable :data="rows" size="small">
          <ElTableColumn prop="name" :label="$t('mgs.game')" min-width="180" />
          <ElTableColumn prop="brand.name" :label="$t('mgs.brand')" min-width="130" />
          <ElTableColumn
            prop="platform_game_code"
            :label="$t('mgs.platformCode')"
            min-width="160"
          />
          <ElTableColumn prop="currency_codes" :label="$t('mgs.currency')" min-width="120">
            <template #default="{ row }">{{ (row.currency_codes || []).join(', ') }}</template>
          </ElTableColumn>
          <ElTableColumn :label="$t('mgs.status')" width="90">
            <template #default="{ row }"
              ><ElSwitch
                v-permission="'app:mgs:game:update'"
                v-model="row.status"
                :active-value="1"
                :inactive-value="0"
                @change="toggleGame(row)"
            /></template>
          </ElTableColumn>
          <ElTableColumn prop="rate_value" :label="$t('mgs.rate')" width="110" />
          <ElTableColumn :label="$t('mgs.operation')" width="90" fixed="right">
            <template #default="{ row }">
              <ElButton
                v-permission="'app:mgs:game:update'"
                link
                type="primary"
                @click="editGame(row)"
              >
                {{ $t('mgs.edit') }}
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
      </template>
      <ElTable v-else :data="rows" size="small">
        <ElTableColumn
          v-if="active === 'users'"
          prop="user_no"
          :label="$t('mgs.userNo')"
          min-width="180"
        />
        <ElTableColumn
          v-if="active === 'users'"
          prop="nickname"
          :label="$t('mgs.nickname')"
          min-width="140"
        />
        <ElTableColumn
          v-if="active === 'bets'"
          prop="bet_no"
          :label="$t('mgs.betNo')"
          min-width="190"
        />
        <ElTableColumn
          v-if="active === 'bills'"
          prop="bill_no"
          :label="$t('mgs.billNo')"
          min-width="190"
        />
        <ElTableColumn
          v-if="active === 'reports'"
          prop="stat_date"
          :label="$t('mgs.date')"
          width="120"
        />
        <ElTableColumn
          v-if="active === 'settlements'"
          prop="settlement_no"
          :label="$t('mgs.settlementNo')"
          min-width="190"
        />
        <ElTableColumn prop="currency_code" :label="$t('mgs.currency')" width="100" />
        <ElTableColumn prop="amount" :label="$t('mgs.amount')" width="140" />
        <ElTableColumn prop="bet_amount" :label="$t('mgs.betAmount')" width="140" />
        <ElTableColumn prop="win_amount" :label="$t('mgs.winAmount')" width="140" />
        <ElTableColumn prop="ggr_amount" label="GGR" width="140" />
        <ElTableColumn v-if="active === 'reports'" prop="rtp_value" label="RTP" width="100" />
      </ElTable>
      <ElPagination
        v-if="active !== 'reports' && active !== 'settlements'"
        class="mt-4"
        layout="total, prev, pager, next"
        :total="total"
        :page-size="20"
        @current-change="changePage"
      />
    </ElCard>

    <ElDialog v-model="dialog" :title="$t('mgs.gameConfig')" width="min(480px, 92vw)">
      <ElForm label-width="110px">
        <ElFormItem :label="$t('mgs.game')"><ElInput v-model="form.name" disabled /></ElFormItem>
        <ElFormItem :label="$t('mgs.sort')"
          ><ElInputNumber v-model="form.sort" :min="0"
        /></ElFormItem>
        <ElFormItem :label="$t('mgs.rate')"><ElInput v-model="form.rate_value" /></ElFormItem>
        <ElFormItem :label="$t('mgs.defaultRtp')">
          <ElSelect v-model="form.default_rtp" clearable>
            <ElOption
              v-for="item in form.rtp_options || []"
              :key="item"
              :label="item"
              :value="item"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="$t('mgs.tags')">
          <ElCheckbox v-model="form.is_hot" :true-value="1" :false-value="0">{{
            $t('mgs.hot')
          }}</ElCheckbox>
          <ElCheckbox v-model="form.is_new" :true-value="1" :false-value="0">{{
            $t('mgs.new')
          }}</ElCheckbox>
        </ElFormItem>
      </ElForm>
      <template #footer
        ><ElButton @click="dialog = false">{{ $t('mgs.cancel') }}</ElButton
        ><ElButton type="primary" :loading="saving" @click="saveGame">{{
          $t('mgs.save')
        }}</ElButton></template
      >
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import api from '@/api/mgs'
  import { useRoute, useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'

  const route = useRoute()
  const router = useRouter()
  const { t } = useI18n()
  const loading = ref(false)
  const syncing = ref(false)
  const generating = ref(false)
  const settlementMonth = ref(previousMonth())
  const saving = ref(false)
  const dialog = ref(false)
  const form = reactive<any>({})
  const active = ref(String(route.path.split('/').pop() || 'overview'))
  const overview = reactive<any>({ daily: [] })
  const rows = ref<any[]>([])
  const total = ref(0)
  const page = ref(1)
  const metrics = computed(() => [
    { label: t('mgs.users'), value: overview.user_count || 0 },
    {
      label: t('mgs.games'),
      value: `${overview.active_game_count || 0} / ${overview.game_count || 0}`
    },
    {
      label: t('mgs.today'),
      value: (overview.daily || []).reduce(
        (sum: number, row: any) => sum + Number(row.bet_count || 0),
        0
      )
    }
  ])

  async function load() {
    loading.value = true
    try {
      if (active.value === 'overview') Object.assign(overview, await api.overview())
      else {
        const result = await api[
          active.value as 'games' | 'users' | 'bets' | 'bills' | 'reports' | 'settlements'
        ]({ page: page.value, limit: 20 })
        rows.value = result.data || []
        total.value = result.total || 0
      }
      if (route.path !== `/mgs/${active.value}`) router.replace(`/mgs/${active.value}`)
    } finally {
      loading.value = false
    }
  }
  async function sync() {
    syncing.value = true
    try {
      await api.sync()
      ElMessage.success(t('mgs.queued'))
    } finally {
      syncing.value = false
    }
  }
  async function toggleGame(row: any) {
    const status = row.status
    try {
      await api.gameStatus({ id: row.id, status })
      ElMessage.success(t('mgs.saved'))
    } catch (error) {
      row.status = status === 1 ? 0 : 1
      throw error
    }
  }
  function editGame(row: any) {
    Object.assign(form, row)
    dialog.value = true
  }
  async function saveGame() {
    saving.value = true
    try {
      await api.gameConfig(form)
      dialog.value = false
      ElMessage.success(t('mgs.saved'))
      load()
    } finally {
      saving.value = false
    }
  }
  async function generateSettlement() {
    generating.value = true
    try {
      const result = await api.generateSettlement(settlementMonth.value)
      ElMessage.success(t('mgs.generated', { count: result.count }))
      load()
    } finally {
      generating.value = false
    }
  }
  function previousMonth() {
    const date = new Date()
    date.setUTCDate(1)
    date.setUTCMonth(date.getUTCMonth() - 1)
    return date.toISOString().slice(0, 7)
  }
  function changePage(value: number) {
    page.value = value
    load()
  }
  onMounted(load)
</script>

<style scoped>
  .mgs-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .mgs-head h2 {
    margin: 0;
    font-size: 20px;
  }
  .mgs-head p {
    margin: 6px 0 0;
    color: var(--el-text-color-secondary);
  }
  .mgs-actions {
    display: flex;
    gap: 8px;
  }
  .mgs-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
  }
  .mgs-metrics span,
  .mgs-metrics strong {
    display: block;
  }
  .mgs-metrics span {
    color: var(--el-text-color-secondary);
  }
  .mgs-metrics strong {
    margin-top: 10px;
    font-size: 24px;
  }
  @media (max-width: 700px) {
    .mgs-metrics {
      grid-template-columns: 1fr;
    }
    .mgs-head {
      align-items: flex-start;
      gap: 12px;
    }
  }
</style>
