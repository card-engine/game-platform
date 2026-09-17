<script setup lang="ts">
  const form = defineModel<{ keyword: string; status: string; currency_code: string }>({
    required: true
  })
  defineEmits<{ search: []; reset: [] }>()
</script>

<template>
  <SaSearchBar
    v-model="form"
    :show-expand="false"
    @search="$emit('search')"
    @reset="$emit('reset')"
  >
    <ElCol :xs="24" :sm="8"
      ><ElFormItem :label="$t('mgsRecharge.rechargeNo')"
        ><ElInput v-model="form.keyword" clearable /></ElFormItem
    ></ElCol>
    <ElCol :xs="24" :sm="8"
      ><ElFormItem :label="$t('mgs.status')"
        ><ElSelect v-model="form.status" clearable
          ><ElOption
            v-for="status in ['pending', 'paid', 'expired', 'review', 'closed']"
            :key="status"
            :label="$t(`mgsRecharge.${status}`)"
            :value="status" /></ElSelect></ElFormItem
    ></ElCol>
    <ElCol :xs="24" :sm="8"
      ><ElFormItem :label="$t('mgs.currency')"
        ><ElInput v-model="form.currency_code" clearable /></ElFormItem
    ></ElCol>
  </SaSearchBar>
</template>
