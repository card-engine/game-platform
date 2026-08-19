<template>
  <SaSearchBar
    ref="searchBarRef"
    v-model="form"
    label-width="76px"
    :show-expand="false"
    @reset="reset"
    @search="search"
  >
    <slot />
  </SaSearchBar>
</template>

<script setup lang="ts">
  const props = defineProps<{ modelValue: Record<string, any> }>()
  const emit = defineEmits<{
    'update:modelValue': [value: Record<string, any>]
    search: []
    reset: []
  }>()
  const searchBarRef = ref()
  const form = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })
  const search = () => emit('search')
  const reset = () => {
    searchBarRef.value?.ref?.resetFields()
    emit('reset')
  }
</script>
