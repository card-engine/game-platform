import assert from 'node:assert/strict'
import { computed } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { useGameTime } from '../src/composables/useGameTime'

Object.defineProperty(globalThis, 'localStorage', { value: { getItem: () => null } })
setActivePinia(createPinia())
const { timezone, formatTime, formatJson } = useGameTime()
const time = '2026-09-18 23:37:31.952'
const display = computed(() => formatTime(time))
assert.equal(display.value, '2026-09-18 23:37:31')
timezone.value = 'Asia/Kolkata'
assert.equal(display.value, '2026-09-19 05:07:31')
assert.equal(formatTime(time, 'time'), '05:07:31')
assert.equal(formatTime(time, 'short'), '09-19 05:07:31')
assert.equal(formatTime('2026-09-19T07:37:31.952+08:00'), display.value)
assert.equal(formatTime(Date.parse('2026-09-18T23:37:31.952Z') / 1000), display.value)
assert.equal(formatTime(new Date('2026-09-18T23:37:31.952Z')), display.value)

// 浏览器所在时区不应影响 UTC 解析；夏令时由 IANA 规则处理。
timezone.value = 'America/Los_Angeles'
assert.equal(formatTime('2026-03-08 09:59:59'), '2026-03-08 01:59:59')
assert.equal(formatTime('2026-03-08 10:00:00'), '2026-03-08 03:00:00')
assert.equal(formatTime('2026-11-01 08:30:00'), '2026-11-01 01:30:00')
assert.equal(formatTime('2026-11-01 09:30:00'), '2026-11-01 01:30:00')
assert.equal(formatTime('2026-01-01 00:00:00'), '2025-12-31 16:00:00')
for (const empty of [null, undefined, '', 'invalid']) assert.equal(formatTime(empty), '—')
timezone.value = 'UTC'
assert.equal(formatTime(0), '1970-01-01 00:00:00')
assert.equal(formatTime('2026-09-19 00:00:00'), '2026-09-19 00:00:00')

// 渲染详情不改变原对象、统计日期、月份、Unix 数值或日志正文。
const source = {
  create_time: time,
  stat_date: '2026-09-18',
  settlement_month: '2026-09',
  timestamp: 1789760251,
  message: '2026-09-18 23:37:31 request completed',
  actions: [{ time }]
}
const original = JSON.stringify(source)
timezone.value = 'Asia/Tokyo'
const rendered = JSON.parse(formatJson(source))
assert.equal(rendered.create_time, '2026-09-19 08:37:31 Asia/Tokyo')
assert.equal(rendered.actions[0].time, rendered.create_time)
for (const key of ['stat_date', 'settlement_month', 'timestamp', 'message'])
  assert.equal(rendered[key], source[key as keyof typeof source])
assert.equal(JSON.stringify(source), original)
console.log(
  'Time display smoke passed: reactive timezone, UTC/offset/Unix, DST, immutable details.'
)
