<script setup lang="ts">
  import { useGameTime } from '@/composables/useGameTime'
  import { useClipboard } from '@vueuse/core'
  import { useI18n } from 'vue-i18n'
  import type { TronBlock } from '@/api/mgs/recharges'

  const props = defineProps<{ blocks: TronBlock[] }>()
  const { t } = useI18n()
  const { timezone, formatTime } = useGameTime()
  const { copy, copied } = useClipboard()
  const visible = computed(() => props.blocks.slice(0, 6).reverse())
</script>

<template>
  <section class="blocks-panel" :aria-label="t('tronScan.recentBlocks')">
    <header
      ><strong>{{ t('tronScan.recentBlocks') }}</strong
      ><span>{{ t('tronScan.blockOrder') }} · {{ timezone }}</span></header
    >
    <ElEmpty v-if="!blocks.length" :description="t('tronScan.waitingBlocks')" :image-size="40" />
    <div v-else class="block-stream">
      <article
        v-for="(block, index) in visible"
        :key="block.height"
        class="block-item"
        :class="{
          connected:
            index > 0 &&
            block.height === visible[index - 1].height + 1 &&
            block.parent_hash === visible[index - 1].hash,
          latest: block.height === blocks[0].height
        }"
      >
        <span class="block-time"
          >{{ formatTime(block.block_time, 'time')
          }}<span v-if="block.height === blocks[0].height" class="latest-label">{{
            t('tronScan.latest')
          }}</span></span
        >
        <ElLink
          :href="`https://tronscan.org/#/block/${block.height}`"
          target="_blank"
          rel="noopener noreferrer"
          :title="block.hash"
          :underline="false"
          >#{{ block.height }} ↗</ElLink
        >
        <span>{{ t('tronScan.transactions', { count: block.transactions }) }}</span>
        <div class="block-counts"
          ><span :class="{ matched: block.transfers > 0 }">{{
            t('tronScan.transfers', { count: block.transfers })
          }}</span
          ><small v-if="block.height === blocks[0].height">{{ block.duration_ms }} ms</small></div
        >
        <template v-if="block.height === blocks[0].height">
          <div class="block-hash"
            ><code>{{ block.hash.slice(0, 6) }}…{{ block.hash.slice(-6) }}</code
            ><ElButton size="small" link type="primary" @click="copy(block.hash)">{{
              copied ? t('tronScan.copied') : t('tronScan.copy')
            }}</ElButton></div
          >
        </template>
      </article>
    </div>
  </section>
</template>

<style scoped>
  .blocks-panel {
    container-type: inline-size;
    min-width: 0;
    display: grid;
    gap: 6px;
    grid-template-rows: auto 1fr;
  }
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
    line-height: 20px;
  }
  header strong {
    font-size: 13px;
  }
  header span,
  .block-time,
  small,
  code {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
  .block-stream {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr)) minmax(172px, 1.55fr);
    grid-auto-rows: 1fr;
    gap: 10px;
    align-items: stretch;
  }
  .block-item {
    position: relative;
    min-width: 0;
    display: grid;
    align-content: start;
    gap: 4px;
    padding: 8px;
    border: 1px solid var(--el-border-color-light);
    border-radius: 6px;
    background: var(--el-bg-color-overlay);
    font-size: 12px;
    line-height: 18px;
    font-variant-numeric: tabular-nums;
  }
  .block-item :deep(.el-link) {
    justify-content: flex-start;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
  }
  .block-item.latest {
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
  }
  .latest :deep(.el-link),
  .latest-label {
    color: var(--el-color-primary);
  }
  .block-time,
  .block-counts,
  .block-hash {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
  }
  .block-hash {
    border-top: 1px solid var(--el-border-color-lighter);
    padding-top: 5px;
  }
  .block-hash code {
    white-space: nowrap;
  }
  .block-hash :deep(.el-button) {
    font-size: 12px;
  }
  .matched {
    color: var(--el-color-success);
  }
  .block-item.connected::before {
    position: absolute;
    top: 50%;
    left: -11px;
    width: 10px;
    height: 1px;
    content: '';
    background: var(--el-border-color);
  }
  .block-item.connected::after {
    position: absolute;
    top: calc(50% - 2px);
    left: -8px;
    width: 4px;
    height: 4px;
    content: '';
    border-radius: 50%;
    background: var(--el-text-color-placeholder);
  }
  @container (max-width: 720px) {
    .block-stream {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .block-item:nth-child(odd)::before,
    .block-item:nth-child(odd)::after {
      display: none;
    }
  }
  @container (max-width: 340px) {
    .block-hash {
      flex-wrap: wrap;
    }
  }
</style>
