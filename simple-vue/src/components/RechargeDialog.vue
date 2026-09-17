<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { createRecharge, getCurrentRecharge, getRecharge, getRechargeOptions } from '../api/game'
import type { RechargeOptions, RechargeOrder } from '../api/game'

const props = defineProps<{ currency: string; userId: string }>()
const emit = defineEmits<{ close: []; paid: [] }>()
const { t } = useI18n()
const options = ref<RechargeOptions>()
const order = ref<RechargeOrder | null>(null)
const amount = ref(100)
const payCurrency = ref('USDT')
const busy = ref(true)
const error = ref('')
const payment = computed(() => options.value?.payments.find((item) => item.pay_currency_code === payCurrency.value))
const storageKey = `mgs-recharge:${props.userId}:${props.currency}`
let timer: ReturnType<typeof setTimeout> | undefined
let disposed = false

async function load(newOrder = false) {
  clearTimeout(timer)
  busy.value = true
  error.value = ''
  try {
    if (newOrder) {
      localStorage.removeItem(`${storageKey}:order`)
      order.value = null
    }
    const current = await getCurrentRecharge(props.currency)
    const previous = localStorage.getItem(`${storageKey}:order`)
    order.value = current || (previous ? await getRecharge(previous) : null)
    options.value = await getRechargeOptions(props.currency)
    amount.value = options.value.default_amount
    payCurrency.value = options.value.payments[0]?.pay_currency_code || 'USDT'
    if (order.value?.status === 'paid' && !disposed) emit('paid')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('recharge.failed')
  } finally {
    busy.value = false
    if (!disposed && !document.hidden && order.value?.status === 'pending') timer = setTimeout(refresh, 5000)
  }
}

async function submit() {
  if (busy.value || !options.value?.available || !payment.value) return
  busy.value = true
  error.value = ''
  // 同一账号、币种和档位的网络重试复用请求编号，刷新页面也不丢失。
  const requestKey = `${storageKey}:request:${amount.value}:${payCurrency.value}`
  const requestId = localStorage.getItem(requestKey) || crypto.randomUUID()
  localStorage.setItem(requestKey, requestId)
  try {
    order.value = await createRecharge({ currency_code: props.currency, recharge_amount: amount.value,
      pay_currency_code: payCurrency.value, request_id: requestId, quote_key: payment.value.quote_key })
    localStorage.setItem(`${storageKey}:order`, order.value.order_no)
    localStorage.removeItem(requestKey)
    if (order.value.status === 'paid' && !disposed) emit('paid')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('recharge.failed')
  } finally {
    busy.value = false
    if (!disposed && !document.hidden && order.value?.status === 'pending') timer = setTimeout(refresh, 5000)
  }
}

async function refresh() {
  clearTimeout(timer)
  if (busy.value || disposed || document.hidden || !order.value) return
  busy.value = true
  try {
    order.value = await getRecharge(order.value.order_no)
    error.value = ''
    if (order.value.status === 'paid' && !disposed) emit('paid')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('recharge.failed')
  } finally {
    busy.value = false
    if (!disposed && !document.hidden && order.value?.status === 'pending') timer = setTimeout(refresh, 5000)
  }
}

function visibilityChanged() {
  clearTimeout(timer)
  if (!document.hidden) void refresh()
}

onMounted(() => {
  document.addEventListener('visibilitychange', visibilityChanged)
  void load()
})
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(timer)
  document.removeEventListener('visibilitychange', visibilityChanged)
})
</script>

<template>
  <el-dialog :model-value="true" :title="t('recharge.title')" width="min(92vw, 460px)" @close="emit('close')">
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <template v-if="order">
      <p>{{ order.order_no }}</p>
      <p>{{ t('recharge.credit') }}: {{ order.recharge_amount }} {{ order.currency_code }}</p>
      <p>{{ t(`recharge.${order.status}`) }}</p>
      <template v-if="order.status === 'pending'">
        <p>{{ t('recharge.pay') }}: <strong>{{ order.pay_amount }} {{ order.pay_currency_code }}</strong></p>
        <p class="recharge-address">{{ order.receive_address }}</p>
        <p>{{ t('recharge.network') }}</p>
        <p>{{ t('recharge.expires') }}: {{ new Date(order.expire_time).toLocaleString() }}</p>
      </template>
      <el-button :loading="busy" @click="refresh">{{ t('recharge.refresh') }}</el-button>
      <el-button v-if="['paid', 'expired', 'closed'].includes(order.status)" :disabled="busy" @click="load(true)">
        {{ t('recharge.newOrder') }}
      </el-button>
    </template>
    <template v-else>
      <p v-if="options && !options.available">{{ options.unavailable_reason }}</p>
      <div class="recharge-amounts">
        <button v-for="value in options?.amounts" :key="value" type="button" :disabled="busy || !options?.available"
          :class="{ active: amount === value }" :aria-pressed="amount === value" @click="amount = value">{{ value }}</button>
      </div>
      <el-radio-group v-model="payCurrency" :disabled="busy">
        <el-radio v-for="item in options?.payments" :key="item.pay_currency_code" :value="item.pay_currency_code">{{ item.pay_currency_code }}</el-radio>
      </el-radio-group>
      <p>{{ t('recharge.credit') }}: {{ amount }} {{ currency }}</p>
      <p v-if="payment">{{ t('recharge.estimate') }}: {{ payment.amounts[String(amount)] }} {{ payCurrency }}</p>
      <el-button type="primary" :loading="busy" :disabled="!options?.available || !payment" @click="submit">{{ t('recharge.create') }}</el-button>
      <el-button :disabled="busy" @click="load()">{{ t('recharge.refresh') }}</el-button>
      <el-button v-if="error" :disabled="busy" @click="load(true)">{{ t('recharge.newOrder') }}</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.recharge-address { overflow-wrap: anywhere; font-family: monospace; }
.recharge-amounts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 18px 0; }
.recharge-amounts button { padding: 9px 4px; border: 1px solid var(--line); border-radius: 6px; color: var(--text); background: var(--surface-2); cursor: pointer; }
.recharge-amounts button.active { border-color: var(--accent); color: var(--accent); }
.recharge-amounts button:disabled { cursor: not-allowed; opacity: .5; }
</style>
