<script setup lang="ts">
import PwaInstallButton from './components/PwaInstallButton.vue'
</script>

<template>
  <RouterView v-slot="{ Component }">
    <Suspense><component :is="Component" /></Suspense>
  </RouterView>
  <PwaInstallButton />
</template>
