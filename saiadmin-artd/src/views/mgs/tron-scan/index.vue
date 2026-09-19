<script setup lang="ts">
  import { useGameTime } from '@/composables/useGameTime'
  import { useDocumentVisibility, useIntervalFn } from '@vueuse/core'
  import { useI18n } from 'vue-i18n'
  import api, { type TronStatus } from '@/api/mgs/recharges'
  import BlockStream from './modules/block-stream.vue'
  import RecentRecharges from './modules/recent-recharges.vue'

  const { t } = useI18n()
  const { timezone, formatTime } = useGameTime()
  const state = ref<TronStatus>()
  const busy = ref(false)
  const failed = ref(false)
  const active = ref(true)
  const detailsVisible = ref(false)
  const visibility = useDocumentVisibility()
  let request: AbortController | undefined
  const { pause, resume } = useIntervalFn(load, 1000, { immediate: false })
  const running = computed(() => active.value && visibility.value === 'visible')
  const statusText = computed(() => {
    if (failed.value) return t('tronScan.refreshFailed')
    if (!state.value?.checkpoint) return t('tronScan.waiting')
    if (state.value.health?.error) return t('tronScan.error')
    return state.value.ready ? t('tronScan.normal') : t('tronScan.delayed')
  })
  const metrics = computed(() => [
    {
      label: t('tronScan.solid'),
      value: state.value?.health?.solid_number || null,
      hint: state.value?.health?.solid_time
        ? formatTime(state.value.health.solid_time, 'time')
        : '—'
    },
    {
      label: t('tronScan.scanned'),
      value: state.value?.scanned_number,
      hint: state.value?.recent_blocks[0] ? `${state.value.recent_blocks[0].duration_ms} ms` : '—'
    },
    {
      label: t('tronScan.lag'),
      value: state.value?.lag_blocks,
      hint: state.value?.health
        ? t('tronScan.heartbeat', {
            seconds: Math.max(0, state.value.server_time - state.value.health.heartbeat_time)
          })
        : '—'
    },
    {
      details: true,
      label: t('tronScan.gapBlocks'),
      value: state.value?.gap_blocks,
      hint: t('tronScan.gapTasks', { count: Object.keys(state.value?.gaps ?? {}).length })
    }
  ])

  async function load() {
    if (busy.value || !running.value) return
    busy.value = true
    request = new AbortController()
    try {
      state.value = await api.scan(request.signal)
      failed.value = false
    } catch {
      if (!request.signal.aborted) failed.value = true
    } finally {
      busy.value = false
    }
  }
  watch(
    running,
    (value) => {
      if (value) {
        resume()
        void load()
      } else {
        pause()
        request?.abort()
      }
    },
    { immediate: true }
  )
  onActivated(() => {
    active.value = true
  })
  onDeactivated(() => {
    active.value = false
  })
  onBeforeUnmount(() => {
    pause()
    request?.abort()
  })
</script>

<template>
  <div class="tron-page">
    <header class="tron-heading">
      <div>
        <h2>{{ t('tronScan.title') }}</h2>
        <p
          >TRON MAINNET · {{ t('tronScan.subtitle')
          }}<span v-if="state">
            · {{ t('tronScan.updated') }} {{ formatTime(state.server_time, 'time') }} ·
            {{ timezone }}</span
          ></p
        >
      </div>
      <ElSpace wrap>
        <ElTag
          :type="failed || state?.health?.error ? 'danger' : state?.ready ? 'success' : 'warning'"
          >{{ statusText }}</ElTag
        >
        <ElButton size="small" :loading="busy && !state" @click="load">{{
          t('mgsRecharge.refresh')
        }}</ElButton>
      </ElSpace>
    </header>
    <ElAlert
      v-if="failed || state?.health?.error"
      :title="failed ? t('tronScan.stale') : state?.health?.error || ''"
      type="error"
      :closable="false"
      show-icon
    />
    <ElSkeleton v-if="!state && !failed" :rows="6" animated />
    <template v-if="state">
      <div class="tron-overview">
        <section class="tron-metrics" :aria-label="t('tronScan.title')">
          <div v-for="metric in metrics" :key="metric.label" class="tron-metric">
            <ElButton v-if="metric.details" link type="primary" @click="detailsVisible = true"
              >{{ metric.label }} ↗</ElButton
            >
            <span v-else>{{ metric.label }}</span>
            <strong :class="{ 'text-warning': metric.details && state.gap_blocks }">{{
              metric.value?.toLocaleString() ?? '—'
            }}</strong>
            <small>{{ metric.hint }}</small>
          </div>
        </section>
        <BlockStream :blocks="state.recent_blocks" />
      </div>
      <RecentRecharges v-if="state.recent_recharges !== null" :rows="state.recent_recharges" />
      <ElDialog v-model="detailsVisible" :title="t('tronScan.technical')" width="min(92vw, 720px)">
        <p v-if="!Object.keys(state.gaps).length">{{ t('tronScan.noGaps') }}</p>
        <div v-for="(gap, id) in state.gaps" :key="id" class="tron-gap">
          <strong>#{{ gap.from }} – #{{ gap.to }}</strong>
          <span>{{ t('tronScan.nextBlock') }} #{{ gap.next }}</span>
          <span>{{ t('tronScan.attempts', { count: gap.attempts }) }}</span>
          <span v-if="gap.retry_time"
            >{{ t('tronScan.retryTime') }} {{ formatTime(gap.retry_time) }} · {{ timezone }}</span
          >
          <span v-if="gap.error" class="text-danger">{{ gap.error }}</span>
        </div>
        <pre>{{
          JSON.stringify(
            { checkpoint: state.checkpoint, health: state.health, gaps: state.gaps },
            (key, value) =>
              ['last_success_time', 'solid_time', 'heartbeat_time', 'retry_time'].includes(key)
                ? value
                  ? `${formatTime(value)} ${timezone}`
                  : '—'
                : value,
            2
          )
        }}</pre>
      </ElDialog>
    </template>
  </div>
</template>

<style scoped>
  .tron-page {
    display: grid;
    align-content: start;
    gap: 12px;
    min-width: 0;
  }
  .tron-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
  }
  h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
  }
  .tron-heading p {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
  .tron-heading p {
    margin: 2px 0 0;
  }
  .tron-overview {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 12px;
    align-items: stretch;
  }
  .tron-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    overflow: hidden;
    border: 1px solid var(--el-border-color-light);
    border-radius: 6px;
    background: var(--el-bg-color-overlay);
  }
  .tron-metric {
    display: grid;
    align-content: center;
    justify-items: start;
    gap: 2px;
    padding: 8px;
  }
  .tron-metric:nth-child(even) {
    border-left: 1px solid var(--el-border-color-lighter);
  }
  .tron-metric:nth-child(n + 3) {
    border-top: 1px solid var(--el-border-color-lighter);
  }
  .tron-metric > span,
  .tron-metric small,
  .tron-metric :deep(.el-button) {
    font-size: 12px;
    line-height: 16px;
  }
  .tron-metric > span,
  .tron-metric small {
    color: var(--el-text-color-secondary);
  }
  .tron-metric strong {
    font-size: 19px;
    line-height: 22px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }
  .tron-gap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    padding: 8px 0;
    font-size: 13px;
    overflow-wrap: anywhere;
  }
  pre {
    max-height: 50vh;
    overflow: auto;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 12px;
  }
  @media (max-width: 1250px) {
    .tron-overview {
      grid-template-columns: 1fr;
    }
  }
</style>
