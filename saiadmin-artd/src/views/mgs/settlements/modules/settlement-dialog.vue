<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
  import api, { type Settlement } from '@/api/mgs/settlements'
  import { displayAmount, rateToPercent } from '@/utils/game/amount'
  const props = defineProps<{ data: Settlement; mode: 'view' | 'confirm' | 'pay' | 'reopen' }>()
  const emit = defineEmits<{ close: []; success: [] }>()
  const { t } = useI18n()
  const formRef = ref<FormInstance>()
  const busy = ref(false)
  const form = reactive({
    remark: '',
    payment_reference: '',
    paid_time: new Date().toISOString().slice(0, 19).replace('T', ' ')
  })
  const rules = computed<FormRules>(() =>
    Object.fromEntries(
      ['remark', 'payment_reference', 'paid_time'].map((key) => [
        key,
        [{ required: true, message: t('mgs.reasonRequired'), trigger: 'blur' }]
      ])
    )
  )
  async function submit() {
    if (!(await formRef.value?.validate())) return
    await ElMessageBox.confirm(t('mgs.settlementActionHint'), t('mgs.settlement'), {
      type: 'warning'
    })
    busy.value = true
    try {
      if (props.mode === 'pay') await api.pay(props.data.id, form)
      else if (props.mode === 'confirm') await api.confirm(props.data.id, { remark: form.remark })
      else if (props.mode === 'reopen') await api.reopen(props.data.id, { remark: form.remark })
      emit('success')
      emit('close')
    } finally {
      busy.value = false
    }
  }
</script>
<template>
  <ElDialog
    :model-value="true"
    :title="t('mgs.settlement')"
    width="min(92vw, 720px)"
    @close="emit('close')"
  >
    <ElDescriptions :column="1" border>
      <ElDescriptionsItem :label="t('mgs.settlement')"
        >{{ data.settlement_no }} · {{ data.settlement_month }}</ElDescriptionsItem
      >
      <ElDescriptionsItem :label="t('mgs.sourceBill')">{{
        data.data?.source?.bill_no || '—'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem :label="t('mgs.billingTimezone')">{{
        data.data?.timezone || '—'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="GGR"
        >{{ displayAmount(data.ggr_amount) }} {{ data.currency_code }}</ElDescriptionsItem
      >
      <ElDescriptionsItem :label="t('mgs.platformFee')"
        >{{ displayAmount(data.platform_fee) }} {{ data.currency_code }}</ElDescriptionsItem
      >
      <ElDescriptionsItem :label="t('mgs.rateDetails')"
        ><div v-for="(rate, index) in data.data?.source?.rules_snapshot?.rates" :key="index"
          >{{ rateToPercent(rate.merchant_rate_value) }}% · GGR
          {{ displayAmount(rate.ggr_amount) }}</div
        ></ElDescriptionsItem
      >
      <ElDescriptionsItem :label="t('mgs.confirmed')"
        >{{ data.confirmed_by || '—' }} · {{ data.confirmed_time || '—' }} UTC</ElDescriptionsItem
      >
      <ElDescriptionsItem :label="t('mgs.paidTime')"
        >{{ data.paid_by || '—' }} · {{ data.paid_time || '—' }} UTC</ElDescriptionsItem
      >
      <ElDescriptionsItem :label="t('mgs.paymentReference')">{{
        data.payment_reference || '—'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem :label="t('mgsRecharge.reason')">{{
        data.remark || '—'
      }}</ElDescriptionsItem>
    </ElDescriptions>
    <ElForm
      v-if="mode !== 'view'"
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="top"
      class="mt-4"
    >
      <ElAlert :title="t('mgs.settlementActionHint')" type="info" :closable="false" class="mb-4" />
      <template v-if="mode === 'pay'">
        <ElFormItem prop="payment_reference" :label="t('mgs.paymentReference')"
          ><ElInput v-model="form.payment_reference" maxlength="180"
        /></ElFormItem>
        <ElFormItem prop="paid_time" :label="t('mgs.paidTime') + ' · UTC'"
          ><ElInput v-model="form.paid_time" placeholder="YYYY-MM-DD HH:mm:ss"
        /></ElFormItem>
      </template>
      <ElFormItem prop="remark" :label="t('mgsRecharge.reason')"
        ><ElInput v-model="form.remark" type="textarea" maxlength="300"
      /></ElFormItem>
    </ElForm>
    <template #footer
      ><ElButton @click="emit('close')">{{ t('mgs.close') }}</ElButton
      ><ElButton v-if="mode !== 'view'" :loading="busy" type="primary" @click="submit">{{
        t('mgs.submit')
      }}</ElButton></template
    >
  </ElDialog>
</template>
