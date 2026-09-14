<script setup lang="ts">
import { ref, watch } from 'vue'
import { KeyRound } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { useAccessStore } from '../stores/access'

const open = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()
const access = useAccessStore()
const { t } = useI18n()
const password = ref('')
const error = ref(false)

watch(open, (value) => {
  if (!value) {
    password.value = ''
    error.value = false
  }
})

function submit() {
  if (!access.verify(password.value)) {
    password.value = ''
    error.value = true
    return
  }
  open.value = false
  emit('success')
}
</script>

<template>
  <el-dialog
    v-model="open"
    class="access-dialog"
    width="min(420px, calc(100vw - 32px))"
    :close-on-click-modal="false"
    align-center
  >
    <div class="access-dialog__heading">
      <span class="access-dialog__icon"><KeyRound :size="22" /></span>
      <div>
        <h2>{{ t('access.title') }}</h2>
        <p>{{ t('access.hint') }}</p>
      </div>
    </div>

    <el-input
      v-model="password"
      type="password"
      size="large"
      show-password
      autofocus
      :placeholder="t('access.password')"
      :aria-label="t('access.password')"
      @keyup.enter="submit"
    />
    <p v-if="error" class="access-dialog__error" role="alert">{{ t('access.wrong') }}</p>

    <template #footer>
      <el-button @click="open = false">{{ t('access.cancel') }}</el-button>
      <el-button type="primary" @click="submit">{{ t('access.unlock') }}</el-button>
    </template>
  </el-dialog>
</template>
