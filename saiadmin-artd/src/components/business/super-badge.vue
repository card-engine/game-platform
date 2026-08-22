<template>
  <span class="super-badge" :class="{ 'super-badge-inline': inline }">
    {{ $t('game.superAdmin') }}
  </span>
</template>

<script setup lang="ts">
  withDefaults(defineProps<{ inline?: boolean }>(), { inline: false })
</script>

<style scoped>
  .super-badge {
    position: absolute;
    top: 0;
    right: 0;
    z-index: 2;
    padding: 0 4px;
    border: 0;
    border-radius: 3px;
    background: var(--el-color-danger);
    box-shadow: 0 2px 5px rgb(0 0 0 / 18%);
    color: #fff;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0;
    line-height: 14px;
    transform: translate(55%, -75%) rotate(12deg);
    transform-origin: center;
  }

  .super-badge-inline {
    position: static;
    display: inline-block;
    margin-left: 6px;
    padding: 0 4px;
    line-height: 16px;
    vertical-align: middle;
    transform: none;
  }
</style>
