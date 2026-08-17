<template>
  <div class="game-context max-lg:!hidden">
    <span class="game-context__time max-xl:!hidden">{{ timezone }} · {{ time }}</span>
    <ElSelect
      v-model="merchantId"
      filterable
      :loading="loading"
      :placeholder="$t('game.merchantParam')"
      class="game-context__merchant"
      @change="change"
    >
      <ElOption v-for="item in merchants" :key="item.id" :label="item.label" :value="item.id" />
    </ElSelect>
  </div>
</template>

<script setup lang="ts">
  import api from '@/api/game/context'
  import { useGameStore } from '@/store/modules/game'
  import { mittBus } from '@/utils/sys'
  import { useI18n } from 'vue-i18n'

  const { t } = useI18n()
  const store = useGameStore()
  const merchantId = ref<number>()
  const merchants = ref<any[]>([])
  const timezone = ref('UTC')
  const time = ref('')
  const loading = ref(false)
  let timer: number
  let offset = 0

  const tick = () => {
    const date = new Date(Date.now() + offset)
    time.value = new Intl.DateTimeFormat('sv-SE', {
      timeZone: timezone.value,
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    }).format(date)
  }
  const load = async () => {
    loading.value = true
    try {
      const data = await api.read()
      merchants.value =
        data.role === 'super_admin'
          ? [{ id: 0, label: t('game.allMerchants'), timezone: data.timezone }, ...data.merchants]
          : data.merchants
      store.setRole(data.role)
      offset = Number(data.server_time) * 1000 - Date.now()
      const selected = merchants.value.find((item) => item.id === store.merchantId)
      merchantId.value = selected?.id ?? data.merchant_id
      store.setMerchant(merchantId.value)
      timezone.value = selected?.timezone || data.timezone
      tick()
    } finally {
      loading.value = false
    }
  }
  const change = (id: number) => {
    store.setMerchant(id)
    timezone.value = merchants.value.find((item) => item.id === id)?.timezone || timezone.value
    mittBus.emit('gameMerchantChanged', id)
  }
  onMounted(async () => {
    await load()
    timer = window.setInterval(tick, 1000)
  })
  onUnmounted(() => window.clearInterval(timer))
</script>

<style scoped>
  .game-context {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
  }

  .game-context__time {
    color: var(--art-gray-600);
    font-size: 12px;
    white-space: nowrap;
  }

  .game-context__merchant {
    width: 230px;
  }
</style>
