import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'

export type ThemePreference = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'mgames-theme'
const colorScheme = window.matchMedia('(prefers-color-scheme: dark)')

export const useThemeStore = defineStore('theme', () => {
  const preference = ref<ThemePreference>((localStorage.getItem(STORAGE_KEY) as ThemePreference) || 'system')
  const systemDark = ref(colorScheme.matches)
  const activeTheme = computed(() => preference.value === 'system'
    ? (systemDark.value ? 'dark' : 'light')
    : preference.value)

  colorScheme.addEventListener('change', (event) => { systemDark.value = event.matches })
  watch(preference, (value) => localStorage.setItem(STORAGE_KEY, value))
  watch(activeTheme, (value) => {
    document.documentElement.dataset.theme = value
    document.documentElement.style.colorScheme = value
    document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')!.content = getComputedStyle(document.documentElement)
      .getPropertyValue('--header').trim()
  }, { immediate: true })

  return { preference }
})
