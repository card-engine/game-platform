<template>
  <div class="document-page art-full-height">
    <div class="document-toolbar">
      <span>{{ $t('game.merchantParam') }}</span>
      <ElSelect
        v-model="merchantId"
        filterable
        :loading="loading"
        :placeholder="$t('game.selectMerchant')"
        @change="load"
      >
        <ElOption v-for="item in merchants" :key="item.id" :label="item.label" :value="item.id" />
      </ElSelect>
      <span class="document-tip">{{ $t('game.documentTip') }}</span>
    </div>
    <ElAlert
      v-if="notice"
      :title="notice"
      type="warning"
      :closable="false"
      show-icon
      class="document-notice"
    />
    <MdPreview
      editor-id="merchant-document"
      :model-value="content"
      :theme="settingStore.isDark ? 'dark' : 'light'"
      preview-theme="github"
    />
  </div>
</template>

<script setup lang="ts">
  import { MdPreview } from 'md-editor-v3'
  import 'md-editor-v3/lib/preview.css'
  import { useSettingStore } from '@/store/modules/setting'
  import api from '@/api/game/document'
  import { mittBus } from '@/utils/sys'

  const settingStore = useSettingStore()
  const content = ref('')
  const merchantId = ref<number>()
  const merchants = ref<{ id: number; label: string }[]>([])
  const loading = ref(false)
  const notice = ref('')

  const load = async () => {
    loading.value = true
    try {
      const data = await api.read(merchantId.value)
      content.value = data.content
      merchantId.value = data.merchant_id
      merchants.value = data.merchants
      notice.value = data.notice || ''
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    load()
    mittBus.on('gameMerchantChanged', load)
  })
  onUnmounted(() => mittBus.off('gameMerchantChanged', load))
</script>

<style lang="scss" scoped>
  .document-page {
    overflow: auto;
    background: var(--default-bg-color);
  }

  .document-toolbar {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 52px;
    padding: 8px 24px;
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-light);

    .el-select {
      width: min(360px, 50vw);
    }
  }

  .document-tip {
    color: var(--art-gray-500);
    font-size: 12px;
  }

  .document-notice {
    max-width: 1100px;
    margin: 16px auto 0;
  }

  :deep(.md-editor-preview-wrapper) {
    max-width: 1100px;
    min-height: 100%;
    margin: 0 auto;
    padding: 24px 40px 48px;
  }

  @media (max-width: 767px) {
    .document-toolbar {
      flex-wrap: wrap;
      padding: 10px 16px;

      .el-select {
        width: calc(100% - 76px);
      }

      .document-tip {
        width: 100%;
      }
    }

    :deep(.md-editor-preview-wrapper) {
      padding: 16px 18px 32px;
    }
  }
</style>
