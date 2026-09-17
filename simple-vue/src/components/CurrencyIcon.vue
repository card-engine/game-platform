<script setup lang="ts">
import { computed } from 'vue'
import sprite from '../assets/currencies.svg?url&no-inline'

const props = withDefaults(defineProps<{ code: string; size?: number }>(), { size: 20 })
const code = computed(() => props.code.toUpperCase())
const symbol = computed(() => ['USD', 'INR', 'EUR', 'GBP', 'JPY', 'CNY', 'USDT', 'TRX'].includes(code.value) ? code.value : 'coin')
</script>

<template>
  <svg class="currency-icon" :width="size" :height="size" viewBox="0 0 24 24" role="img" :aria-label="code" focusable="false">
    <use :href="`${sprite}#${symbol}`" />
    <text v-if="symbol === 'coin'" x="12" y="12.5" text-anchor="middle" dominant-baseline="middle" fill="white"
      font-family="Arial, sans-serif" :font-size="code.length > 3 ? 5 : 7" font-weight="700">{{ code }}</text>
  </svg>
</template>

<style scoped>
.currency-icon { display: inline-block; flex-shrink: 0; vertical-align: middle; color: #68528c; }
</style>
