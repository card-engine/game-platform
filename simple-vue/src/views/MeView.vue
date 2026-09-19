<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { Check, Copy, CreditCard, FileText, Pencil, RefreshCw, Send, ShieldCheck, UserRound, WalletCards } from '@lucide/vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import GameCard from '../components/GameCard.vue'
import RechargeHistory from '../components/RechargeHistory.vue'
import CurrencyIcon from '../components/CurrencyIcon.vue'
import { getUser, getUserGames, updateUser } from '../api/game'
import { useUserStore } from '../stores/user'
import type { Balance, GameItem } from '../types/game'
import { formatAmount } from '../utils/amount'

const props = defineProps<{ balance?: Balance; launchingId: string }>()
const emit = defineEmits<{ play: [game: GameItem]; recharge: [] }>()
const { t } = useI18n()
const user = useUserStore()
const queryClient = useQueryClient()
const seed = ref(0)
const editing = ref(false)
const saving = ref(false)
const nickname = ref('')
const telegramUrl = import.meta.env.VITE_TELEGRAM_URL as string | undefined

const { data: profile } = useQuery({ queryKey: ['user'], queryFn: getUser })
const { data: recommendations, isLoading, isError, refetch } = useQuery({
  queryKey: computed(() => ['user-games', seed.value]),
  queryFn: () => getUserGames(seed.value),
  staleTime: 300000,
})

watch(profile, (value) => {
  if (!value) return
  user.nickname = value.nickname || ''
  user.status = value.status
  nickname.value = user.nickname
}, { immediate: true })

const displayName = computed(() => profile.value?.nickname || t('me.player', { id: profile.value?.unique_id || user.uniqueId }))
const avatarStyle = computed(() => ({ background: `linear-gradient(145deg, hsl(${Number(user.uniqueId || 0) % 360} 62% 58%), var(--accent))` }))
const formattedBalance = computed(() => `${formatAmount(props.balance?.balance)} ${props.balance?.currency || user.currencyCode}`)
const groups = computed(() => [
  { key: 'recent', title: t('me.recent'), games: recommendations.value?.recent || [] },
  { key: 'hot', title: t('me.hot'), games: recommendations.value?.hot || [] },
  { key: 'discover', title: t('me.discover'), games: recommendations.value?.discover || [] },
].filter((group) => group.games.length))

async function copyId() {
  await navigator.clipboard.writeText(String(profile.value?.unique_id || user.uniqueId))
  ElMessage.success(t('me.copied'))
}

async function saveNickname() {
  saving.value = true
  try {
    const value = await updateUser(nickname.value.trim())
    user.nickname = value.nickname || ''
    user.status = value.status
    queryClient.setQueryData(['user'], value)
    editing.value = false
    ElMessage.success(t('me.saved'))
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : t('me.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="me-page">
    <aside class="me-sidebar">
  <el-alert v-if="user.status === 0" :title="t('accountDisabled')" type="warning" :closable="false" />
      <section class="profile-card">
        <span class="profile-avatar" :style="avatarStyle"><UserRound :size="40" /></span>
        <div class="profile-card__identity">
          <template v-if="editing">
            <el-input v-model="nickname" maxlength="20" :placeholder="t('me.nickname')" @keyup.enter="saveNickname" />
            <div class="profile-card__edit-actions">
              <el-button size="small" @click="editing = false">{{ t('me.cancel') }}</el-button>
              <el-button size="small" type="primary" :loading="saving" @click="saveNickname">{{ t('me.save') }}</el-button>
            </div>
          </template>
          <template v-else>
            <button class="profile-name" type="button" :disabled="user.status === 0" @click="editing = true">{{ displayName }}<Pencil :size="14" /></button>
            <button class="profile-id" type="button" @click="copyId">ID {{ profile?.unique_id || user.uniqueId }}<Copy :size="14" /></button>
          </template>
        </div>
      </section>

      <section class="me-panel wallet-panel">
        <div><WalletCards :size="22" /><span>{{ t('me.wallet') }}</span></div>
        <strong><CurrencyIcon :code="balance?.currency || user.currencyCode" :size="22" />{{ formattedBalance }}</strong>
        <button type="button" :disabled="user.status === 0" @click="emit('recharge')"><CreditCard :size="18" />{{ t('me.recharge') }}</button>
      </section>

      <section class="me-panel account-notice">
        <ShieldCheck :size="22" />
        <div><strong>{{ t('me.browserAccount') }}</strong><p>{{ t('me.browserNotice') }}</p></div>
      </section>

      <nav class="me-links" :aria-label="t('me.services')">
        <a v-if="telegramUrl" :href="telegramUrl" target="_blank" rel="noopener"><Send :size="19" />Telegram</a>
        <RouterLink to="/privacy"><ShieldCheck :size="19" />{{ t('me.privacy') }}</RouterLink>
        <RouterLink to="/terms"><FileText :size="19" />{{ t('me.terms') }}</RouterLink>
      </nav>
    </aside>

    <div class="me-games">
      <RechargeHistory />
      <div v-if="isLoading" class="recommendation-loading">
        <div v-for="item in 12" :key="item" class="game-skeleton"><el-skeleton animated><template #template><el-skeleton-item variant="image" /><el-skeleton-item variant="text" /></template></el-skeleton></div>
      </div>
      <section v-for="group in groups" v-else :key="group.key" class="recommendation-section">
        <header><h2>{{ group.title }}</h2><button v-if="group.key === 'discover'" type="button" @click="seed++"><RefreshCw :size="16" />{{ t('me.shuffle') }}</button></header>
        <div class="recommendation-games">
          <GameCard v-for="game in group.games" :key="game.gameId" :game="game" :loading="launchingId === game.gameId" @play="emit('play', $event)" />
        </div>
      </section>
      <section v-if="isError" class="state-panel">
        <RefreshCw :size="28" /><h2>{{ t('gamesLoadFailed') }}</h2><el-button type="primary" @click="refetch()">{{ t('tryAgain') }}</el-button>
      </section>
      <section v-else-if="!isLoading && !groups.length" class="state-panel"><Check :size="28" /><h2>{{ t('me.noRecommendations') }}</h2></section>
    </div>
  </section>
</template>
