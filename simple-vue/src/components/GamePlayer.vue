<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ExternalLink, RectangleHorizontal, RectangleVertical, RefreshCw, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import type { GameItem } from '../types/game'

const props = defineProps<{ url: string; game: GameItem }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
const orientation = ref<'portrait' | 'landscape'>(localStorage.getItem('mgames:game-trial:orientation') === 'landscape' ? 'landscape' : 'portrait')
const isMobile = ref(false)
const controlsExpanded = ref(false)
const frameKey = ref(0)
let collapseTimer: ReturnType<typeof setTimeout> | undefined

function updateViewport() { isMobile.value = window.matchMedia('(max-width: 767px)').matches }
function collapseControls() { controlsExpanded.value = false; clearTimeout(collapseTimer) }
function scheduleCollapse() { clearTimeout(collapseTimer); collapseTimer = setTimeout(collapseControls, 3000) }
function toggleControls() {
  controlsExpanded.value = !controlsExpanded.value
  controlsExpanded.value ? scheduleCollapse() : clearTimeout(collapseTimer)
}
function switchOrientation() {
  orientation.value = orientation.value === 'portrait' ? 'landscape' : 'portrait'
  localStorage.setItem('mgames:game-trial:orientation', orientation.value)
  frameKey.value += 1
  scheduleCollapse()
}
function refresh() { frameKey.value += 1; scheduleCollapse() }
function openExternal() { window.open(props.url, '_blank', 'noopener,noreferrer'); scheduleCollapse() }
function onDocumentPointerDown(event: PointerEvent) {
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
    <div class="game-player__safe-area" aria-hidden="true" />
    <div class="game-player__stage-wrap" :class="`is-${orientation}`">
      <div class="game-player__stage">
        <iframe :key="frameKey" class="game-player__frame" :src="url" :title="game.gameFullName || game.gameName" allow="autoplay; fullscreen" referrerpolicy="no-referrer" />
      </div>
      <div class="game-player__controls" :class="{ 'is-expanded': controlsExpanded }" @click.stop>
        <button v-if="isMobile" class="game-player__handle" type="button" :aria-label="t('player.label')" :aria-expanded="controlsExpanded" @click="toggleControls"><span /></button>
        <div class="game-player__actions" :inert="isMobile && !controlsExpanded ? true : undefined">
          <button v-if="!isMobile" class="game-player__action" type="button" :title="t('player.orientation')" :aria-label="t('player.orientation')" @click="switchOrientation">
            <RectangleVertical v-if="orientation === 'landscape'" :size="18" />
            <RectangleHorizontal v-else :size="18" />
          </button>
          <button class="game-player__action" type="button" :title="t('player.reload')" :aria-label="t('player.reload')" @click="refresh"><RefreshCw :size="18" /></button>
          <button v-if="!isMobile" class="game-player__action" type="button" :title="t('player.newWindow')" :aria-label="t('player.newWindow')" @click="openExternal"><ExternalLink :size="18" /></button>
          <button class="game-player__action game-player__action--close" type="button" :title="t('player.close')" :aria-label="t('player.close')" @click="emit('close')"><X :size="19" /></button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.game-player { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; overflow: hidden; color-scheme: dark; background: rgb(0 0 0 / 35%); }
.game-player__safe-area { display: none; }
.game-player__stage-wrap { position: relative; background: #050505; overflow: visible; }
.game-player__stage-wrap.is-landscape { width: 83vw; aspect-ratio: 16 / 9; }
.game-player__stage-wrap.is-portrait { width: min(83vw, 56.25vh); aspect-ratio: 9 / 16; }
.game-player__stage { width: 100%; height: 100%; overflow: hidden; }
.game-player__frame { display: block; width: 100%; height: 100%; opacity: 1; border: 0; background: #000; transition: none; }
.game-player__controls { position: absolute; top: 10px; left: calc(100% + 10px); z-index: 2; }
.game-player__actions { display: flex; flex-direction: column; gap: 8px; }
.game-player__action,
.game-player__handle { display: grid; width: 38px; height: 38px; place-items: center; padding: 0; color: #fff; background: rgb(20 24 28 / 88%); border: 1px solid rgb(255 255 255 / 22%); border-radius: 50%; cursor: pointer; }
.game-player__action:hover,
.game-player__handle:hover { background: #277b54; }
.game-player__action--close { color: #ffd0c8; }
.game-player.is-mobile { background: #000; }
.is-mobile .game-player__stage-wrap { width: 100vw; height: 100dvh; }
.is-mobile .game-player__stage { width: 100%; height: 100%; }
.is-mobile .game-player__frame { width: 100%; height: 100%; }
.is-mobile .game-player__controls { top: auto; right: 0; bottom: 30%; left: auto; transform: translateY(50%); }
.is-mobile .game-player__handle { width: 18px; height: 88px; border-right: 0; border-radius: 12px 0 0 12px; touch-action: manipulation; }
.is-mobile .game-player__handle span { width: 3px; height: 26px; border-radius: 2px; background: rgb(255 255 255 / 60%); }
.is-mobile .game-player__actions { position: absolute; top: 50%; right: calc(100% + 8px); gap: 10px; visibility: hidden; opacity: 0; pointer-events: none; transform: translate(12px, -50%); transition: opacity .2s ease, transform .2s ease, visibility .2s; }
.is-mobile .game-player__controls.is-expanded .game-player__actions { visibility: visible; opacity: 1; pointer-events: auto; transform: translate(0, -50%); }
.is-mobile .game-player__actions .game-player__action { width: 50px; height: 50px; }
@media (display-mode: standalone) {
  .game-player__safe-area { position: absolute; inset: 0 0 auto; z-index: 1; display: block; width: 100%; height: max(24px, env(safe-area-inset-top)); pointer-events: none; background: linear-gradient(110deg, #7557c7 0%, #3d9668 100%); }
}
</style>
