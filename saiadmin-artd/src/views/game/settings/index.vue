<template>
  <div class="settings-page">
    <ElAlert type="warning" :closable="false" show-icon>
      <template #title>{{ $t('game.platformSettingsWarning') }}</template>
    </ElAlert>
    <ElAlert
      v-if="rebuild.status !== 'idle'"
      :type="
        rebuild.status === 'failed' ? 'error' : rebuild.status === 'completed' ? 'success' : 'info'
      "
      :closable="false"
      show-icon
    >
      <template #title>
        {{ rebuild.message }}
        <span v-if="['queued', 'running'].includes(rebuild.status)"
          >（{{ rebuild.progress }}%）</span
        >
      </template>
    </ElAlert>
    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center gap-2 font-medium">
          {{ $t('game.platformStatistics') }}
          <SuperBadge inline />
        </div>
      </template>
      <ElForm v-loading="loading" :model="form" label-width="140px" class="max-w-2xl">
        <ElFormItem>
          <template #label>
            <span class="field-label">
              {{ $t('game.platformTimezone') }}
              <ElTooltip :content="$t('game.platformTimezoneHelp')" placement="top">
                <ElIcon><QuestionFilled /></ElIcon>
              </ElTooltip>
            </span>
          </template>
          <ElSelect v-model="form.platform_timezone" filterable class="w-full">
            <ElOption
              v-for="item in timezones"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <template #label>
            <span class="field-label">
              {{ $t('game.platformCurrency') }}
              <ElTooltip :content="$t('game.platformCurrencyHelp')" placement="top">
                <ElIcon><QuestionFilled /></ElIcon>
              </ElTooltip>
            </span>
          </template>
          <ElSelect
            v-model="form.platform_currency_code"
            filterable
            allow-create
            default-first-option
            class="w-full"
          >
            <ElOption v-for="code in currencies" :key="code" :label="code" :value="code" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="$t('game.exchangeDisplayCurrencies')">
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
        <ElFormItem>
          <ElButton
            type="primary"
            :loading="saving"
            :disabled="['queued', 'running'].includes(rebuild.status)"
            class="relative !overflow-visible"
            @click="save"
          >
            <ArtSvgIcon icon="ri:save-line" />
            {{ $t('game.save') }}
            <SuperBadge />
          </ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { QuestionFilled } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import api from '@/api/game/settings'
  import merchantApi from '@/api/game/merchant'
  import SuperBadge from '@/components/business/super-badge.vue'

  const { t } = useI18n()

  const loading = ref(false)
  const saving = ref(false)
  const rebuild = ref<any>({ status: 'idle', progress: 100 })
  let statusTimer: ReturnType<typeof setTimeout>
  const timezones = ref<{ value: string; label: string }[]>([])
  const currencies = ['USD', 'USDT', 'CNY', 'EUR', 'GBP', 'INR', 'PKR', 'BRL', 'MXN']
  const form = reactive({
    platform_timezone: 'UTC',
    platform_currency_code: 'USD',
    exchange_rate_display_codes: [...currencies]
  })
  const load = async () => {
    loading.value = true
    try {
      const [configs, options] = await Promise.all([api.configs(), merchantApi.options()])
      for (const config of configs) (form as any)[config.code] = config.value
      timezones.value = options.timezones
    } finally {
      loading.value = false
    }
  }
  const loadRebuildStatus = async () => {
    rebuild.value = await api.rebuildStatus()
    if (['queued', 'running'].includes(rebuild.value.status))
      statusTimer = setTimeout(loadRebuildStatus, 2000)
  }
  const save = async () => {
    await ElMessageBox.confirm(t('game.settingsChangeConfirm'), t('game.impactConfirmation'), {
      type: 'warning'
    })
    saving.value = true
    try {
      await api.save({
        platform_timezone: form.platform_timezone,
        platform_currency_code: form.platform_currency_code.toUpperCase(),
        exchange_rate_display_codes: form.exchange_rate_display_codes.map((item) =>
          item.toUpperCase()
        )
      })
      ElMessage.success(t('game.globalSettingsSaved'))
      await loadRebuildStatus()
    } finally {
      saving.value = false
    }
  }
  onMounted(() => {
    load()
    loadRebuildStatus()
  })
  onUnmounted(() => clearTimeout(statusTimer))
</script>

<style scoped>
  .settings-page {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .field-label {
    display: inline-flex;
    align-items: center;
    gap: 5px;
  }

  .field-label .el-icon {
    color: var(--el-text-color-secondary);
    cursor: help;
  }
</style>
