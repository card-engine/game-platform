import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { VueQueryPlugin } from '@tanstack/vue-query'
import { registerSW } from 'virtual:pwa-register'
import {
  ElButton,
  ElDialog,
  ElInput,
  ElOption,
  ElSelect,
  ElSkeleton,
  ElSkeletonItem,
  ElTooltip,
} from 'element-plus'
import 'element-plus/dist/index.css'
import App from './App.vue'
import { i18n } from './i18n'
import { isPwaStandalone } from './pwa'
import './styles/main.css'

document.documentElement.classList.toggle('pwa-standalone', isPwaStandalone())
if (import.meta.env.PROD) registerSW({ immediate: true })
else void navigator.serviceWorker?.getRegistrations().then((registrations) => registrations.forEach((registration) => registration.unregister()))

const app = createApp(App).use(createPinia()).use(VueQueryPlugin).use(i18n)

for (const component of [ElButton, ElDialog, ElInput, ElOption, ElSelect, ElSkeleton, ElSkeletonItem, ElTooltip]) {
  app.component(component.name!, component)
}

app.mount('#app')
