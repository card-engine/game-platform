<template>
  <ElDialog
    v-model="visible"
    :title="dialogType === 'add' ? $t('game.newEnterprise') : $t('game.editEnterprise')"
    width="min(720px, 94vw)"
    @closed="reset"
  >
    <ElForm ref="formRef" :model="form" :rules="rules" label-width="100px">
      <ElFormItem :label="$t('game.enterpriseName')" prop="name"
        ><ElInput v-model="form.name"
      /></ElFormItem>
      <div class="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        <ElFormItem :label="$t('game.merchantLimit')" prop="merchant_limit">
          <ElInputNumber v-model="form.merchant_limit" :min="1" class="!w-full" />
        </ElFormItem>
        <ElFormItem :label="$t('game.status')"
          ><ElSwitch v-model="form.status" :active-value="1" :inactive-value="0"
        /></ElFormItem>
        <ElFormItem :label="$t('game.timezone')" prop="timezone">
          <ElSelect v-model="form.timezone" filterable class="w-full">
            <ElOption
              v-for="item in timezones"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="$t('game.defaultLanguage')" prop="default_language">
          <ElSelect v-model="form.default_language" class="w-full">
            <ElOption
              v-for="item in gameLanguages"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
      </div>
      <template v-if="dialogType === 'add'">
        <ElDivider content-position="left">{{ $t('game.ownerLoginAccount') }}</ElDivider>
        <ElFormItem :label="$t('game.loginAccount')" prop="username"
          ><ElInput v-model="form.username" autocomplete="off"
        /></ElFormItem>
        <ElFormItem :label="$t('game.loginPassword')" prop="password">
          <ElInput
            v-model="form.password"
            type="password"
            show-password
            autocomplete="new-password"
          />
        </ElFormItem>
        <ElFormItem :label="$t('game.displayName')"><ElInput v-model="form.realname" /></ElFormItem>
        <ElFormItem :label="$t('game.accountType')"
          ><ElInput :value="$t('game.accountRole')" disabled
        /></ElFormItem>
        <ElFormItem :label="$t('game.createMerchantTogether')"
          ><ElSwitch v-model="form.create_merchant"
        /></ElFormItem>
        <div v-if="form.create_merchant" class="mb-3 border border-g-200 p-3">
          <div class="mb-3 text-sm font-medium">{{ $t('game.firstMerchantHint') }}</div>
          <div class="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
            <ElFormItem :label="$t('game.paramName')"
              ><ElInput v-model="form.merchant.name"
            /></ElFormItem>
            <ElFormItem :label="$t('game.currency')">
              <ElSelect
                v-model="form.merchant.currency_codes"
                multiple
                filterable
                allow-create
                default-first-option
                class="w-full"
                :placeholder="$t('game.currencyExample')"
              />
            </ElFormItem>
            <ElFormItem :label="$t('game.ggrRate')"
              ><ElInputNumber
                v-model="form.merchant.rate_percent"
                :min="0"
                :max="100"
                :precision="4"
                class="!w-full"
            /></ElFormItem>
            <ElFormItem :label="$t('game.defaultLanguage')">
              <ElSelect v-model="form.merchant.default_language" class="w-full">
                <ElOption
                  v-for="item in gameLanguages"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="$t('game.supportedLanguages')" class="sm:col-span-2">
              <ElSelect v-model="form.merchant.language_codes" multiple filterable class="w-full">
                <ElOption
                  v-for="item in gameLanguages"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="$t('game.callbackUrl')" class="sm:col-span-2"
              ><ElInput
                v-model="form.merchant.callback_url"
                placeholder="https://merchant.example/callback"
            /></ElFormItem>
            <ElFormItem :label="$t('game.timezone')" class="sm:col-span-2">
              <ElSelect v-model="form.merchant.timezone" filterable class="w-full">
                <ElOption
                  v-for="item in timezones"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
                />
              </ElSelect>
            </ElFormItem>
          </div>
        </div>
      </template>
      <ElFormItem :label="$t('game.remark')"
        ><ElInput v-model="form.remark" type="textarea" :rows="2"
      /></ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ $t('game.cancel') }}</ElButton>
      <ElButton type="primary" :loading="saving" @click="submit">{{ $t('game.save') }}</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import api from '@/api/game/enterprise'
  import { gameLanguages } from '@/utils/game/options'

  const props = defineProps<{
    modelValue: boolean
    dialogType: string
    data?: any
    timezones: { value: string; label: string }[]
  }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; success: [] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const initial = {
    id: undefined,
    name: '',
    merchant_limit: 1,
    timezone: 'UTC',
    default_language: 'en',
    status: 1,
    username: '',
    password: '',
    realname: '',
    create_merchant: false,
    merchant: {
      name: '',
      callback_url: '',
      currency_codes: ['USD'],
      rate_percent: 3,
      language_codes: ['en'],
      default_language: 'en',
      timezone: 'UTC'
    },
    remark: ''
  }
  const form = reactive<any>({ ...initial })
  const formRef = ref<FormInstance>()
  const saving = ref(false)
  const { t } = useI18n()
  const rules: FormRules = {
    name: [{ required: true, message: t('game.pleaseEnter') + t('game.enterpriseName') }],
    merchant_limit: [{ required: true, message: t('game.pleaseEnter') + t('game.merchantLimit') }],
    timezone: [{ required: true, message: t('game.pleaseEnter') + t('game.timezone') }],
    default_language: [{ required: true, message: t('game.pleaseEnter') + t('game.language') }],
    username: [{ required: true, message: t('game.pleaseEnter') + t('game.loginAccount') }],
    password: [{ required: true, min: 6, message: t('game.passwordMinSix') }]
  }
  watch(visible, (value) => {
    if (!value) return
    Object.assign(form, initial, props.data || {}, {
      create_merchant: false,
      merchant: { ...initial.merchant }
    })
  })
  const reset = () => formRef.value?.resetFields()
  const submit = async () => {
    await formRef.value?.validate()
    if (
      props.dialogType === 'add' &&
      form.create_merchant &&
      (!form.merchant.name || !form.merchant.currency_codes.length)
    )
      return ElMessage.warning(t('game.firstMerchantRequired'))
    saving.value = true
    try {
      await (props.dialogType === 'add' ? api.save(form) : api.update(form))
      ElMessage.success(t('game.saveSuccess'))
      visible.value = false
      emit('success')
    } finally {
      saving.value = false
    }
  }
</script>
