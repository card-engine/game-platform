<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import api from '@/api/mgs/recharges'
  const { t } = useI18n()
  const state = ref<Awaited<ReturnType<typeof api.scan>>>()
  const busy = ref(false)
  async function load() {
    busy.value = true
    try {
      state.value = await api.scan()
    } finally {
      busy.value = false
    }
  }
  onMounted(load)
</script>
<template>
  <ElCard shadow="never">
    <template #header
      ><ElSpace
        ><span>{{ t('mgsRecharge.scan') }}</span
        ><ElButton :loading="busy" @click="load">{{ t('mgsRecharge.refresh') }}</ElButton
        ><ElTag :type="state?.ready ? 'success' : 'warning'">{{
          state?.ready ? t('mgsRecharge.ready') : t('mgsRecharge.notReady')
        }}</ElTag></ElSpace
      ></template
    >
    <p>{{ t('mgsRecharge.scanHint') }}</p>
    <pre class="whitespace-pre-wrap break-all">{{ JSON.stringify(state, null, 2) }}</pre>
  </ElCard>
</template>
