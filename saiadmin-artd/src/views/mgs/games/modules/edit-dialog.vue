<template>
  <ElDialog
    v-model="visible"
    :title="dialogType === 'edit' ? $t('mgs.configureGame') : $t('mgs.gameConfig')"
    width="560px"
    align-center
    destroy-on-close
  >
    <ElForm ref="formRef" :model="form" label-width="100px">
      <ElFormItem :label="$t('mgs.gameName')"><ElInput v-model="form.name" disabled /></ElFormItem>
      <ElFormItem :label="$t('mgs.gamePlatform')"
        ><ElInput v-model="form.platform" disabled
      /></ElFormItem>
      <ElFormItem :label="$t('mgs.sort')"
        ><ElInputNumber v-model="form.sort" :min="0" class="!w-full"
      /></ElFormItem>
      <ElFormItem :label="$t('mgs.rate')"><ElInput v-model="form.rate_value" /></ElFormItem>
      <ElFormItem v-if="form.support_rtp" :label="$t('mgs.defaultRtp')">
        <ElSelect v-model="form.default_rtp" clearable class="w-full">
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
    <template #footer>
      <ElButton @click="visible = false">{{ $t('mgs.cancel') }}</ElButton>
      <ElButton type="primary" :loading="saving" @click="submit">{{ $t('mgs.save') }}</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import type { FormInstance } from 'element-plus'
  import api from '@/api/mgs'

  const { t } = useI18n()

  const props = defineProps<{
    modelValue: boolean
    dialogType: string
    data?: Record<string, any>
  }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; success: [] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const formRef = ref<FormInstance>()
  const form = reactive<Record<string, any>>({})
  const saving = ref(false)
  watch(visible, (value) => value && Object.assign(form, props.data || {}))
  const submit = async () => {
    await formRef.value?.validate()
    saving.value = true
    try {
      await api.gameConfig(form)
      ElMessage.success(t('mgs.saved'))
      emit('success')
      visible.value = false
    } finally {
      saving.value = false
    }
  }
</script>
