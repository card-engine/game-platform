<script setup lang="ts">
  import { useDocumentVisibility, useIntervalFn } from '@vueuse/core'
  import { useI18n } from 'vue-i18n'
  import api, { type TronStatus } from '@/api/mgs/recharges'
  import BlockStream from './modules/block-stream.vue'
  import RecentRecharges from './modules/recent-recharges.vue'

  const { t } = useI18n()
  const state = ref<TronStatus>()
  const busy = ref(false)
  const failed = ref(false)
  const active = ref(true)
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
        ? new Date(state.value.health.solid_time * 1000).toISOString().slice(11, 19) + ' UTC'
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
      <div
        ><h2>{{ t('tronScan.title') }}</h2
        ><p>TRON MAINNET · {{ t('tronScan.subtitle') }}</p></div
      >
      <ElSpace wrap>
        <ElTag
          :type="failed || state?.health?.error ? 'danger' : state?.ready ? 'success' : 'warning'"
          >{{ statusText }}</ElTag
        >
        <span class="tron-muted">{{ t('tronScan.polling') }}</span>
        <ElButton :loading="busy && !state" @click="load">{{ t('mgsRecharge.refresh') }}</ElButton>
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
      <ElCard shadow="never">
        <div class="tron-metrics"
          ><div v-for="metric in metrics" :key="metric.label"
            ><span>{{ metric.label }}</span
            ><strong>{{ metric.value?.toLocaleString() ?? '—' }}</strong
            ><small>{{ metric.hint }}</small></div
          ></div
        >
      </ElCard>
      <BlockStream :blocks="state.recent_blocks" />
      <RecentRecharges v-if="state.recent_recharges !== null" :rows="state.recent_recharges" />
      <ElCard v-if="Object.keys(state.gaps).length" shadow="never">
        <template #header>{{ t('tronScan.backfill') }}</template>
        <div v-for="(gap, id) in state.gaps" :key="id" class="tron-gap">
          <strong>#{{ gap.from }} – #{{ gap.to }}</strong>
          <span>{{ t('tronScan.nextBlock') }} #{{ gap.next }}</span>
          <span>{{ t('tronScan.attempts', { count: gap.attempts }) }}</span>
          <span v-if="gap.retry_time"
            >{{ t('tronScan.retryTime') }}
            {{ new Date(gap.retry_time * 1000).toISOString().replace('T', ' ').slice(0, 19) }}
            UTC</span
          >
          <span v-if="gap.error" class="text-danger">{{ gap.error }}</span>
        </div>
      </ElCard>
      <footer class="tron-footer">
        <span v-if="!Object.keys(state.gaps).length">{{ t('tronScan.noGaps') }}</span>
        <details
          ><summary>{{ t('tronScan.technical') }}</summary
          ><pre>{{
            JSON.stringify(
              { checkpoint: state.checkpoint, health: state.health, gaps: state.gaps },
              null,
              2
            )
          }}</pre>
        </details>
        <span
          >{{ t('tronScan.updated') }}
          {{ new Date(state.server_time * 1000).toISOString().slice(11, 19) }} UTC</span
        >
      </footer>
    </template>
  </div>
</template>

<style scoped>
  .tron-page {
    display: grid;
    gap: 12px;
  }
  .tron-heading,
  .tron-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
  }
  h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
  }
  .tron-heading p,
  .tron-muted,
  .tron-footer {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
  .tron-heading p {
    margin: 2px 0 0;
  }
  .tron-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0;
  }
  .tron-metrics > div {
    display: grid;
    gap: 2px;
    padding: 0 14px;
    border-left: 1px solid var(--el-border-color-lighter);
  }
  .tron-metrics > div:first-child {
    padding-left: 0;
    border-left: 0;
  }
  .tron-metrics span,
  .tron-metrics small {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .tron-metrics strong {
    font-size: clamp(17px, 1.8vw, 24px);
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
  :deep(.el-card__header) {
    padding: 10px 14px;
  }
  :deep(.el-card__body) {
    padding: 12px 14px;
  }
  details {
    max-width: 100%;
  }
  summary {
    cursor: pointer;
  }
  pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  @media (max-width: 640px) {
    .tron-metrics {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .tron-metrics > div:nth-child(odd) {
      padding-left: 0;
      border-left: 0;
    }
  }
</style>
