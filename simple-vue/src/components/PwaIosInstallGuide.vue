<script setup lang="ts">
import { computed } from 'vue'
import {
  BookOpen,
  ChevronLeft,
  ChevronRight,
  CircleCheck,
  Copy,
  MousePointer2,
  SquareArrowUp,
  SquarePlus,
  X,
} from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import logoImage from '../assets/images/logo.webp'
import type { IosBrowser } from '../pwa'

const props = defineProps<{ modelValue: boolean; browser: IosBrowser }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
const { t } = useI18n()
const steps = computed(() => props.browser === 'chrome'
  ? ['pwa.chromeShare', 'pwa.chromeAdd', 'pwa.confirm']
  : ['pwa.safariShare', 'pwa.safariAdd', 'pwa.confirm'])
</script>

<template>
  <Teleport to="body">
    <Transition name="pwa-guide">
      <div v-if="modelValue" class="pwa-guide" @click.self="emit('update:modelValue', false)">
        <section class="pwa-guide__panel" role="dialog" aria-modal="true" :aria-label="t('pwa.guideTitle')">
          <header>
            <span class="pwa-guide__handle" />
            <h2>{{ t('pwa.guideTitle') }}</h2>
            <p>{{ t('pwa.guideNote') }}</p>
            <button type="button" :aria-label="t('pwa.close')" @click="emit('update:modelValue', false)">
              <X :size="21" />
            </button>
          </header>

          <ol>
            <li v-for="(step, index) in steps" :key="step">
              <div class="pwa-guide__step-title">
                <i />
                <strong>{{ t('step', { number: index + 1 }) }}</strong>
              </div>

              <div class="pwa-guide__step-card">
                <div v-if="index === 0" class="pwa-guide__toolbar" aria-hidden="true">
                  <ChevronLeft :size="20" />
                  <ChevronRight :size="20" />
                  <SquareArrowUp :size="20" />
                  <BookOpen :size="19" />
                  <Copy :size="18" />
                </div>

                <div v-else-if="index === 1" class="pwa-guide__menu" aria-hidden="true">
                  <span class="pwa-guide__menu-row"><i /><i /></span>
                  <span class="pwa-guide__menu-row"><i /><i /></span>
                  <span class="pwa-guide__menu-row pwa-guide__menu-row--active">
                    <SquarePlus :size="21" />
                    <b>{{ t(step) }}</b>
                  </span>
                </div>

                <div v-else class="pwa-guide__complete" aria-hidden="true">
                  <span class="pwa-guide__phone">
                    <i />
                    <img :src="logoImage" alt="" />
                    <CircleCheck :size="17" />
                  </span>
                  <span>{{ t('pwa.installed') }}</span>
                </div>

                <p>{{ t(step) }}</p>
              </div>
            </li>
          </ol>

          <div class="pwa-guide__action" aria-hidden="true">
            <MousePointer2 :size="28" />
            <SquareArrowUp :size="27" />
          </div>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.pwa-guide {
  --guide-overlay: rgba(3, 6, 8, 0.62);
  --guide-panel: transparent;
  --guide-dot-ring: #1a2027;
  --guide-text: #f5f6f7;
  --guide-muted: #c2c8ce;
  --guide-border: rgba(255, 255, 255, 0.16);
  --guide-line: rgba(255, 255, 255, 0.24);
  --guide-card: transparent;
  --guide-card-border: rgba(255, 255, 255, 0.12);
  --guide-toolbar: rgba(243, 244, 245, 0.9);
  --guide-toolbar-icon: #4e82d6;
  --guide-menu: rgba(245, 246, 247, 0.9);
  --guide-menu-text: #20252b;
  --guide-accent: #e4c84f;
  --guide-focus: #d58a3b;
  position: fixed;
  inset: 0;
  z-index: 2100;
  display: grid;
  align-items: end;
  justify-items: center;
  padding: max(18px, env(safe-area-inset-top)) max(18px, env(safe-area-inset-right)) max(18px, env(safe-area-inset-bottom)) max(18px, env(safe-area-inset-left));
  background: var(--guide-overlay);
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
}

:global(html[data-theme="light"]) .pwa-guide {
  --guide-overlay: rgba(242, 246, 241, 0.7);
  --guide-panel: transparent;
  --guide-dot-ring: #eef2ed;
  --guide-text: #1b211e;
  --guide-muted: #687169;
  --guide-border: rgba(54, 70, 60, 0.18);
  --guide-line: rgba(54, 70, 60, 0.24);
  --guide-card: transparent;
  --guide-card-border: rgba(54, 70, 60, 0.14);
  --guide-toolbar: rgba(237, 240, 236, 0.86);
  --guide-toolbar-icon: #267b54;
  --guide-menu: rgba(245, 247, 244, 0.88);
  --guide-menu-text: #1b211e;
  --guide-accent: #b9862d;
  --guide-focus: #c9782c;
}

.pwa-guide__panel {
  position: relative;
  width: min(100%, 440px);
  max-height: min(780px, calc(100vh - 36px));
  overflow-x: hidden;
  overflow-y: auto;
  color: var(--guide-text);
  background: var(--guide-panel);
  border: 0;
  border-radius: 8px 8px 0 0;
  box-shadow: none;
}

.pwa-guide header {
  position: relative;
  padding: 27px 52px 17px;
  text-align: center;
}

.pwa-guide__handle {
  position: absolute;
  top: 9px;
  left: 50%;
  width: 38px;
  height: 4px;
  background: var(--guide-line);
  border-radius: 2px;
  transform: translateX(-50%);
}

.pwa-guide h2 {
  margin: 0;
  font-size: 21px;
  line-height: 1.3;
}

.pwa-guide header p {
  margin: 8px 0 0;
  color: var(--guide-muted);
  font-size: 12px;
  line-height: 1.45;
}

.pwa-guide header button {
  position: absolute;
  top: 22px;
  right: 15px;
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  padding: 0;
  color: var(--guide-muted);
  background: transparent;
  border: 0;
  border-radius: 50%;
  cursor: pointer;
}

.pwa-guide ol {
  position: relative;
  margin: 0;
  padding: 10px 26px 0;
  list-style: none;
}

.pwa-guide li {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.pwa-guide li + li {
  margin-top: 18px;
}

.pwa-guide li:nth-child(even) {
  align-items: flex-start;
}

.pwa-guide__step-title {
  position: relative;
  z-index: 1;
  display: flex;
  width: 84%;
  height: 22px;
  align-items: center;
  justify-content: flex-end;
  gap: 13px;
}

.pwa-guide li:nth-child(odd) .pwa-guide__step-title {
  flex-direction: row-reverse;
}

.pwa-guide li:nth-child(even) .pwa-guide__step-title {
  justify-content: flex-start;
}

.pwa-guide__step-title i {
  width: 12px;
  height: 12px;
  flex: 0 0 12px;
  background: var(--guide-accent);
  border: 3px solid var(--guide-dot-ring);
  border-radius: 50%;
  box-shadow: 0 0 0 1px var(--guide-accent);
}

.pwa-guide__step-title strong {
  color: var(--guide-accent);
  font-size: 14px;
  font-weight: 800;
}

.pwa-guide__step-card {
  width: 84%;
  margin-top: 8px;
  padding: 12px;
  background: var(--guide-card);
  border: 1px solid var(--guide-card-border);
  border-radius: 8px;
}

.pwa-guide__step-card > p {
  margin: 9px 0 0;
  color: var(--guide-text);
  font-size: 14px;
  font-weight: 600;
  line-height: 1.45;
}

.pwa-guide__toolbar {
  display: grid;
  height: 38px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  align-items: center;
  padding: 0 8px;
  color: var(--guide-toolbar-icon);
  background: var(--guide-toolbar);
  border-radius: 7px;
}

.pwa-guide__toolbar svg {
  justify-self: center;
}

.pwa-guide__toolbar svg:nth-child(2) {
  opacity: 0.35;
}

.pwa-guide__toolbar svg:nth-child(3) {
  width: 30px;
  height: 30px;
  padding: 4px;
  border: 2px solid var(--guide-focus);
  border-radius: 6px;
}

.pwa-guide__menu {
  padding: 5px 5px 7px;
  color: var(--guide-menu-text);
  background: var(--guide-menu);
  border-radius: 7px;
}

.pwa-guide__menu-row {
  display: grid;
  min-height: 34px;
  grid-template-columns: 28px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  padding: 5px 8px;
  overflow: hidden;
  border-bottom: 1px solid rgba(110, 118, 124, 0.2);
}

.pwa-guide__menu-row:last-child {
  border-bottom: 0;
}

.pwa-guide__menu-row i:first-child {
  width: 18px;
  height: 18px;
  background: rgba(110, 118, 124, 0.24);
  border-radius: 4px;
}

.pwa-guide__menu-row i:last-child {
  width: 52%;
  height: 7px;
  background: rgba(110, 118, 124, 0.22);
  border-radius: 4px;
}

.pwa-guide__menu-row--active {
  margin: 2px 0;
  border-radius: 6px;
  box-shadow: inset 0 0 0 2px var(--guide-focus);
}

.pwa-guide__menu-row b {
  overflow: hidden;
  font-size: 13px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pwa-guide__menu-row svg {
  color: #b9862d;
}

.pwa-guide__complete {
  display: flex;
  min-height: 56px;
  align-items: center;
  gap: 12px;
  color: var(--guide-text);
}

.pwa-guide__phone {
  position: relative;
  display: grid;
  width: 112px;
  height: 62px;
  flex: 0 0 112px;
  place-items: center;
  color: #8bcf52;
  background: #10151a;
  border: 2px solid #77818b;
  border-radius: 12px 12px 5px 5px;
  box-shadow: inset 0 0 0 3px #1d252c;
}

.pwa-guide__phone i {
  position: absolute;
  top: 4px;
  left: 50%;
  width: 28px;
  height: 4px;
  background: #39434c;
  border-radius: 2px;
  transform: translateX(-50%);
}

.pwa-guide__phone img {
  width: 34px;
  height: 34px;
  object-fit: cover;
  border: 1px solid rgba(219, 182, 93, 0.7);
  border-radius: 7px;
}

.pwa-guide__phone svg {
  position: absolute;
  right: 8px;
  bottom: 6px;
  color: #8bcf52;
  background: #10151a;
  border-radius: 50%;
}

.pwa-guide__complete > span:last-child {
  font-size: 14px;
  font-weight: 750;
}

.pwa-guide__action {
  display: flex;
  height: 66px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding-bottom: env(safe-area-inset-bottom);
  color: var(--guide-accent);
}

.pwa-guide__action svg:first-child {
  color: var(--guide-text);
  animation: pwa-guide-tap 1.2s ease-in-out infinite;
}

.pwa-guide-enter-active,
.pwa-guide-leave-active {
  transition: opacity 160ms ease;
}

.pwa-guide-enter-active .pwa-guide__panel,
.pwa-guide-leave-active .pwa-guide__panel {
  transition: transform 160ms ease, opacity 160ms ease;
}

.pwa-guide-enter-from,
.pwa-guide-leave-to,
.pwa-guide-enter-from .pwa-guide__panel,
.pwa-guide-leave-to .pwa-guide__panel {
  opacity: 0;
}

.pwa-guide-enter-from .pwa-guide__panel,
.pwa-guide-leave-to .pwa-guide__panel {
  transform: translateY(18px);
}

@keyframes pwa-guide-tap {
  0%, 100% { transform: translate(-4px, 4px); }
  50% { transform: translate(0, 0); }
}

@media (max-width: 520px) {
  .pwa-guide {
    padding: max(18px, env(safe-area-inset-top)) 0 0;
  }

  .pwa-guide__panel {
    width: 100%;
    max-height: calc(100vh - max(18px, env(safe-area-inset-top)));
    border-right: 0;
    border-bottom: 0;
    border-left: 0;
  }

  .pwa-guide header {
    padding-right: 44px;
    padding-left: 44px;
  }

  .pwa-guide ol {
    padding-right: 18px;
    padding-left: 18px;
  }

}

@media (prefers-reduced-motion: reduce) {
  .pwa-guide__action svg:first-child {
    animation: none;
  }
}
</style>
