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
  <ElCard shadow="never">
    <template #header
      ><div class="block-heading"
        ><strong>{{ t('tronScan.recentBlocks') }}</strong
        ><span>{{ t('tronScan.blockOrder') }}</span></div
      ></template
    >
    <ElEmpty v-if="!blocks.length" :description="t('tronScan.waitingBlocks')" :image-size="64" />
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
          <small>{{ block.block_time.slice(11, 19) }} UTC</small>
          <strong>#{{ block.height }}</strong>
          <span>{{ t('tronScan.transactions', { count: block.transactions }) }}</span>
          <small :class="{ matched: block.transfers > 0 }">{{
            t('tronScan.transfers', { count: block.transfers })
          }}</small>
          <small v-if="block.height === blocks[0].height">{{ t('tronScan.latest') }}</small>
        </button>
      </div>
      <div v-if="current" class="block-detail">
        <div class="block-heading"
          ><ElLink
            :href="`https://tronscan.org/#/block/${current.height}`"
            target="_blank"
            rel="noopener noreferrer"
            type="primary"
            >#{{ current.height }} ↗</ElLink
          ><span>{{ t('tronScan.processed') }} · {{ current.duration_ms }} ms</span></div
        >
        <div class="block-hash"
          ><code>{{ current.hash }}</code
          ><ElButton size="small" text @click="copy(current.hash)">{{
            copied ? t('tronScan.copied') : t('tronScan.copy')
          }}</ElButton></div
        >
        <small>{{ t('tronScan.transferHint') }}</small>
      </div>
    </template>
  </ElCard>
</template>

<style scoped>
  .block-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
  }
  .block-heading span,
  small {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
  .block-stream {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 20px;
  }
  .block-item {
    position: relative;
    display: grid;
    align-content: start;
    gap: 7px;
    padding: 14px 10px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
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
      transform: translateY(4px);
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
  .block-item.latest strong {
    color: var(--el-color-primary);
  }
  .block-item.connected::before {
    position: absolute;
    top: 50%;
    left: -21px;
    width: 20px;
    height: 1px;
    content: '';
    background: var(--el-border-color);
  }
  .block-item.connected::after {
    position: absolute;
    top: calc(50% - 2px);
    left: -13px;
    width: 5px;
    height: 5px;
    content: '';
    border-radius: 50%;
    background: var(--el-text-color-placeholder);
  }
  .matched {
    color: var(--el-color-success);
  }
  .block-detail {
    display: grid;
    gap: 8px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--el-border-color-lighter);
  }
  .block-hash {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  code {
    font-size: 12px;
    overflow-wrap: anywhere;
    min-width: 0;
  }
  @media (max-width: 900px) {
    .block-stream {
      grid-template-columns: 1fr;
      gap: 14px;
    }
    .block-item {
      grid-template-columns: 1fr 1fr;
      padding: 12px 16px;
    }
    .block-item.connected::before {
      top: -15px;
      left: 28px;
      width: 1px;
      height: 14px;
    }
    .block-item.connected::after {
      top: -10px;
      left: 26px;
    }
  }
</style>
