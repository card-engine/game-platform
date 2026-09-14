import { defineStore } from 'pinia'

const STORAGE_KEY = 'mgames-game-access-until'
const PASSWORD = import.meta.env.VITE_GAME_ACCESS_PASSWORD || 'mgames'
const TTL = Number(import.meta.env.VITE_GAME_ACCESS_TTL_DAYS || 7) * 86400000

export const useAccessStore = defineStore('access', () => {
  function hasAccess() {
    return Number(localStorage.getItem(STORAGE_KEY)) > Date.now()
  }

  function verify(password: string) {
    if (password !== PASSWORD) return false
    localStorage.setItem(STORAGE_KEY, String(Date.now() + TTL))
    return true
  }

  return { hasAccess, verify }
})
