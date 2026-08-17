<template>
  <svg
    :style="colors"
    class="game-default-icon"
    viewBox="16 16 96 96"
    aria-hidden="true"
    focusable="false"
  >
    <path
      fill="currentColor"
      fill-rule="evenodd"
      d="M20 86 27 44c2-10 10-16 20-16h6c4 0 6 15 11 15s7-15 11-15h6c10 0 18 6 20 16l7 42c2 11-11 18-19 10L77 84H51L39 96c-8 8-21 1-19-10ZM37 53h8v8h8v8h-8v8h-8v-8h-8v-8h8v-8Zm41 12a5 5 0 1 0 0 10 5 5 0 1 0 0-10Z"
    />
    <circle cx="89" cy="59" r="7" fill="#c7ff3d" />
  </svg>
</template>

<script setup lang="ts">
  import { useSettingStore } from '@/store/modules/setting'

  const props = defineProps<{ id: number }>()
  const { isDark } = storeToRefs(useSettingStore())
  const colors = computed(() => {
    const hue = (props.id * 137.508) % 360
    return {
      color: `hsl(${hue} ${isDark.value ? '68% 72%' : '58% 34%'})`,
      backgroundColor: `hsl(${hue} ${isDark.value ? '32% 18%' : '60% 92%'})`,
      borderColor: `hsl(${hue} ${isDark.value ? '28% 30%' : '38% 82%'})`
    }
  })
</script>

<style scoped>
  .game-default-icon {
    display: block;
    box-sizing: border-box;
    padding: 2px;
    border: 1px solid;
    border-radius: 5px;
  }
</style>
