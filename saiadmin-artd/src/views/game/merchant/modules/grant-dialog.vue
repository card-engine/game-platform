<template>
  <ElDialog
    v-model="visible"
    :title="`${merchant?.name || ''} · ${$t('game.gameOverrides')}`"
    width="min(1100px, 97vw)"
    top="4vh"
    destroy-on-close
  >
    <div class="grant-dialog__body">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="font-medium">{{ $t('game.gameOverrides') }}</div>
        <div class="flex flex-wrap gap-2">
          <ElSelect
            v-model="query.platform_code"
            clearable
            :placeholder="$t('game.gamePlatform')"
            class="!w-36"
            @change="loadGames"
          >
            <ElOption v-for="item in platforms" :key="item" :label="item" :value="item" />
          </ElSelect>
          <ElSelect
            v-model="query.unique_brand_id"
            clearable
            filterable
            :placeholder="$t('game.uniqueBrand')"
            class="!w-40"
            @change="loadGames"
          >
            <ElOption v-for="item in brands" :key="item.id" :label="item.name" :value="item.id" />
          </ElSelect>
          <ElInput
            v-model="query.keyword"
            clearable
            :placeholder="$t('game.gameKeyword')"
            class="!w-48"
            @keyup.enter="loadGames"
          />
          <ElButton :icon="Search" @click="loadGames">{{ $t('game.search') }}</ElButton>
        </div>
      </div>
      <ElAlert type="info" :closable="false" :title="$t('game.grantEffectiveHint')" class="mb-3" />
      <ElTable :data="games" v-loading="loading" border height="470">
        <ElTableColumn :label="$t('game.override')" width="100" align="center">
          <template #default="{ row }">
            <ElTag v-if="overrides[row.id]" type="success" size="small">{{
              $t('game.configured')
            }}</ElTag>
            <ElButton v-else link type="primary" @click="enable(row)">{{
              $t('game.configure')
            }}</ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="$t('game.game')" min-width="220">
          <template #default="{ row }"
            ><div>{{ row.name }}</div
            ><div class="text-xs text-g-500">{{ row.game_code }}</div></template
          >
        </ElTableColumn>
        <ElTableColumn
          prop="brand.unique_brand.name"
          :label="$t('game.uniqueBrand')"
          min-width="130"
        />
        <ElTableColumn prop="platform_code" :label="$t('game.gamePlatform')" width="120" />
        <ElTableColumn :label="$t('game.status')" width="120" align="center">
          <template #default="{ row }">
            <ElSwitch
              v-if="overrides[row.id]"
              v-model="overrides[row.id].status"
              :active-value="1"
              :inactive-value="0"
            />
            <span v-else class="text-g-500">{{ $t('game.defaultValue') }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="merchant?.billing_mode === 1"
          :label="`${$t('game.ggrRate')} %`"
          width="150"
        >
          <template #default="{ row }">
            <ElInput v-if="overrides[row.id]" v-model="overrides[row.id].rate_percent" class="!w-28"
              ><template #suffix>%</template></ElInput
            >
            <span v-else class="text-g-500">{{ $t('game.defaultValue') }}</span>
          </template>
        </ElTableColumn>
      </ElTable>
      <div class="mt-2 text-xs text-g-500">{{
        $t('game.overrideSummary', { count: Object.keys(overrides).length })
      }}</div>
    </div>
    <template #footer
      ><ElButton @click="visible = false">{{ $t('game.cancel') }}</ElButton
      ><ElButton type="primary" :loading="saving" @click="save">{{
        $t('game.saveGrant')
      }}</ElButton></template
    >
  </ElDialog>
</template>

<script setup lang="ts">
  import { Search } from '@element-plus/icons-vue'
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import merchantApi from '@/api/game/merchant'
  import listApi from '@/api/game/list'
  import { percentToRate, rateToPercent } from '@/utils/game/amount'

  const props = defineProps<{ modelValue: boolean; merchant?: any; brands: any[] }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const { t } = useI18n()
  const overrides = reactive<Record<number, any>>({})
  const games = ref<any[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const query = reactive({
    merchant_id: undefined as number | undefined,
    platform_code: '',
    unique_brand_id: undefined as number | undefined,
    keyword: '',
    page: 1,
    limit: 100
  })
  const platforms = ['wxgame', 'acewin', 'tada', 'goldengatex']

  const loadGames = async () => {
    loading.value = true
    try {
      games.value = (await listApi.list(query)).data
    } finally {
      loading.value = false
    }
  }
  watch(visible, async (value) => {
    if (!value || !props.merchant) return
    Object.keys(overrides).forEach((key) => delete overrides[Number(key)])
    query.merchant_id = props.merchant.id
    const state = await merchantApi.grants(props.merchant.id)
    state.games.forEach((item: any) => {
      overrides[Number(item.game_id)] = {
        ...item,
        status: Number(item.status),
        rate_percent: item.rate_value == null ? undefined : rateToPercent(item.rate_value)
      }
    })
    await loadGames()
  })
  const enable = (game: any) => {
    overrides[game.id] = { game_id: game.id, status: 0, rate_percent: undefined }
  }
  const save = async () => {
    saving.value = true
    try {
      await merchantApi.saveGrants({
        id: props.merchant.id,
        games: Object.values(overrides).map((item) => ({
          game_id: item.game_id,
          status: item.status,
          rate_value: item.rate_percent == null ? null : percentToRate(item.rate_percent),
          default_rtp: item.default_rtp ?? null
        }))
      })
      ElMessage.success(t('game.saveSuccess'))
      visible.value = false
    } finally {
      saving.value = false
    }
  }
</script>

<style scoped>
  .grant-dialog__body {
    max-height: calc(92vh - 130px);
    padding-right: 4px;
    overflow-y: auto;
  }
</style>
