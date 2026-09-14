<script setup lang="ts">
import { Play } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { finishImageLoading, GAME_PLACEHOLDER, setGamePlaceholder } from '../game-image'
import type { GameItem } from '../types/game'

defineProps<{ game: GameItem; loading: boolean }>()
defineEmits<{ play: [game: GameItem] }>()
const { t } = useI18n()
</script>

<template>
  <button
    class="game-card"
    type="button"
    :data-game-id="game.gameId"
    :disabled="loading"
    :aria-busy="loading"
    :aria-label="t('play', { name: game.gameFullName || game.gameName })"
    @click="$emit('play', game)"
  >
    <span class="game-card__artwork">
      <img
        :src="game.gameIcon || GAME_PLACEHOLDER"
        :alt="game.gameFullName || game.gameName"
        loading="lazy"
        decoding="async"
        @load="finishImageLoading"
        @error="setGamePlaceholder"
      />
      <span class="game-card__play"><Play :size="20" fill="currentColor" /></span>
      <span v-if="loading" class="game-card__loading"><span class="loading-ring" /></span>
    </span>
    <span class="game-card__meta">
      <strong>{{ game.gameFullName || game.gameName }}</strong>
      <small>{{ game.gameBrand.toUpperCase() }}</small>
    </span>
  </button>
</template>
