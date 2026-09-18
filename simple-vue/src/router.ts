import { createRouter, createWebHashHistory } from 'vue-router'
import LobbyView from './views/LobbyView.vue'
import type { GameType } from './types/game'

const types: GameType[] = ['slot', 'table', 'fish', 'poker', 'sport']
const savedType = () => {
  const type = JSON.parse(localStorage.getItem('mgames-lobby-state') || '{}').type as GameType
  return types.includes(type) ? type : 'slot'
}

export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', redirect: () => `/games/${savedType()}` },
    { path: '/games/:type', name: 'games', component: LobbyView },
    { path: '/me', name: 'me', component: LobbyView },
    { path: '/privacy', name: 'privacy', component: LobbyView },
    { path: '/terms', name: 'terms', component: LobbyView },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
  scrollBehavior: (to, from) => to.name === 'games' && from.name !== 'games' ? false : { top: 0 },
})
