<script setup lang="ts">
  import { useClipboard } from '@vueuse/core'
  import { useI18n } from 'vue-i18n'
  import type { TronBlock } from '@/api/mgs/recharges'

  const props = defineProps<{ blocks: TronBlock[] }>()
  const { t } = useI18n()
  const { copy, copied } = useClipboard()
  const selected = ref<number>()
  const visible = computed(() => props.blocks.slice(0, 6).reverse())
  const current = computed(
    () => visible.value.find((block) => block.height === selected.value) ?? props.blocks[0]
  )
</script>

<template>
  <ElCard class="block-card" shadow="never">
    <template #header>
      <div class="block-heading">
        <strong>{{ t('tronScan.recentBlocks') }}</strong>
        <span>{{ t('tronScan.blockOrder') }}</span>
      </div>
    </template>
    <ElEmpty v-if="!blocks.length" :description="t('tronScan.waitingBlocks')" :image-size="48" />
    <template v-else>
      <div class="block-stream">
        <button
          v-for="(block, index) in visible"
          :key="block.height"
          type="button"
          class="block-item"
          :class="{
            connected:
              index > 0 &&
              block.height === visible[index - 1].height + 1 &&
              block.parent_hash === visible[index - 1].hash,
            latest: block.height === blocks[0].height
          }"
          :aria-pressed="current?.height === block.height"
          @click="selected = block.height"
        >
          <span class="block-time">{{ block.block_time.slice(11, 19) }}</span>
          <strong>#{{ block.height }}</strong>
          <span>{{ block.transactions }} {{ t('tronScan.txShort') }}</span>
          <span :class="{ matched: block.transfers > 0 }"
            >{{ block.transfers }} {{ t('tronScan.transferShort') }}</span
          >
        </button>
      </div>
      <div v-if="current" class="block-detail">
        <ElLink
          :href="`https://tronscan.org/#/block/${current.height}`"
          target="_blank"
          rel="noopener noreferrer"
          type="primary"
          >#{{ current.height }} ↗</ElLink
        >
        <span>{{ current.duration_ms }} ms</span>
        <code>{{ current.hash.slice(0, 10) }}…{{ current.hash.slice(-8) }}</code>
        <ElButton size="small" text @click="copy(current.hash)">{{
          copied ? t('tronScan.copied') : t('tronScan.copy')
        }}</ElButton>
      </div>
    </template>
  </ElCard>
</template>

<style scoped>
  .block-heading,
  .block-detail {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
  }
  .block-heading span,
  .block-time,
  .block-detail > span,
  .block-detail code {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
  .block-stream {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
  }
  .block-item {
    position: relative;
    display: grid;
    gap: 3px;
    min-width: 0;
    padding: 8px 10px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    background: var(--el-bg-color);
    color: var(--el-text-color-primary);
    text-align: left;
    cursor: pointer;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
  }
  .block-item[aria-pressed='true'] {
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
  }
  .block-item strong {
    font-size: 13px;
  }
  .block-item.latest {
    animation: block-arrive 250ms ease-out;
  }
  @keyframes block-arrive {
    from {
      opacity: 0.6;
      transform: translateY(3px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .block-item.latest {
      animation: none;
    }
  }
  .block-item.latest strong,
  .matched {
    color: var(--el-color-primary);
  }
  .block-item.connected::before {
    position: absolute;
    top: 50%;
    left: -13px;
    width: 12px;
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
  .block-detail {
    justify-content: flex-start;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--el-border-color-lighter);
  }
  .block-detail code {
    overflow-wrap: anywhere;
  }
  @media (max-width: 900px) {
    .block-stream {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 8px;
    }
    .block-item.connected::before {
      top: 50%;
      left: -9px;
      width: 8px;
    }
    .block-item.connected::after {
      top: calc(50% - 2px);
      left: -6px;
    }
  }
  @media (max-width: 520px) {
    .block-stream {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
</style>
