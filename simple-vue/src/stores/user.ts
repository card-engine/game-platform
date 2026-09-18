import { ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { createSession } from '../api/game'

const TOKEN_KEY = 'mgs-player-token'
const UNIQUE_ID_KEY = 'mgs-unique-id'
const LANGUAGE_KEY = 'mgs-language'
const CURRENCY_KEY = 'mgs-currency-code'

const token = localStorage.getItem(TOKEN_KEY) || (() => {
  const bytes = crypto.getRandomValues(new Uint8Array(32))
  const value = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  localStorage.setItem(TOKEN_KEY, value)
  return value
})()

export const useUserStore = defineStore('user', () => {
  const mgsUserId = ref(0)
  const uniqueId = ref(localStorage.getItem(UNIQUE_ID_KEY) || '')
  const nickname = ref('')
  const language = ref(localStorage.getItem(LANGUAGE_KEY) || 'en-US')
  const currencyCode = ref(localStorage.getItem(CURRENCY_KEY) || 'INR')
  const ready = createSession(token, language.value).then((data) => {
    mgsUserId.value = data.user.mgs_user_id
    uniqueId.value = String(data.user.unique_id)
    nickname.value = data.user.nickname || ''
    currencyCode.value = data.default_currency_code
    localStorage.setItem(UNIQUE_ID_KEY, uniqueId.value)
    localStorage.setItem(CURRENCY_KEY, currencyCode.value)
  }).catch(() => undefined)
  watch(language, (value) => localStorage.setItem(LANGUAGE_KEY, value))
  return { token, mgsUserId, uniqueId, nickname, language, currencyCode, ready }
})
