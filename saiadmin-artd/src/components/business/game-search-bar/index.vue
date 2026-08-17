<template>
  <SaSearchBar v-model="model" class="game-search-bar" v-bind="$attrs">
    <slot />
  </SaSearchBar>
</template>

<script setup lang="ts">
  defineOptions({ inheritAttrs: false, name: 'GameSearchBar' })

  const model = defineModel<Record<string, any>>({ default: () => ({}) })
</script>

<style lang="scss" scoped>
  .game-search-bar {
    :deep(.el-row) {
      align-items: flex-start;
    }

    :deep(.game-search-item) {
      flex: 0 0 var(--game-search-width, 210px) !important;
      max-width: var(--game-search-width, 210px) !important;
    }

    :deep(.game-search-item--month) {
      --game-search-width: 170px;
    }

    :deep(.game-search-item--date) {
      --game-search-width: 300px;
    }

    :deep(.game-search-item--status) {
      --game-search-width: 150px;
    }

    :deep(.game-search-item--keyword) {
      --game-search-width: 220px;
    }

    :deep(.game-search-item--select) {
      --game-search-width: 210px;
    }

    :deep(.game-search-item--platform) {
      --game-search-width: 185px;
    }

    :deep(.game-search-item .el-select),
    :deep(.game-search-item .el-input),
    :deep(.game-search-item .el-date-editor) {
      width: 100%;
    }

    :deep(.action-column) {
      flex: 1 1 180px;
      max-width: none !important;
      min-width: 170px;
    }
  }

  @media (max-width: 1199px) {
    .game-search-bar {
      :deep(.game-search-item) {
        flex-basis: calc(50% - 12px) !important;
        max-width: calc(50% - 12px) !important;
      }

      :deep(.action-column) {
        flex-basis: 100%;
      }
    }
  }

  @media (max-width: 767px) {
    .game-search-bar {
      :deep(.game-search-item),
      :deep(.action-column) {
        flex-basis: 100% !important;
        max-width: 100% !important;
      }
    }
  }
</style>
