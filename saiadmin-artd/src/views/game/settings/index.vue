<template>
  <ElCard shadow="never">
    <template #header
      ><div class="flex items-center gap-2 font-medium"
        >{{ t('game.platformStatistics') }}<SuperBadge inline /></div
    ></template>
    <ElForm v-loading="loading" label-width="140px" class="max-w-2xl">
      <ElFormItem :label="t('game.platformTimezone')"
        ><span>{{ form.platform_timezone }}</span></ElFormItem
      >
      <ElFormItem :label="t('game.platformCurrency')"
        ><span>{{ form.platform_currency_code }}</span></ElFormItem
      >
      <ElFormItem
        ><span class="text-sm text-g-500">{{ t('game.installOnlySettings') }}</span></ElFormItem
      >
      <ElFormItem :label="t('game.exchangeDisplayCurrencies')">
        <ElSelect
          v-model="form.exchange_rate_display_codes"
          multiple
          filterable
          allow-create
          default-first-option
          class="w-full"
        >
          <ElOption v-for="code in currencies" :key="code" :label="code" :value="code" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem
        ><ElButton
          v-permission="'app:game:settings:update'"
          type="primary"
          :loading="saving"
          @click="save"
          >{{ t('game.save') }}</ElButton
        ></ElFormItem
      >
    </ElForm>
  </ElCard>
</template>
<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import api from '@/api/game/settings'
  import SuperBadge from '@/components/business/super-badge.vue'
  const { t } = useI18n()
  const loading = ref(false)
  const saving = ref(false)
  const currencies = ['USD', 'USDT', 'CNY', 'EUR', 'GBP', 'INR', 'PKR', 'BRL', 'MXN']
  const form = reactive({
    platform_timezone: '',
    platform_currency_code: '',
    exchange_rate_display_codes: [...currencies]
  })
  onMounted(async () => {
    loading.value = true
    try {
      Object.assign(
        form,
        Object.fromEntries((await api.configs()).map((item) => [item.code, item.value]))
      )
    } finally {
      loading.value = false
    }
  })
  async function save() {
    saving.value = true
    try {
      await api.save({
        exchange_rate_display_codes: form.exchange_rate_display_codes.map((code) =>
          code.toUpperCase()
        )
      })
      ElMessage.success(t('game.globalSettingsSaved'))
    } finally {
      saving.value = false
    }
  }
</script>
