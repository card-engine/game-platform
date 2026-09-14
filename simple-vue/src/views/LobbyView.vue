<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import bannerImage from '../assets/images/banner.webp'
import logoImage from '../assets/images/logo.webp'
import {
  ChevronRight,
  Dices,
  FishSymbol,
  Gamepad2,
  Globe2,
  Monitor,
  Moon,
  RadioTower,
  Search,
  Send,
  Spade,
  Sun,
  Volleyball,
  WalletCards,
} from '@lucide/vue'
import GameCard from '../components/GameCard.vue'
import GamePlayer from '../components/GamePlayer.vue'
import { getBalance, getBrandStats, getGameLink, getGames } from '../api/game'
import { finishImageLoading, GAME_PLACEHOLDER, setGamePlaceholder } from '../game-image'
import { useThemeStore } from '../stores/theme'
import { useUserStore } from '../stores/user'
import type { GameItem, GameType } from '../types/game'

const categories = [
  { value: 'slot', label: 'category.slots', icon: Gamepad2 },
  { value: 'table', label: 'category.mini', icon: Dices },
  { value: 'fish', label: 'category.fishing', icon: FishSymbol },
  { value: 'poker', label: 'category.poker', icon: Spade },
  { value: 'sport', label: 'category.sports', icon: Volleyball },
  { value: 'other', label: 'category.other', icon: Gamepad2 },
] as const
const themes = [
  { value: 'system', label: 'theme.system', icon: Monitor },
  { value: 'light', label: 'theme.light', icon: Sun },
  { value: 'dark', label: 'theme.dark', icon: Moon },
] as const
const languages = [
  ['en-US', 'English'],
  ['zh-CN', '中文'],
  ['ja-JP', '日本語'],
  ['ko-KR', '한국어'],
  ['th-TH', 'ไทย'],
  ['id-ID', 'Indonesia'],
  ['vi-VN', 'Tiếng Việt'],
  ['my-MM', 'မြန်မာ'],
  ['pt-BR', 'Português'],
  ['es-AR', 'Español'],
  ['bn-BD', 'বাংলা'],
]
const brandOrder = ['jili', 'pg', 'pragmatic', 'inout', 'spribe', 'jdb', 'fachai', 'tada']
const lobbyState = JSON.parse(localStorage.getItem('mgames-lobby-state') || '{}') as {
  type?: GameType
  brand?: string
  visibleCount?: number
  scrollY?: number
  anchorId?: string
  anchorOffset?: number
}
history.scrollRestoration = 'manual'
const user = useUserStore()
await user.ready
const theme = useThemeStore()
const queryClient = useQueryClient()
const { t, locale } = useI18n()
const selectedType = ref<GameType>(lobbyState.type || 'slot')
const selectedBrand = ref(lobbyState.brand === 'yono' ? '' : lobbyState.brand || '')
const search = ref('')
const visibleCount = ref(lobbyState.visibleCount || 24)
const animatedBalance = ref(0)
const player = ref<{ game: GameItem; url: string }>()
const launchingId = ref('')
const loadMoreTrigger = ref<HTMLElement>()
const categoryStrip = ref<HTMLElement>()
const providerStrip = ref<HTMLElement>()
let saveFrame = 0
let balanceFrame = 0
let restoreScrollY = lobbyState.scrollY || 0

function saveLobbyState() {
  cancelAnimationFrame(saveFrame)
  saveFrame = requestAnimationFrame(() => {
    const headerBottom = document.querySelector('.site-header')?.getBoundingClientRect().bottom || 0
    const anchor = [...document.querySelectorAll<HTMLElement>('.game-card')]
      .find((card) => card.getBoundingClientRect().top >= headerBottom)
    if (restoreScrollY && !anchor) return
    localStorage.setItem('mgames-lobby-state', JSON.stringify({
      type: selectedType.value,
      brand: selectedBrand.value,
      visibleCount: visibleCount.value,
      scrollY: window.scrollY,
      anchorId: anchor?.dataset.gameId,
      anchorOffset: anchor?.getBoundingClientRect().top,
    }))
  })
}

const loadObserver = new IntersectionObserver(([entry]) => {
  if (entry.isIntersecting) visibleCount.value += 24
}, { rootMargin: '320px' })

watch(loadMoreTrigger, (element) => {
  loadObserver.disconnect()
  if (element) loadObserver.observe(element)
})
onMounted(() => {
  window.addEventListener('scroll', saveLobbyState, { passive: true })
  void nextTick(() => categoryStrip.value?.querySelector('.active')
    ?.scrollIntoView({ block: 'nearest', inline: 'center' }))
})
onBeforeUnmount(() => {
  loadObserver.disconnect()
  cancelAnimationFrame(saveFrame)
  cancelAnimationFrame(balanceFrame)
  window.removeEventListener('scroll', saveLobbyState)
})

const { data: brandStats, isLoading: statsLoading, isError: statsError, refetch: refetchStats } = useQuery({
  queryKey: ['brand-stats'],
  queryFn: getBrandStats,
  staleTime: 300000,
})

const totalGames = computed(() => Object.values(brandStats.value || {})
  .flat()
  .reduce((total, item) => total + item.count, 0))
const selectedStats = computed(() => [...(brandStats.value?.[selectedType.value] || [])].sort((a, b) => {
  const aIndex = brandOrder.indexOf(a.gameBrand.toLowerCase())
  const bIndex = brandOrder.indexOf(b.gameBrand.toLowerCase())
  return (aIndex < 0 ? 99 : aIndex) - (bIndex < 0 ? 99 : bIndex)
}))

watch(selectedStats, async (stats) => {
  if (!brandStats.value) return
  if (!stats.length && !lobbyState.type) {
    selectedType.value = (Object.entries(brandStats.value).find(([, items]) => items.length)?.[0] as GameType) || selectedType.value
    return
  }
  if (!stats.some(({ gameBrand }) => gameBrand.toLowerCase() === selectedBrand.value)) {
    selectedBrand.value = stats[0]?.gameBrand.toLowerCase() || ''
  }
  await nextTick()
  const strip = providerStrip.value
  const active = strip?.querySelector<HTMLElement>('.active')
  if (strip && active) strip.scrollLeft = active.offsetLeft - strip.offsetLeft - (strip.clientWidth - active.offsetWidth) / 2
}, { immediate: true })
watch([selectedType, selectedBrand], () => {
  restoreScrollY = 0
  search.value = ''
  visibleCount.value = 24
  window.scrollTo(0, 0)
})
watch([selectedType, selectedBrand, visibleCount], saveLobbyState)
watch(search, () => { visibleCount.value = 24 })
watch(() => user.language, (value) => {
  locale.value = value
  document.documentElement.lang = value
}, { immediate: true })

const { data: games, isLoading: gamesLoading, isError: gamesError, refetch: refetchGames } = useQuery({
  queryKey: computed(() => ['games', selectedType.value, selectedBrand.value]),
  queryFn: () => getGames(selectedBrand.value, selectedType.value),
  enabled: computed(() => Boolean(selectedBrand.value)),
  staleTime: 300000,
})
const filteredGames = computed(() => {
  const keyword = search.value.trim().toLowerCase()
  if (!keyword) return games.value || []
  return (games.value || []).filter((game) => [game.gameId, game.gameName, game.gameFullName]
    .some((value) => value.toLowerCase().includes(keyword)))
})
const featuredGames = computed(() => {
  const list = [...(games.value || [])]
  for (let index = list.length - 1; index > 0; index--) {
    const random = Math.floor(Math.random() * (index + 1))
    const item = list[index]
    list[index] = list[random]
    list[random] = item
  }
  return list.slice(0, 3)
})
const displayedGames = computed(() => filteredGames.value.slice(0, visibleCount.value))

watch([displayedGames, statsLoading, gamesLoading], ([, statsPending, gamesPending]) => {
  if (statsPending || gamesPending || !restoreScrollY) return
  const anchor = lobbyState.anchorId
    ? document.querySelector<HTMLElement>(`[data-game-id="${CSS.escape(lobbyState.anchorId)}"]`)
    : null
  if (anchor && lobbyState.anchorOffset !== undefined) {
    window.scrollBy(0, anchor.getBoundingClientRect().top - lobbyState.anchorOffset)
  } else {
    window.scrollTo(0, restoreScrollY)
  }
  restoreScrollY = 0
}, { immediate: true, flush: 'post' })

const { data: balance } = useQuery({
  queryKey: ['balance', user.uniqueId],
  queryFn: () => getBalance(),
  refetchOnWindowFocus: false,
})
const balanceFormatter = computed(() => new Intl.NumberFormat(user.language, {
  style: 'currency',
  currency: balance.value?.currency || 'USD',
}))
watch(() => balance.value?.balance, (target) => {
  if (target === undefined) return
  cancelAnimationFrame(balanceFrame)
  const startValue = animatedBalance.value
  let startTime = 0
  const animate = (time: number) => {
    startTime ||= time
    const progress = Math.min((time - startTime) / 900, 1)
    animatedBalance.value = startValue + (target - startValue) * (1 - (1 - progress) ** 3)
    if (progress < 1) balanceFrame = requestAnimationFrame(animate)
  }
  balanceFrame = requestAnimationFrame(animate)
}, { immediate: true })
const formattedBalance = computed(() => {
  const parts = balanceFormatter.value.formatToParts(animatedBalance.value)
  const numberParts = ['integer', 'fraction']
  return parts.map((part, index) => {
    if (part.type !== 'currency') return part.value
    const before = numberParts.includes(parts[index - 1]?.type)
    const after = numberParts.includes(parts[index + 1]?.type)
    return `${before ? ' ' : ''}${part.value}${after ? ' ' : ''}`
  }).join('')
})

function categoryCount(type: GameType) {
  return brandStats.value?.[type]?.reduce((total, item) => total + item.count, 0) || 0
}

function selectCategory(type: GameType, event: MouseEvent) {
  const button = event.currentTarget as HTMLElement
  const active = button.parentElement?.querySelector<HTMLElement>('.active')
  const movingLeft = active ? button.offsetLeft < active.offsetLeft : false
  const neighbor = movingLeft ? button.previousElementSibling : button.nextElementSibling
  selectedType.value = type
  providerStrip.value?.scrollTo({ left: 0 })
  void nextTick(() => neighbor?.scrollIntoView({ block: 'nearest', inline: movingLeft ? 'start' : 'end' }))
}

function brandLabel(brand: string) {
  return brand.toLowerCase() === 'pragmatic' ? 'PP' : brand.toUpperCase()
}

function selectBrand(brand: string, event: MouseEvent) {
  selectedBrand.value = brand.toLowerCase()
  const button = event.currentTarget as HTMLElement
  button.nextElementSibling?.scrollIntoView({ block: 'nearest', inline: 'end' })
}

async function launchGame(game: GameItem) {
  launchingId.value = game.gameId
  try {
    const url = await getGameLink({
      gameId: game.gameId,
      language: game.gameBrand === 'pg' ? user.language.split('-')[0] : user.language,
    })
    player.value = { game, url }
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : 'Unable to open game')
  } finally {
    launchingId.value = ''
  }
}

function requestGame(game: GameItem) { void launchGame(game) }

function closePlayer() {
  player.value = undefined
  void queryClient.invalidateQueries({ queryKey: ['balance', user.uniqueId] })
}

</script>

<template>
  <div class="lobby-shell">
    <header class="site-header">
      <div class="site-header__inner">
        <a class="brand" href="/" :aria-label="t('home')">
          <span class="brand__mark"><img :src="logoImage" alt="MGames" /></span>
          <span class="brand__name"><strong>MGames</strong></span>
        </a>

        <nav ref="categoryStrip" class="category-strip" :aria-label="t('categories')">
          <button
            v-for="category in categories"
            :key="category.value"
            type="button"
            :class="{ active: selectedType === category.value }"
            :aria-pressed="selectedType === category.value"
            @click="selectCategory(category.value, $event)"
          >
            <span><component :is="category.icon" :size="21" /></span>
            <strong>{{ t(category.label) }}</strong>
            <small>{{ categoryCount(category.value) }}</small>
          </button>
        </nav>

        <div class="header-actions">
          <div class="wallet">
            <WalletCards :size="18" />
            <span>{{ formattedBalance }}</span>
          </div>

          <div class="theme-switcher" role="group" :aria-label="t('theme.label')">
            <el-tooltip v-for="item in themes" :key="item.value" :content="t(item.label)" placement="bottom">
              <button
                type="button"
                :aria-label="t(item.label)"
                :aria-pressed="theme.preference === item.value"
                :class="{ active: theme.preference === item.value }"
                @click="theme.preference = item.value"
              >
                <component :is="item.icon" :size="17" />
              </button>
            </el-tooltip>
          </div>

          <el-select v-model="user.language" class="language-select" :aria-label="t('language')">
            <template #prefix><Globe2 :size="17" /></template>
            <el-option v-for="language in languages" :key="language[0]" :label="`${language[1]} · ${language[0]}`" :value="language[0]" />
          </el-select>

          <el-tooltip content="Telegram" placement="bottom">
            <a class="icon-button telegram-button" href="#" :aria-label="t('openTelegram')"><Send :size="19" /></a>
          </el-tooltip>
        </div>
      </div>
    </header>

    <main class="page-content">
      <section v-if="!statsError" class="provider-navigation">
        <div ref="providerStrip" class="provider-strip" role="group" :aria-label="t('providers')">
          <div v-if="statsLoading" class="provider-loading">
            <el-skeleton-item v-for="item in 5" :key="item" variant="button" />
          </div>
          <button
            v-for="provider in selectedStats"
            v-else
            :key="provider.gameBrand"
            type="button"
            :class="{ active: selectedBrand === provider.gameBrand.toLowerCase() }"
            :aria-pressed="selectedBrand === provider.gameBrand.toLowerCase()"
            @click="selectBrand(provider.gameBrand, $event)"
          >
            <span>{{ brandLabel(provider.gameBrand) }}</span>
            <small>{{ provider.count }}</small>
          </button>
        </div>

        <el-input v-model="search" class="game-search" size="large" clearable :placeholder="t('search')">
          <template #prefix><Search :size="18" /></template>
        </el-input>
      </section>

      <section class="promo-rail" :aria-label="t('featuredGames')">
        <article class="brand-promo">
          <div>
            <span>MGames</span>
            <small>{{ t('gameCount', { count: totalGames }) }}</small>
          </div>
          <img class="brand-promo__bottom-art" :src="bannerImage" alt="" loading="lazy" decoding="async" />
        </article>

        <template v-if="statsLoading || gamesLoading">
          <div v-for="item in 3" :key="item" class="featured-game featured-game--skeleton" aria-hidden="true" />
        </template>

        <button
          v-for="game in featuredGames"
          :key="game.gameId"
          class="featured-game"
          type="button"
          :disabled="launchingId === game.gameId"
          :aria-label="t('playFeatured', { name: game.gameFullName || game.gameName })"
          @click="requestGame(game)"
        >
          <img
            :src="game.gameIcon || GAME_PLACEHOLDER"
            :alt="game.gameFullName || game.gameName"
            loading="lazy"
            decoding="async"
            @load="finishImageLoading"
            @error="setGamePlaceholder"
          />
          <span class="featured-game__caption">
            <small>{{ t('featured') }} · {{ brandLabel(game.gameBrand) }}</small>
            <strong>{{ game.gameFullName || game.gameName }}</strong>
            <span>{{ t('playNow') }} <ChevronRight :size="15" /></span>
          </span>
        </button>
      </section>

      <section v-if="statsError" class="state-panel">
        <RadioTower :size="28" />
        <h2>{{ t('providerUnavailable') }}</h2>
        <el-button type="primary" @click="refetchStats()">{{ t('tryAgain') }}</el-button>
      </section>

      <template v-else>
        <section class="game-library">
          <header class="section-heading">
            <template v-if="selectedBrand">
              <h1>{{ t('gamesTitle', { brand: brandLabel(selectedBrand) }) }}</h1>
              <span>{{ t('gameCount', { count: filteredGames.length }) }}</span>
            </template>
          </header>

          <section v-if="statsLoading || gamesLoading" class="game-grid" :aria-label="t('loadingGames')">
            <div v-for="item in 18" :key="item" class="game-skeleton">
              <el-skeleton animated>
                <template #template>
                  <el-skeleton-item variant="image" />
                  <el-skeleton-item variant="text" />
                  <el-skeleton-item variant="text" style="width: 48%" />
                </template>
              </el-skeleton>
            </div>
          </section>

          <section v-else-if="gamesError" class="state-panel">
            <RadioTower :size="28" />
            <h2>{{ t('gamesLoadFailed') }}</h2>
            <el-button type="primary" @click="refetchGames()">{{ t('tryAgain') }}</el-button>
          </section>

          <section v-else-if="displayedGames.length" class="game-grid" :aria-label="t('games')">
            <GameCard
              v-for="game in displayedGames"
              :key="game.gameId"
              :game="game"
              :loading="launchingId === game.gameId"
              @play="requestGame"
            />
          </section>

          <section v-else class="state-panel">
            <Search :size="28" />
            <h2>{{ t('noGames') }}</h2>
            <p>{{ t('noGamesHint') }}</p>
          </section>

          <div v-if="displayedGames.length < filteredGames.length" ref="loadMoreTrigger" class="load-more-trigger" />
        </section>
      </template>
    </main>

    <GamePlayer
      v-if="player"
      :game="player.game"
      :url="player.url"
      @close="closePlayer"
    />
  </div>
</template>
