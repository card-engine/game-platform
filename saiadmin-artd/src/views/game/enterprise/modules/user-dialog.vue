<template>
  <ElDialog
    v-model="visible"
    :title="`${enterprise?.name || ''} · ${$t('game.backendAccounts')}`"
    width="min(980px, 96vw)"
  >
    <div v-permission="'app:game:enterprise:user:save'" class="mb-5 border-b border-g-200 pb-4">
      <div class="mb-2 flex items-center gap-2 font-medium">
        {{ $t('game.newChildAccount') }}
        <ElTag size="small">{{ $t('game.enterpriseOwner') }}</ElTag>
      </div>
      <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-[150px_150px_150px_1fr_auto]">
        <ElInput v-model="form.username" :placeholder="$t('game.loginAccount')" />
        <ElInput v-model="form.realname" :placeholder="$t('game.displayName')" />
        <ElInput
          v-model="form.password"
          type="password"
          show-password
          :placeholder="$t('game.loginPassword')"
        />
        <ElSelect
          v-model="form.merchant_ids"
          multiple
          collapse-tags
          :max-collapse-tags="2"
          :placeholder="$t('game.accessibleMerchants')"
        >
          <ElOption v-for="item in merchants" :key="item.id" :label="item.name" :value="item.id" />
        </ElSelect>
        <ElButton type="primary" @click="add">{{ $t('game.create') }}</ElButton>
      </div>
    </div>

    <ElTable :data="users" v-loading="loading">
      <ElTableColumn :label="$t('game.account')" min-width="170">
        <template #default="{ row }">
          <div class="font-medium">{{ row.user.username }}</div>
          <div class="text-xs text-g-500">{{ row.user.realname || '-' }}</div>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="$t('game.type')" width="105">
        <template #default="{ row }">
          <ElTag :type="row.is_owner ? 'primary' : 'info'" size="small">
            {{ row.is_owner ? $t('game.owner') : $t('game.childAccount') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="$t('game.accessibleMerchants')" min-width="330">
        <template #default="{ row }">
          <span v-if="row.is_owner" class="text-sm text-g-600">{{
            $t('game.allEnterpriseMerchants')
          }}</span>
          <div v-else class="flex items-center gap-2">
            <ElSelect
              v-model="row.merchant_ids"
              multiple
              collapse-tags
              :max-collapse-tags="2"
              class="!w-60"
            >
              <ElOption
                v-for="item in merchants"
                :key="item.id"
                :label="item.name"
                :value="item.id"
              />
            </ElSelect>
            <ElButton link type="primary" @click="saveMerchants(row)">{{
              $t('game.save')
            }}</ElButton>
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="user.login_time" :label="$t('game.lastLogin')" min-width="170" />
      <ElTableColumn :label="$t('game.status')" width="80">
        <template #default="{ row }">
          <ElSwitch
            v-model="row.status"
            :disabled="Boolean(row.is_owner)"
            :active-value="1"
            :inactive-value="0"
            @change="(value) => change(row, Number(value))"
          />
        </template>
      </ElTableColumn>
      <ElTableColumn :label="$t('game.operation')" width="80" fixed="right">
        <template #default="{ row }">
          <ElButton v-if="!row.is_owner" link type="primary" @click="changePassword(row)">
            {{ $t('game.changePassword') }}
          </ElButton>
        </template>
      </ElTableColumn>
    </ElTable>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import enterpriseApi from '@/api/game/enterprise'
  import merchantApi from '@/api/game/merchant'

  const props = defineProps<{ modelValue: boolean; enterprise?: any }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const loading = ref(false)
  const { t } = useI18n()
  const users = ref<any[]>([])
  const merchants = ref<any[]>([])
  const form = reactive({
    username: '',
    realname: '',
    password: '',
    merchant_ids: [] as number[]
  })
  const load = async () => {
    if (!props.enterprise) return
    loading.value = true
    try {
      const [rows, options] = await Promise.all([
        enterpriseApi.users(props.enterprise.id),
        merchantApi.options()
      ])
      users.value = rows.map((row: any) => ({
        ...row,
        merchant_ids: row.merchants.map((item: any) => item.id)
      }))
      merchants.value = options.merchants.filter(
        (item: any) => item.enterprise_id === props.enterprise.id
      )
    } finally {
      loading.value = false
    }
  }
  watch(visible, (value) => value && load())
  const add = async () => {
    if (!form.username || form.password.length < 6 || !form.merchant_ids.length) {
      return ElMessage.warning(t('game.accountRequired'))
    }
    await enterpriseApi.saveUser({ enterprise_id: props.enterprise.id, ...form })
    Object.assign(form, { username: '', realname: '', password: '', merchant_ids: [] })
    ElMessage.success(t('game.childCreated'))
    await load()
  }
  const saveMerchants = async (row: any) => {
    if (!row.merchant_ids.length) return ElMessage.warning(t('game.merchantRequired'))
    await enterpriseApi.userMerchants({ id: row.id, merchant_ids: row.merchant_ids })
    ElMessage.success(t('game.scopeUpdated'))
  }
  const change = async (row: any, status: number) => {
    await enterpriseApi.userStatus({ id: row.id, status })
    ElMessage.success(t('game.statusUpdated'))
  }
  const changePassword = async (row: any) => {
    const { value } = await ElMessageBox.prompt(
      t('game.setPassword', { account: row.user.username }),
      t('game.changePassword'),
      {
        inputType: 'password',
        inputValidator: (password) => password.length >= 6 || t('game.passwordMinSix')
      }
    )
    await enterpriseApi.userPassword({ id: row.id, password: value })
    ElMessage.success(t('game.passwordUpdated'))
  }
</script>
