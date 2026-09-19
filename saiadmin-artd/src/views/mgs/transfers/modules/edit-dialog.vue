<script setup lang="ts">
  import { useGameTime } from '@/composables/useGameTime'
  import { useI18n } from 'vue-i18n'
  import { ElMessageBox } from 'element-plus'
  import api from '@/api/mgs/recharges'
  const props = defineProps<{ data: Partial<Record<string, unknown>> }>()
  const visible = defineModel<boolean>({ required: true })
  const emit = defineEmits<{ success: [] }>()
  const { formatJson } = useGameTime()
  const { t } = useI18n()
  const form = reactive({ mgs_recharge_id: '', remark: '', status: 'review' })
  const formRef = ref()
  const busy = ref(false)
  const rules = computed(() => ({
    remark: [{ required: true, message: t('mgsRecharge.reasonRequired'), trigger: 'blur' }]
  }))
  watch(visible, (open) => {
    if (open)
      Object.assign(form, {
        mgs_recharge_id: String(props.data.recharge_id || ''),
        remark: '',
        status: 'review'
      })
  })
  async function save(credit: boolean) {
    await formRef.value.validate()
    if (credit && !/^[1-9]\d*$/.test(form.mgs_recharge_id)) return
    if (credit)
      await ElMessageBox.confirm(t('mgsRecharge.creditWarning'), t('mgsRecharge.credit'), {
        type: 'warning'
      })
    busy.value = true
    try {
      if (credit) await api.credit(String(props.data.id), form)
      else await api.review(String(props.data.id), form)
      visible.value = false
      emit('success')
    } finally {
      busy.value = false
    }
  }
</script>
<template>
  <ElDialog v-model="visible" :title="t('mgsRecharge.review')" width="min(90vw, 700px)">
    <pre class="max-h-60 overflow-auto whitespace-pre-wrap break-all">{{ formatJson(data) }}</pre>
    <ElForm ref="formRef" :model="form" :rules="rules" label-position="top">
      <ElFormItem :label="t('mgsRecharge.rechargeId')"
        ><ElInput v-model="form.mgs_recharge_id"
      /></ElFormItem>
      <ElFormItem :label="t('mgsRecharge.reason')" prop="remark"
        ><ElInput v-model="form.remark" type="textarea" maxlength="500"
      /></ElFormItem>
      <ElFormItem :label="t('mgs.status')"
        ><ElSelect v-model="form.status"
          ><ElOption :label="t('mgsRecharge.review')" value="review" /><ElOption
            :label="t('mgsRecharge.ignored')"
            value="ignored" /></ElSelect
      ></ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton v-permission="'app:mgs:recharge:review'" :loading="busy" @click="save(false)">{{
        t('mgsRecharge.saveReview')
      }}</ElButton>
      <ElButton
        v-permission="'app:mgs:recharge:credit'"
        type="danger"
        :loading="busy"
        :disabled="data.status !== 'review' || !form.mgs_recharge_id"
        @click="save(true)"
        >{{ t('mgsRecharge.credit') }}</ElButton
      >
    </template>
  </ElDialog>
</template>
