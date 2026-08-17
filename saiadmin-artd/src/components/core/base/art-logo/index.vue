<!-- 系统logo -->
<template>
  <div class="flex-cc">
    <svg :style="logoStyle" class="art-logo" viewBox="0 0 128 128" role="img" aria-label="MGames">
      <path
        fill="currentColor"
        fill-rule="evenodd"
        d="M20 86 27 44c2-10 10-16 20-16h6c4 0 6 15 11 15s7-15 11-15h6c10 0 18 6 20 16l7 42c2 11-11 18-19 10L77 84H51L39 96c-8 8-21 1-19-10ZM37 53h8v8h8v8h-8v8h-8v-8h-8v-8h8v-8Zm41 12a5 5 0 1 0 0 10 5 5 0 1 0 0-10Z"
      />
      <circle cx="89" cy="59" r="7" fill="#c7ff3d" />
    </svg>
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'ArtLogo' })

  interface Props {
    /** logo 大小 */
    size?: number | string
  }

  const props = withDefaults(defineProps<Props>(), {
    size: 36
  })

  const logoStyle = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }))
</script>

<style scoped>
  .art-logo {
    display: block;
    color: var(--theme-color);
    transition: color 0.2s ease;
  }
</style>
