<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { ArrowUp, Download } from '@lucide/vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import logoImage from '../assets/images/logo.webp'
import { getIosBrowser, isPwaStandalone, type BeforeInstallPromptEvent } from '../pwa'
import PwaIosInstallGuide from './PwaIosInstallGuide.vue'

const { t } = useI18n()
const installed = ref(isPwaStandalone())
const guideOpen = ref(false)
const showBackToTop = ref(false)
const promptEvent = shallowRef<BeforeInstallPromptEvent>()
const iosBrowser = getIosBrowser(navigator.userAgent, navigator.platform, navigator.maxTouchPoints)

function capturePrompt(event: Event) {
  event.preventDefault()
  promptEvent.value = event as BeforeInstallPromptEvent
}

async function install() {
  if (iosBrowser) {
    guideOpen.value = true
    return
  }

  if (!promptEvent.value) {
    ElMessage.info(t('pwa.unavailable'))
    return
  }

  const event = promptEvent.value
  await event.prompt()
  await event.userChoice
  promptEvent.value = undefined
}

function markInstalled() {
  installed.value = true
  promptEvent.value = undefined
  ElMessage.success(t('pwa.installed'))
}

function updateBackToTop() {
  showBackToTop.value = window.scrollY > 500
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
  window.addEventListener('beforeinstallprompt', capturePrompt)
  window.addEventListener('appinstalled', markInstalled)
  window.addEventListener('scroll', updateBackToTop, { passive: true })
  updateBackToTop()
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeinstallprompt', capturePrompt)
  window.removeEventListener('appinstalled', markInstalled)
  window.removeEventListener('scroll', updateBackToTop)
})
</script>

<template>
  <Teleport to="body">
    <div class="floating-actions">
      <el-tooltip v-if="!installed" :content="t('pwa.install')" placement="left">
        <button class="pwa-install-button" type="button" :aria-label="t('pwa.install')" @click="install">
          <img :src="logoImage" alt="" />
          <span class="pwa-install-button__download"><Download :size="30" stroke-width="2.3" /></span>
        </button>
      </el-tooltip>

      <el-tooltip v-if="showBackToTop" :content="t('pwa.backToTop')" placement="left">
        <button
          class="back-to-top-button"
          type="button"
          :aria-label="t('pwa.backToTop')"
          @click="scrollToTop"
        >
          <ArrowUp :size="20" stroke-width="2.5" />
        </button>
      </el-tooltip>
    </div>
  </Teleport>

  <PwaIosInstallGuide v-if="iosBrowser" v-model="guideOpen" :browser="iosBrowser" />
</template>

<style scoped>
.floating-actions {
  position: fixed;
  right: max(18px, env(safe-area-inset-right));
  bottom: max(18px, env(safe-area-inset-bottom));
  z-index: 120;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.pwa-install-button {
  position: relative;
  width: 58px;
  height: 58px;
  padding: 3px;
  overflow: hidden;
  background: #071b1b;
  border: 1px solid rgba(211, 182, 93, 0.72);
  border-radius: 50%;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.32), 0 0 0 5px rgba(38, 123, 84, 0.13);
  cursor: pointer;
  animation: pwa-install-pulse 2.8s ease-in-out infinite;
}

.back-to-top-button {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  padding: 0;
  color: var(--accent);
  background: color-mix(in srgb, var(--surface) 86%, transparent);
  border: 1px solid var(--line);
  border-radius: 50%;
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.22);
  cursor: pointer;
  -webkit-backdrop-filter: blur(8px);
  backdrop-filter: blur(8px);
}

.pwa-install-button img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 50%;
}

.pwa-install-button__download {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: rgba(255, 255, 255, 0.8);
  background: rgba(4, 18, 14, 0.52);
  border-radius: inherit;
  opacity: 0;
  transition: opacity 160ms ease;
  pointer-events: none;
  -webkit-backdrop-filter: blur(3px);
  backdrop-filter: blur(3px);
}

.pwa-install-button:hover {
  transform: translateY(-2px);
}

.pwa-install-button:hover .pwa-install-button__download,
.pwa-install-button:focus-visible .pwa-install-button__download,
.pwa-install-button:active .pwa-install-button__download {
  opacity: 1;
}

.back-to-top-button:hover {
  color: var(--accent-contrast);
  background: var(--accent);
  transform: translateY(-2px);
}

.pwa-install-button:focus-visible,
.back-to-top-button:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 48%, transparent);
  outline-offset: 3px;
}

@keyframes pwa-install-pulse {
  0%, 100% { box-shadow: 0 10px 28px rgba(0, 0, 0, 0.32), 0 0 0 5px rgba(38, 123, 84, 0.12); }
  50% { box-shadow: 0 12px 32px rgba(0, 0, 0, 0.38), 0 0 0 9px rgba(38, 123, 84, 0.04); }
}

@media (max-width: 760px) {
  .floating-actions {
    right: max(14px, env(safe-area-inset-right));
    bottom: calc(84px + env(safe-area-inset-bottom));
    gap: 8px;
  }

  .pwa-install-button {
    width: 52px;
    height: 52px;
  }

  .back-to-top-button {
    width: 40px;
    height: 40px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pwa-install-button {
    animation: none;
  }
}
</style>
