const formatDate = (date: Date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

export const recentMonthRange = () => {
  const end = new Date()
  const start = new Date(end)
  start.setDate(start.getDate() - 30)
  return [formatDate(start), formatDate(end)]
}

export const currentMonthRange = () => {
  const end = new Date()
  return [formatDate(new Date(end.getFullYear(), end.getMonth(), 1)), formatDate(end)]
}
