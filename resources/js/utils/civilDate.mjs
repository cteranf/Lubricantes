// Civil form dates retain their calendar day; no instant or timezone conversion.
export function toDateInput(value) {
  return parseCivilDate(value).value
}

export function parseCivilDate(value) {
  const result = (state, normalized = '') => Object.freeze({ state, value: normalized })
  if (value === null || value === undefined || value === '') return result('empty')
  const normalized = normalizeCivilDate(value)
  return normalized === '' ? result('invalid') : result('valid', normalized)
}

function normalizeCivilDate(value) {
  if (typeof value !== 'string' || value === '') return ''
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ]([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,6})?(?:Z|([+-])(0\d|1[0-4]):([0-5]\d))?)?$/.exec(value)
  if (!match) return ''
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (year < 1 || month < 1 || month > 12 || (match[8] === '14' && match[9] !== '00')) return ''
  const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)
  const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
  if (day < 1 || day > days[month - 1]) return ''
  return `${match[1]}-${match[2]}-${match[3]}`
}

export function hydrateCivilDates(record, fields) {
  const values = { ...record }
  const states = {}
  for (const field of fields) {
    const parsed = parseCivilDate(record[field])
    values[field] = parsed.value
    states[field] = parsed.state
  }
  return { values, states }
}

export function civilDatePayload(form, states) {
  const payload = { ...form }
  for (const [field, state] of Object.entries(states)) {
    if (state === 'invalid') delete payload[field]
  }
  return payload
}
