import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useGameStore } from '@/store/modules/game'

type TimeValue = string | number | Date | null | undefined

/** 只格式化展示值：数据库时间按 UTC 解析，数字为 Unix 秒，不改接口原始数据。 */
export function useGameTime() {
  const { timezone } = storeToRefs(useGameStore())
  const formatter = computed(
    () =>
      new Intl.DateTimeFormat('sv-SE', {
        timeZone: timezone.value,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
      })
  )
  const formatTime = (value: TimeValue, part: 'datetime' | 'time' | 'short' = 'datetime') => {
    if (value === null || value === undefined || value === '') return '—'
    const date =
      typeof value === 'string'
        ? new Date(value.replace(' ', 'T') + (/(Z|[+-]\d{2}:?\d{2})$/i.test(value) ? '' : 'Z'))
        : new Date(typeof value === 'number' ? value * 1000 : value)
    if (Number.isNaN(date.getTime())) return '—'
    const text = formatter.value.format(date)
    return part === 'time' ? text.slice(11) : part === 'short' ? text.slice(5) : text
  }
  // 详情 JSON 也只在渲染时转换完整时间字符串，日期、月份和原始数值保持不变。
  const formatJson = (data: unknown) =>
    JSON.stringify(
      data,
      (_key, value) =>
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/i.test(value)
          ? `${formatTime(value)} ${timezone.value}`
          : value,
      2
    )
  return { timezone, formatTime, formatJson }
}
