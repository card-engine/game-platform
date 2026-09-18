<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeft } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { legalDocument, type LegalType } from '../legal-content'

const props = defineProps<{ type: LegalType }>()
const { locale, t } = useI18n()
const document = computed(() => legalDocument(locale.value, props.type))
</script>

<template>
  <article class="legal-page">
    <RouterLink class="legal-page__back" to="/me"><ArrowLeft :size="18" />{{ t('legal.back') }}</RouterLink>
    <header>
      <span>MGames</span>
      <h1>{{ document.title }}</h1>
      <small>{{ t('legal.updated') }}: {{ document.updated }}</small>
      <p>{{ document.intro }}</p>
    </header>
    <section v-for="section in document.sections" :key="section.title">
      <h2>{{ section.title }}</h2>
      <p v-for="paragraph in section.paragraphs" :key="paragraph">{{ paragraph }}</p>
    </section>
  </article>
</template>
