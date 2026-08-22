<template>
  <ElDialog
    v-model="visible"
    :title="`${merchant?.name || ''} · ${$t('game.billingSettings')}`"
    width="min(1040px, 96vw)"
    destroy-on-close
  >
    <div v-loading="loading">
      <div
        class="mb-5 flex flex-wrap items-center justify-between gap-3 border-b border-g-200 pb-4"
      >
        <div>
          <div class="mb-1 flex items-center gap-2 font-medium">
            <span class="inline-flex items-center">
              {{ $t('game.billingPlan') }}
              <SuperBadge v-if="canEdit" inline />
            </span>
          </div>
          <div class="text-xs text-g-500">{{ $t('game.billingPlanHint') }}</div>
        </div>
        <ElRadioGroup v-model="billingMode" :disabled="!canEdit">
          <ElRadioButton :value="1">{{ $t('game.positiveGgr') }}</ElRadioButton>
          <ElRadioButton :value="2">{{ $t('game.tieredMonthly') }}</ElRadioButton>
        </ElRadioGroup>
      </div>

      <template v-if="billingMode === 1">
        <div class="mb-3 flex items-center gap-1 font-medium">
          {{ $t('game.currencyRateCredit') }}
          <ElTooltip :content="$t('game.positiveGgrHelp')">
            <ElIcon class="cursor-help text-g-500"><QuestionFilled /></ElIcon>
          </ElTooltip>
        </div>
        <ElTable :data="credits" border>
          <ElTableColumn prop="currency_code" :label="$t('game.currency')" width="90" />
          <ElTableColumn :label="$t('game.enabled')" width="80">
            <template #default="{ row }">
              <ElSwitch
                v-model="row.status"
                :active-value="1"
                :inactive-value="0"
                :disabled="!canEdit"
              />
            </template>
          </ElTableColumn>
          <ElTableColumn
            prop="matched_games"
            :label="$t('game.applicableGames')"
            width="95"
            align="center"
          />
          <ElTableColumn :label="$t('game.ggrRate')" min-width="150">
            <template #default="{ row }">
              <span
                v-if="!row.settlement_enabled && row.currency_code === 'GC'"
                class="text-g-500"
                >{{ $t('game.freeUse') }}</span
              >
              <ElInput v-else v-model="row.rate_percent" :disabled="!canEdit" class="!w-32">
                <template #suffix>%</template>
              </ElInput>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="$t('game.availableCredit')" min-width="120">
            <template #default="{ row }">{{ merchantMoney(row.available_amount) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="$t('game.reservedCredit')" min-width="120">
            <template #header>
              <span class="inline-flex items-center gap-1">
                {{ $t('game.reservedCredit') }}
                <ElTooltip :content="$t('game.reservedCreditHelp')">
                  <ElIcon class="cursor-help text-g-500"><QuestionFilled /></ElIcon>
                </ElTooltip>
              </span>
            </template>
            <template #default="{ row }">{{ merchantMoney(row.reserved_amount) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="$t('game.payableFee')" min-width="120">
            <template #default="{ row }">{{ merchantMoney(row.payable_amount) }}</template>
          </ElTableColumn>
          <ElTableColumn v-if="canEdit" :label="$t('game.operation')" width="100" align="center">
            <template #default="{ row }">
              <ElButton
                v-if="row.id && row.settlement_enabled"
                link
                type="primary"
                @click="adjusting = row"
              >
                {{ $t('game.adjustCredit') }}
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
      </template>

      <template v-else>
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_340px]">
          <section>
            <div class="mb-3 font-medium">{{ $t('game.monthlyRules') }}</div>
            <ElForm label-width="92px">
              <ElFormItem :label="$t('game.tierMetric')">
                <ElRadioGroup v-model="monthlyMetric" :disabled="!canEdit">
                  <ElRadioButton :value="1">{{ $t('game.monthlyBetAmount') }}</ElRadioButton>
                  <ElRadioButton :value="2">{{ $t('game.monthlyBetCount') }}</ElRadioButton>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="$t('game.minimumMonthly')">
                <ElInputNumber
                  v-model="monthlyMinFee"
                  :min="0.01"
                  :precision="2"
                  :disabled="!canEdit"
                  class="!w-48"
                />
                <span class="ml-2 text-sm">U</span>
                <span class="ml-3 text-xs text-g-500">{{ $t('game.firstMonthCharge') }}</span>
              </ElFormItem>
            </ElForm>
            <ElTable :data="tiers" border>
              <ElTableColumn
                :label="
                  monthlyMetric === 1 ? $t('game.monthlyBetAmount') : $t('game.monthlyBetCount')
                "
                min-width="160"
              >
                <template #default="{ row }">
                  <ElInputNumber
                    v-model="row.min"
                    :min="0"
                    :precision="monthlyMetric === 1 ? 2 : 0"
                    :disabled="!canEdit"
                    class="!w-full"
                  />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="$t('game.nextMonthFee')" min-width="160">
                <template #default="{ row }">
                  <ElInputNumber
                    v-model="row.fee"
                    :min="0.01"
                    :precision="2"
                    :disabled="!canEdit"
                    class="!w-full"
                  />
                </template>
              </ElTableColumn>
              <ElTableColumn v-if="canEdit" width="70" align="center">
                <template #default="{ $index }">
                  <ElButton
                    link
                    type="danger"
                    :icon="Delete"
                    :title="$t('game.delete')"
                    @click="tiers.splice($index, 1)"
                  />
                </template>
              </ElTableColumn>
            </ElTable>
            <ElButton
              v-if="canEdit"
              class="mt-2"
              :icon="Plus"
              @click="tiers.push({ min: 0, fee: monthlyMinFee })"
            >
              {{ $t('game.addTier') }}
            </ElButton>
          </section>

          <section class="border-l-0 border-g-200 lg:border-l lg:pl-5">
            <div class="mb-3 font-medium">{{ $t('game.businessData') }}</div>
            <div class="grid grid-cols-3 gap-2 text-center lg:grid-cols-1">
              <div class="border border-g-200 px-3 py-2 lg:text-left">
                <div class="text-xs text-g-500">{{ $t('game.monthlyBetAmount') }}</div>
                <div class="mt-1 font-medium">{{ merchantMoney(stats?.bet_amount) }}</div>
              </div>
              <div class="border border-g-200 px-3 py-2 lg:text-left">
                <div class="text-xs text-g-500">{{ $t('game.monthlyBetCount') }}</div>
                <div class="mt-1 font-medium">{{ stats?.bet_count || 0 }}</div>
              </div>
              <div class="border border-g-200 px-3 py-2 lg:text-left">
                <div class="text-xs text-g-500">{{ $t('game.activePlayers') }}</div>
                <div class="mt-1 font-medium">{{ stats?.active_user_count || 0 }}</div>
              </div>
            </div>
            <div
              class="mt-3 flex items-center justify-between border border-primary/30 bg-primary/5 px-3 py-2"
            >
              <span class="text-sm">{{ $t('game.nextMonthEstimate') }}</span>
              <strong>{{ merchantMoney(nextFee) }} U</strong>
            </div>

            <template v-if="bill">
              <div class="mb-2 mt-5 font-medium">{{ $t('game.recentBill') }}</div>
              <div class="space-y-2 border border-g-200 p-3 text-sm">
                <div class="flex justify-between"
                  ><span class="text-g-500">{{ $t('game.billingPeriod') }}</span
                  ><span>{{ bill.billing_month }}</span></div
                >
                <div class="flex justify-between"
                  ><span class="text-g-500">{{ $t('game.billingAmount') }}</span
                  ><strong>{{ merchantMoney(bill.amount) }} U</strong></div
                >
                <div class="flex justify-between">
                  <span class="text-g-500">{{ $t('game.billingStatus') }}</span>
                  <ElTag
                    :type="
                      bill.status === 1
                        ? 'success'
                        : bill.status === 2
                          ? 'danger'
                          : bill.status === 3
                            ? 'info'
                            : 'warning'
                    "
                    size="small"
                  >
                    {{ billStatus[bill.status] }}
                  </ElTag>
                </div>
                <ElButton
                  v-if="canEdit && bill.status !== 1"
                  class="w-full"
                  type="success"
                  plain
                  @click="markPaid"
                >
                  {{ $t('game.markPaid') }}
                </ElButton>
              </div>
            </template>
          </section>
        </div>

        <div class="mt-5 border-t border-g-200 pt-4">
          <div class="mb-2 font-medium">{{ $t('game.openCurrencies') }}</div>
          <ElSpace wrap>
            <ElCheckbox
              v-for="item in credits"
              :key="item.id"
              v-model="item.status"
              :true-value="1"
              :false-value="0"
              :disabled="!canEdit"
            >
              {{ item.currency_code }}（{{ item.matched_games }} {{ $t('game.gameCount') }}）
            </ElCheckbox>
          </ElSpace>
        </div>
      </template>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{
        canEdit ? $t('game.cancel') : $t('game.closed')
      }}</ElButton>
      <ElButton v-if="canEdit" type="primary" :loading="saving" @click="save">{{
        $t('game.save')
      }}</ElButton>
    </template>

    <ElDialog
      v-model="adjustVisible"
      :title="$t('game.adjustCreditTitle')"
      width="min(460px, 90vw)"
      append-to-body
    >
      <ElForm label-width="80px">
        <ElFormItem :label="$t('game.currency')">{{ adjusting?.currency_code }}</ElFormItem>
        <ElFormItem :label="$t('game.direction')">
          <ElRadioGroup v-model="adjust.direction">
            <ElRadioButton :value="1">{{ $t('game.increase') }}</ElRadioButton>
            <ElRadioButton :value="2">{{ $t('game.deduct') }}</ElRadioButton>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="$t('game.amount')"><ElInput v-model="adjust.amount" /></ElFormItem>
        <ElFormItem :label="$t('game.remark')"
          ><ElInput v-model="adjust.remark" type="textarea"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="adjustVisible = false">{{ $t('game.cancel') }}</ElButton>
        <ElButton type="primary" @click="submitAdjust">{{ $t('game.confirm') }}</ElButton>
      </template>
    </ElDialog>
  </ElDialog>
</template>

<script setup lang="ts">
  import { Delete, Plus, QuestionFilled } from '@element-plus/icons-vue'
  import { ElMessage } from 'element-plus'
  import api from '@/api/game/merchant'
  import { merchantMoney, percentToRate, rateToPercent } from '@/utils/game/amount'
  import SuperBadge from '@/components/business/super-badge.vue'
  import { useI18n } from 'vue-i18n'

  const { t } = useI18n()
  const props = defineProps<{ modelValue: boolean; merchant?: any; role?: string }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; success: [] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const canEdit = computed(() => props.role === 'super_admin')
  const loading = ref(false)
  const saving = ref(false)
  const billingMode = ref(1)
  const monthlyMetric = ref(1)
  const monthlyMinFee = ref(1000)
  const tiers = ref<any[]>([])
  const credits = ref<any[]>([])
  const stats = ref<any>()
  const nextFee = ref('0')
  const bill = ref<any>()
  const billStatus = computed<Record<number, string>>(() => ({
    0: t('game.pending'),
    1: t('game.paid'),
    2: t('game.overdue'),
    3: t('game.waived')
  }))
  const adjusting = ref<any>()
  const adjustVisible = computed({
    get: () => Boolean(adjusting.value),
    set: (value) => !value && (adjusting.value = undefined)
  })
  const adjust = reactive({ direction: 1, amount: '', remark: '' })

  const load = async () => {
    if (!props.merchant) return
    loading.value = true
    try {
      const data = await api.billing(props.merchant.id)
      billingMode.value = Number(data.merchant.billing_mode)
      monthlyMetric.value = Number(data.merchant.monthly_metric || 1)
      monthlyMinFee.value = Number(data.merchant.monthly_min_fee || 1000)
      tiers.value = (
        data.merchant.monthly_tiers?.length
          ? data.merchant.monthly_tiers
          : [{ min: 0, fee: monthlyMinFee.value }]
      ).map((item: any) => ({ min: Number(item.min), fee: Number(item.fee) }))
      credits.value = data.credits.map((item: any) => ({
        ...item,
        rate_percent: rateToPercent(item.rate_value)
      }))
      stats.value = data.stats
      nextFee.value = data.next_fee || monthlyMinFee.value
      bill.value = data.bill
    } finally {
      loading.value = false
    }
  }
  watch(visible, (value) => value && load())

  const save = async () => {
    if (billingMode.value === 2 && (monthlyMinFee.value <= 0 || !tiers.value.length)) {
      return ElMessage.warning(t('game.correctMonthlyRules'))
    }
    saving.value = true
    try {
      await api.saveBilling({
        id: props.merchant.id,
        billing_mode: billingMode.value,
        monthly_metric: monthlyMetric.value,
        monthly_min_fee: monthlyMinFee.value,
        monthly_tiers: tiers.value,
        credits: credits.value.map((item) => ({
          currency_code: item.currency_code,
          rate_value: percentToRate(item.rate_percent),
          status: item.status
        }))
      })
      ElMessage.success(t('game.billingSaved'))
      visible.value = false
      emit('success')
    } finally {
      saving.value = false
    }
  }
  const submitAdjust = async () => {
    if (!adjust.amount || Number(adjust.amount) <= 0)
      return ElMessage.warning(t('game.correctAmount'))
    await api.adjustCredit({ credit_id: adjusting.value.id, ...adjust })
    Object.assign(adjust, { direction: 1, amount: '', remark: '' })
    adjusting.value = undefined
    ElMessage.success(t('game.creditAdjusted'))
    await load()
    emit('success')
  }
  const markPaid = async () => {
    await api.updateMonthlyBill({ id: bill.value.id, status: 1, remark: bill.value.remark || '' })
    ElMessage.success(t('game.billMarkedPaid'))
    await load()
  }
</script>
