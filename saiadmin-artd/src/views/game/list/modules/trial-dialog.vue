<template>
  <ElDialog
    v-model="visible"
    class="game-trial-dialog"
    :width="isMobile ? '100%' : '83vw'"
    :fullscreen="isMobile"
    :show-close="false"
    :close-on-click-modal="false"
    :close-on-press-escape="true"
    append-to-body
  >
    <div class="game-trial-shell" @click="collapseControls">
      <div class="game-trial-stage" :class="`is-${orientation}`">
        <div v-if="frameLoading" class="game-trial-loading" aria-hidden="true">
          <ElIcon class="is-loading" :size="32"><Loading /></ElIcon>
        </div>
        <iframe
          v-if="src"
          :key="frameKey"
          :src="src"
          class="game-trial-frame"
          title="Game trial"
          allow="autoplay; fullscreen"
          referrerpolicy="no-referrer"
          @load="frameLoading = false"
        />
      </div>

      <div class="game-trial-controls" :class="{ 'is-expanded': controlsExpanded }" @click.stop>
        <button
          type="button"
          class="game-trial-handle"
          :aria-label="$t('game.trialControls')"
          @click="toggleControls"
        >
          <ArtSvgIcon icon="ri:more-2-fill" />
        </button>
        <div class="game-trial-actions">
          <ElTooltip :content="$t('game.switchOrientation')" placement="left">
            <button type="button" class="game-trial-action" @click="switchOrientation">
              <ArtSvgIcon
                :icon="orientation === 'portrait' ? 'ri:landscape-line' : 'ri:smartphone-line'"
              />
            </button>
          </ElTooltip>
          <ElTooltip :content="$t('game.refreshTrial')" placement="left">
            <button type="button" class="game-trial-action" @click="refreshFrame">
              <ArtSvgIcon icon="ri:refresh-line" />
            </button>
          </ElTooltip>
          <ElTooltip :content="$t('game.openTrialWindow')" placement="left">
            <button type="button" class="game-trial-action" @click="openWindow">
              <ArtSvgIcon icon="ri:external-link-line" />
            </button>
          </ElTooltip>
          <ElTooltip :content="$t('game.closeTrial')" placement="left">
            <button type="button" class="game-trial-action" @click="visible = false">
              <ArtSvgIcon icon="ri:close-line" />
            </button>
          </ElTooltip>
        </div>
      </div>
    </div>
  </ElDialog>
</template>

<script setup lang="ts">
  import { Loading } from '@element-plus/icons-vue'

  const props = defineProps<{ src: string }>()
  const visible = defineModel<boolean>({ default: false })
  const orientation = ref<'portrait' | 'landscape'>('portrait')
  const controlsExpanded = ref(false)
  const frameLoading = ref(true)
  const frameKey = ref(0)
  const isMobile = ref(false)
  let collapseTimer: ReturnType<typeof setTimeout> | undefined

  const updateMobile = () => {
    isMobile.value = window.matchMedia('(max-width: 767px)').matches
  }
  const scheduleCollapse = () => {
    clearTimeout(collapseTimer)
    collapseTimer = setTimeout(() => {
      controlsExpanded.value = false
    }, 3000)
  }
  const collapseControls = () => {
    controlsExpanded.value = false
    clearTimeout(collapseTimer)
  }
  const toggleControls = () => {
    controlsExpanded.value = !controlsExpanded.value
    if (controlsExpanded.value) scheduleCollapse()
    else clearTimeout(collapseTimer)
  }
  const switchOrientation = () => {
    orientation.value = orientation.value === 'portrait' ? 'landscape' : 'portrait'
    scheduleCollapse()
  }
  const refreshFrame = () => {
    frameLoading.value = true
    frameKey.value += 1
    scheduleCollapse()
  }
  const openWindow = () => {
    if (props.src) window.open(props.src, '_blank', 'noopener,noreferrer')
    scheduleCollapse()
  }
  const onDocumentPointerDown = (event: PointerEvent) => {
    if (!controlsExpanded.value) return
    const target = event.target as HTMLElement
    if (!target.closest('.game-trial-controls')) collapseControls()
  }

  watch(visible, (value) => {
    if (value) {
      updateMobile()
      frameLoading.value = true
      controlsExpanded.value = false
    } else {
      collapseControls()
    }
  })
  watch(
    () => props.src,
    (value) => {
      if (value) {
        frameLoading.value = true
        frameKey.value += 1
      }
    }
  )
  onMounted(() => {
    updateMobile()
    window.addEventListener('resize', updateMobile)
    document.addEventListener('pointerdown', onDocumentPointerDown)
  })
  onBeforeUnmount(() => {
    clearTimeout(collapseTimer)
    window.removeEventListener('resize', updateMobile)
    document.removeEventListener('pointerdown', onDocumentPointerDown)
  })
</script>

<style lang="scss">
  .game-trial-dialog {
    height: 83vh;
    max-height: 83vh;
    margin: 8.5vh auto 0;
    padding: 0;
    overflow: hidden;
    background: transparent !important;
    border-radius: 0;
    box-shadow: none;

    .el-dialog__header {
      display: none;
    }

    .el-dialog__body {
      height: 100%;
      padding: 0;
      background: transparent;
    }

    &.is-fullscreen {
      position: fixed;
      inset: 0;
      height: 100vh;
      max-height: 100vh;
      width: auto !important;
      margin: 0;
    }
  }

  .game-trial-shell,
  .game-trial-stage {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: #050505;
  }

  .game-trial-stage {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .game-trial-frame {
    display: block;
    max-width: 100%;
    max-height: 100%;
    border: 0;
    background: #000;
  }

  .game-trial-stage.is-portrait .game-trial-frame {
    width: auto;
    height: 100%;
    aspect-ratio: 9 / 16;
  }

  .game-trial-stage.is-landscape .game-trial-frame {
    width: 100%;
    height: auto;
    aspect-ratio: 16 / 9;
  }

  .game-trial-loading {
    position: absolute;
    inset: 0;
    z-index: 1;
    display: grid;
    place-items: center;
    color: rgb(255 255 255 / 80%);
    pointer-events: none;
  }

  .game-trial-controls {
    position: absolute;
    top: auto;
    bottom: 20%;
    right: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    transform: translateY(50%);
  }

  .game-trial-handle,
  .game-trial-action {
    display: grid;
    place-items: center;
    border: 1px solid rgb(255 255 255 / 35%);
    color: rgb(255 255 255 / 90%);
    background: rgb(15 15 15 / 88%);
    cursor: pointer;
    transition:
      background-color 0.2s ease,
      border-color 0.2s ease;

    &:hover {
      border-color: rgb(255 255 255 / 70%);
      background: rgb(45 45 45 / 95%);
    }
  }

  .game-trial-handle {
    width: 14px;
    height: 76px;
    border-right: 0;
    border-radius: 10px 0 0 10px;
    font-size: 14px;
  }

  .game-trial-actions {
    display: flex;
    flex-direction: column;
    order: -1;
    gap: 10px;
    width: 0;
    overflow: hidden;
    opacity: 0;
    transform: translateX(12px);
    transition:
      width 0.2s ease,
      opacity 0.2s ease,
      transform 0.2s ease;
  }

  .game-trial-controls.is-expanded .game-trial-actions {
    width: 54px;
    margin-right: 12px;
    opacity: 1;
    transform: translateX(0);
  }

  .game-trial-action {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    font-size: 22px;
  }

  @media (max-width: 767px) {
    .game-trial-handle {
      width: 18px;
      height: 88px;
    }

    .game-trial-controls.is-expanded .game-trial-actions {
      margin-right: 8px;
    }
  }
</style>
