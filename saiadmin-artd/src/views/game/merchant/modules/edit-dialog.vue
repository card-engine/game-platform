<template>
  <ElDialog
    v-model="visible"
    :title="dialogType === 'add' ? $t('game.newMerchant') : $t('game.editMerchant')"
    width="min(760px, 94vw)"
    @closed="formRef?.resetFields()"
  >
    <ElForm ref="formRef" :model="form" :rules="rules" label-width="100px">
      <div class="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        <ElFormItem
          v-if="dialogType === 'add'"
          :label="$t('game.ownerEnterprise')"
          prop="enterprise_id"
        >
          <ElSelect v-model="form.enterprise_id" class="w-full"
            ><ElOption
              v-for="item in options.enterprises"
              :key="item.id"
              :label="item.name"
              :value="item.id"
          /></ElSelect>
        </ElFormItem>
        <ElFormItem :label="$t('game.paramName')" prop="name"
          ><ElInput v-model="form.name"
        /></ElFormItem>
        <ElFormItem :label="$t('game.walletMode')"
          ><ElInput :value="$t('game.seamlessWallet')" disabled
        /></ElFormItem>
        <ElFormItem :label="$t('game.status')"
          ><ElSelect v-model="form.status" class="w-full"
            ><ElOption :label="$t('game.pending')" :value="0" /><ElOption
              :label="$t('game.enabled')"
              :value="1" /><ElOption :label="$t('game.paused')" :value="2" /><ElOption
              :label="$t('game.closed')"
              :value="3" /></ElSelect
        ></ElFormItem>
        <ElFormItem :label="$t('game.defaultLanguage')" prop="default_language"
          ><ElSelect v-model="form.default_language" class="w-full">
            <ElOption
              v-for="item in gameLanguages"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="$t('game.supportedLanguages')" prop="language_codes"
          ><ElSelect v-model="form.language_codes" multiple filterable class="w-full">
            <ElOption
              v-for="item in gameLanguages"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="$t('game.timezone')" prop="timezone"
          ><ElSelect v-model="form.timezone" filterable class="w-full"
            ><ElOption
              v-for="item in options.timezones"
              :key="item.value"
              :label="item.label"
              :value="item.value" /></ElSelect
        ></ElFormItem>
        <ElFormItem :label="$t('game.timeoutMs')" prop="timeout_ms"
          ><ElInputNumber v-model="form.timeout_ms" :min="500" :max="30000" class="!w-full"
        /></ElFormItem>
      </div>
      <ElFormItem :label="$t('game.callbackUrl')" prop="callback_url"
        ><ElInput v-model="form.callback_url" placeholder="https://merchant.example/mg"
      /></ElFormItem>
      <ElFormItem :label="$t('game.ipWhitelist')"
        ><ElInput
          v-model="form.ip_whitelist_text"
          type="textarea"
          :rows="3"
          :placeholder="$t('game.ipPerLine')"
      /></ElFormItem>
      <ElDivider content-position="left">{{ $t('game.openCurrencies') }}</ElDivider>
      <ElAlert
        type="info"
        :closable="false"
        :title="$t('game.newMerchantBillingHint')"
        class="mb-3"
      />
      <div
        v-for="item in form.credits"
        :key="item.currency_code"
        class="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2"
      >
        <ElInput v-model="item.currency_code" :placeholder="$t('game.currencyExample')" />
        <ElInputNumber
          v-model="item.rate_percent"
          :min="0"
          :max="100"
          :precision="4"
          class="!w-full"
          :placeholder="$t('game.ratePercent')"
        />
      </div>
      <ElButton size="small" @click="form.credits.push(newCredit())">{{
        $t('game.addCurrency')
      }}</ElButton>
      <div class="text-xs text-g-500">{{ $t('game.scGcUsageHint') }}</div>
      <ElDivider content-position="left">{{ $t('game.grantConfig') }}</ElDivider>
      <ElFormItem :label="$t('game.copyGrant')">
        <ElSelect
          v-model="form.copy_from_merchant_id"
          clearable
          filterable
          class="w-full"
          :placeholder="$t('game.copyGrantPlaceholder')"
        >
          <ElOption
            v-for="item in sourceMerchants"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="$t('game.grantBrands')"
        ><ElSelect
          v-model="form.brand_ids"
          multiple
          filterable
          class="w-full"
          :disabled="Boolean(form.copy_from_merchant_id)"
          ><ElOption
            v-for="item in options.brands"
            :key="item.id"
            :label="`${item.name} · ${item.code}`"
            :value="item.id" /></ElSelect
      ></ElFormItem>
      <ElFormItem :label="$t('game.remark')"
        ><ElInput v-model="form.remark" type="textarea" :rows="2"
      /></ElFormItem>
    </ElForm>
    <template #footer
      ><ElButton @click="visible = false">{{ $t('game.cancel') }}</ElButton
      ><ElButton type="primary" :loading="saving" @click="submit">{{
        $t('game.save')
      }}</ElButton></template
    >
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import type { FormInstance, FormRules } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import api from '@/api/game/merchant'
  import { gameLanguages } from '@/utils/game/options'

  const { t } = useI18n()

  const props = defineProps<{ modelValue: boolean; dialogType: string; data?: any; options: any }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; success: [] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const newCredit = (currency_code = '') => ({
    currency_code,
    rate_percent: 3,
    available_amount: '0',
    settlement_enabled: currency_code !== 'GC' ? 1 : 0,
    status: 1
  })
  const initial = {
    id: undefined,
    enterprise_id: undefined,
    name: '',
    wallet_mode: 1,
    callback_url: '',
    ip_whitelist: [] as string[],
    ip_whitelist_text: '',
    language_codes: ['en'],
    default_language: 'en',
    timezone: 'UTC',
    timeout_ms: 5000,
    status: 1,
    remark: '',
    credits: [newCredit()],
    brand_ids: [] as number[],
    copy_from_merchant_id: undefined
  }
  const form = reactive<any>({ ...initial })
  const formRef = ref<FormInstance>()
  const saving = ref(false)
  const sourceMerchants = computed(() =>
    (props.options.merchants || []).filter(
      (item: any) => item.enterprise_id === form.enterprise_id && item.id !== form.id
    )
  )
  const rules: FormRules = {
    enterprise_id: [{ required: true, message: t('game.selectEnterprise') }],
    name: [{ required: true, message: t('game.enterParamName') }],
    callback_url: [{ required: true, type: 'url', message: t('game.enterCallback') }],
    language_codes: [{ required: true, type: 'array', min: 1, message: t('game.enterLanguages') }],
    default_language: [{ required: true, message: t('game.enterDefaultLanguage') }],
    timezone: [{ required: true, message: t('game.enterTimezone') }]
  }
  watch(visible, (value) => {
    if (!value) return
    Object.assign(form, initial, props.data || {}, {
      ip_whitelist_text: (props.data?.ip_whitelist || []).join('\n'),
      credits: props.data?.credits?.length
        ? props.data.credits.map((item: any) => ({
            ...item,
            rate_percent: Number(item.rate_value || 0) * 100
          }))
        : [newCredit()]
    })
  })
  const submit = async () => {
    await formRef.value?.validate()
    saving.value = true
    try {
      const data = {
        ...form,
        ip_whitelist: Array.from(
          new Set(
            form.ip_whitelist_text
              .split(/\r?\n/)
              .map((item: string) => item.trim())
              .filter(Boolean)
          )
        ),
        credits: form.credits
          .filter((item: any) => item.currency_code)
          .map((item: any) => ({
            ...item,
            currency_code: item.currency_code.toUpperCase(),
            rate_value: String(Number(item.rate_percent || 0) / 100)
          }))
      }
      if (props.dialogType === 'add') await api.save(data)
      else await api.update(data)
      ElMessage.success(t('game.saveSuccess'))
      visible.value = false
      emit('success')
    } finally {
      saving.value = false
    }
  }
</script>
