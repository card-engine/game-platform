<template>
  <ElDialog
    v-model="visible"
    :title="dialogType === 'add' ? $t('game.newPlatformAccount') : $t('game.editPlatformAccount')"
    width="min(620px, 94vw)"
    @closed="formRef?.resetFields()"
  >
    <ElForm ref="formRef" :model="form" :rules="rules" label-width="100px">
      <ElFormItem :label="$t('game.accountType')" prop="role_code">
        <ElSelect
          v-model="form.role_code"
          :disabled="dialogType === 'edit'"
          class="w-full"
          @change="changeRole"
        >
          <ElOption
            v-for="item in options.roles"
            :key="item.value"
            :label="roleLabel(item.value)"
            :value="item.value"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem
        v-if="form.role_code !== 'game_super_admin'"
        :label="$t('game.ownerEnterprise')"
        prop="enterprise_id"
      >
        <ElSelect
          v-model="form.enterprise_id"
          :disabled="dialogType === 'edit' && form.role_code === 'enterprise_owner'"
          filterable
          class="w-full"
          @change="form.merchant_ids = []"
        >
          <ElOption
            v-for="item in availableEnterprises"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem
        v-if="form.role_code === 'enterprise_staff'"
        :label="$t('game.accessibleMerchants')"
        prop="merchant_ids"
      >
        <ElSelect v-model="form.merchant_ids" multiple filterable collapse-tags class="w-full">
          <ElOption
            v-for="item in availableMerchants"
            :key="item.id"
            :label="`${item.name} (${item.mch_id})`"
            :value="item.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="$t('game.loginAccount')" prop="username">
        <ElInput v-model="form.username" autocomplete="off" />
      </ElFormItem>
      <ElFormItem :label="$t('game.displayName')" prop="realname">
        <ElInput v-model="form.realname" />
      </ElFormItem>
      <ElFormItem v-if="dialogType === 'add'" :label="$t('game.loginPassword')" prop="password">
        <ElInput
          v-model="form.password"
          type="password"
          show-password
          autocomplete="new-password"
        />
      </ElFormItem>
      <ElFormItem :label="$t('game.status')">
        <ElSwitch
          v-model="form.status"
          :active-value="1"
          :inactive-value="2"
          :disabled="dialogType === 'edit' && Boolean(data?.protected || data?.current)"
        />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ $t('game.cancel') }}</ElButton>
      <ElButton type="primary" :loading="saving" @click="submit">{{ $t('game.save') }}</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import api from '@/api/game/platform-admin'

  const props = defineProps<{
    modelValue: boolean
    dialogType: string
    data?: any
    options: { roles: any[]; enterprises: any[]; merchants: any[] }
  }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; success: [] }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const formRef = ref<FormInstance>()
  const saving = ref(false)
  const { t } = useI18n()
  const form = reactive<any>({
    id: undefined,
    role_code: 'enterprise_staff',
    enterprise_id: undefined,
    merchant_ids: [],
    username: '',
    realname: '',
    password: '',
    status: 1
  })
  const rules: FormRules = {
    role_code: [{ required: true, message: t('game.selectAccountType') }],
    username: [{ required: true, message: t('game.pleaseEnter') + t('game.loginAccount') }],
    password: [{ required: true, min: 6, message: t('game.passwordMinSix') }],
    enterprise_id: [
      {
        validator: (_rule, value, callback) =>
          form.role_code === 'game_super_admin' || value
            ? callback()
            : callback(new Error(t('game.selectEnterprise')))
      }
    ],
    merchant_ids: [
      {
        validator: (_rule, value, callback) =>
          form.role_code !== 'enterprise_staff' || value.length
            ? callback()
            : callback(new Error(t('game.merchantRequired')))
      }
    ]
  }
  const availableEnterprises = computed(() =>
    props.options.enterprises.filter(
      (item) =>
        form.role_code !== 'enterprise_owner' ||
        !item.has_owner ||
        Number(item.id) === Number(props.data?.enterprise_id)
    )
  )
  const availableMerchants = computed(() =>
    props.options.merchants.filter(
      (item) => Number(item.enterprise_id) === Number(form.enterprise_id)
    )
  )
  const roleLabel = (code: string) =>
    code === 'game_super_admin'
      ? t('game.platformSuperAdmin')
      : code === 'enterprise_owner'
        ? t('game.enterpriseOwner')
        : t('game.enterpriseStaff')
  const changeRole = () => {
    form.enterprise_id = availableEnterprises.value[0]?.id
    form.merchant_ids = []
  }

  watch(visible, (value) => {
    if (!value) return
    Object.assign(form, {
      id: props.data?.id,
      role_code: props.data?.role_code || 'enterprise_staff',
      enterprise_id: props.data?.enterprise_id || props.options.enterprises[0]?.id,
      merchant_ids: [...(props.data?.merchant_ids || [])],
      username: props.data?.username || '',
      realname: props.data?.realname || '',
      password: '',
      status: props.data?.status || 1
    })
  })

  const submit = async () => {
    await formRef.value?.validate()
    saving.value = true
    try {
      await (props.dialogType === 'add' ? api.save(form) : api.update(form))
      visible.value = false
      emit('success')
    } finally {
      saving.value = false
    }
  }
</script>
