<script setup lang="ts">
import PwaInstallButton from './components/PwaInstallButton.vue'
import LobbyView from './views/LobbyView.vue'
</script>

<template>
  <Suspense>
    <LobbyView />
  </Suspense>
  <PwaInstallButton />
</template>
