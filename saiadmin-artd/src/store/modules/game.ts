import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useGameStore = defineStore('gameStore', () => {
  const merchantId = ref<number>()
  const role = ref('')
  const setMerchant = (id?: number) => {
    merchantId.value = id
    if (id) localStorage.setItem('mg-current-merchant', String(id))
    else localStorage.removeItem('mg-current-merchant')
  }
  const setRole = (value: string) => (role.value = value)
  const restore = () => {
    const id = Number(localStorage.getItem('mg-current-merchant'))
    if (id) merchantId.value = id
  }
  restore()
  return { merchantId, role, setMerchant, setRole }
})
