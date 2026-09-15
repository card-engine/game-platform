<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ExternalLink, RefreshCw, RotateCw, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import type { GameItem } from '../types/game'

defineProps<{ url: string; game: GameItem }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
const orientation = ref<'portrait' | 'landscape'>(localStorage.getItem('mgames:game-trial:orientation') === 'landscape' ? 'landscape' : 'portrait')
const isMobile = ref(false)
const controlsExpanded = ref(false)
const frameKey = ref(0)
const handleTop = ref(30)
const dragging = ref(false)
let dragOffset = 0
let collapseTimer: ReturnType<typeof setTimeout> | undefined

const updateViewport = () => {
  isMobile.value = window.matchMedia('(max-width: 767px)').matches
  if (!localStorage.getItem('mgames:game-trial:orientation') && isMobile.value) orientation.value = window.innerWidth > window.innerHeight ? 'landscape' : 'portrait'
}
const collapseControls = () => {
  controlsExpanded.value = false
  clearTimeout(collapseTimer)
}
const scheduleCollapse = () => {
  clearTimeout(collapseTimer)
  collapseTimer = setTimeout(collapseControls, 3000)
}
const toggleControls = () => {
  controlsExpanded.value = !controlsExpanded.value
  controlsExpanded.value ? scheduleCollapse() : clearTimeout(collapseTimer)
}
const startDrag = (event: PointerEvent) => {
  if (!isMobile.value) return
  dragging.value = true
  dragOffset = event.clientY - (window.innerHeight * handleTop.value / 100)
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}
const drag = (event: PointerEvent) => {
  if (dragging.value) handleTop.value = Math.min(92, Math.max(8, (event.clientY - dragOffset) / window.innerHeight * 100))
}
const endDrag = () => { dragging.value = false }
const switchOrientation = () => {
  orientation.value = orientation.value === 'portrait' ? 'landscape' : 'portrait'
  localStorage.setItem('mgames:game-trial:orientation', orientation.value)
  frameKey.value += 1
  scheduleCollapse()
}
const refresh = () => {
  frameKey.value += 1
  scheduleCollapse()
}
const openExternal = () => {
  const url = (document.querySelector<HTMLIFrameElement>('.game-player__frame')?.src || '')
  if (url) window.open(url, '_blank', 'noopener,noreferrer')
  scheduleCollapse()
}
const onDocumentPointerDown = (event: PointerEvent) => {
  if (controlsExpanded.value && !(event.target as HTMLElement).closest('.game-player__controls')) collapseControls()
}
onMounted(() => {
  updateViewport()
  window.addEventListener('resize', updateViewport)
  document.addEventListener('pointerdown', onDocumentPointerDown)
})
onBeforeUnmount(() => {
  clearTimeout(collapseTimer)
  window.removeEventListener('resize', updateViewport)
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})
</script>

<template>
  <div class="game-player" :class="{ 'is-mobile': isMobile }" @click="collapseControls">
    <div class="game-player__stage-wrap" :class="`is-${orientation}`">
      <div class="game-player__stage">
        <iframe :key="frameKey" class="game-player__frame" :src="url" :title="game.gameFullName || game.gameName" allow="autoplay; fullscreen" referrerpolicy="no-referrer" />
      </div>
      <div class="game-player__controls" :class="{ 'is-expanded': controlsExpanded }" @click.stop>
        <button v-if="isMobile" class="game-player__handle" :style="{ top: `${handleTop}%` }" type="button" :aria-label="t('player.label')" @click="toggleControls" @pointerdown="startDrag" @pointermove="drag" @pointerup="endDrag" @pointercancel="endDrag"><span /></button>
        <div class="game-player__actions" :style="isMobile ? { top: `${handleTop}%`, transform: 'translateY(-50%)' } : undefined">
          <button v-if="!isMobile" class="game-player__action" type="button" :title="t('player.orientation')" :aria-label="t('player.orientation')" @click="switchOrientation"><RotateCw :size="18" /></button>
          <button class="game-player__action" type="button" :title="t('player.reload')" :aria-label="t('player.reload')" @click="refresh"><RefreshCw :size="18" /></button>
          <button v-if="!isMobile" class="game-player__action" type="button" :title="t('player.newWindow')" :aria-label="t('player.newWindow')" @click="openExternal"><ExternalLink :size="18" /></button>
          <button class="game-player__action game-player__action--close" type="button" :title="t('player.close')" :aria-label="t('player.close')" @click="emit('close')"><X :size="19" /></button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.game-player { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; overflow: visible; color-scheme: dark; background: rgb(0 0 0 / 35%); }
.game-player__stage-wrap { position: relative; background: #050505; overflow: visible; }
.game-player__stage-wrap.is-landscape { width: 83vw; aspect-ratio: 16 / 9; }
.game-player__stage-wrap.is-portrait { width: min(83vw, 56.25vh); aspect-ratio: 9 / 16; }
.game-player__stage { width: 100%; height: 100%; overflow: hidden; }
.game-player__frame { display: block; width: 100%; height: 100%; opacity: 1; border: 0; background: #000; transition: none; }
.game-player__controls { position: absolute; top: 8px; left: calc(100% + 8px); z-index: 1; width: auto; height: auto; transform: none; transition: none; }
.game-player__actions { display: flex; flex-direction: column; gap: 8px; }
.game-player__action, .game-player__handle { position: static; display: grid; place-items: center; width: 36px; height: 36px; padding: 0; color: #fff; background: rgb(20 24 28 / 85%); border: 1px solid rgb(255 255 255 / 20%); border-radius: 6px; cursor: pointer; transform: none; transition: none; }
.game-player__handle span { width: 3px; height: 22px; border-radius: 3px; background: currentColor; }
.game-player__action:hover, .game-player__handle:hover { background: rgb(38 123 84 / 90%); }
.game-player__action--close { color: #ffb4b4; }
.game-player.is-mobile { background: #000; }
.is-mobile .game-player__stage-wrap { width: 100vw; height: 100vh; }
.is-mobile .game-player__stage { display: grid; place-items: center; }
.is-mobile .game-player__frame { width: min(100vw, 177.78vh); height: auto; aspect-ratio: 16 / 9; max-height: 100%; }
.is-mobile .is-portrait .game-player__frame { width: min(56.25vh, 100vw); aspect-ratio: 9 / 16; }
.is-mobile .game-player__controls { top: 0; right: 0; left: auto; height: 100%; }
.is-mobile .game-player__handle { position: absolute; right: 0; width: 24px; height: 64px; border-radius: 12px 0 0 12px; touch-action: none; }
.is-mobile .game-player__actions { position: absolute; top: 0; right: 44px; display: none; }
.is-mobile .game-player__controls.is-expanded .game-player__actions { display: flex; }
</style>
