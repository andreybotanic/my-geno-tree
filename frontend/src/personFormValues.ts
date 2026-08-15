import type { DateKind, GenealogicalDate } from './api/generated'

export type DateInputState = {
  day: string
  endDay: string
  endMonth: string
  endYear: string
  kind: DateKind
  month: string
  unknown: boolean
  year: string
}

export const emptyDate = (): DateInputState => ({
  day: '',
  endDay: '',
  endMonth: '',
  endYear: '',
  kind: 'exact',
  month: '',
  unknown: false,
  year: '',
})

export const unknownDate = (): DateInputState => ({ ...emptyDate(), unknown: true })

/**
 * Returns the next display value for a day field, or null when the attempted
 * edit cannot be a valid day prefix.
 */
export function dateDayInputValue(
  value: string,
  yearValue: string,
  monthValue: string,
): string | null {
  const digits = value.replace(/\D/g, '')
  if (digits.length > 2 || monthValue === '') {
    return null
  }
  if (digits === '') {
    return ''
  }

  const month = Number(monthValue)
  if (!Number.isInteger(month) || month < 1 || month > 12) {
    return null
  }
  const maximum = daysInMonth(yearValue, month)
  if (digits.length === 1) {
    return Number(digits) <= Math.floor(maximum / 10) ? digits : null
  }

  const day = Number(digits)

  return day >= 1 && day <= maximum ? digits : null
}

export function isValidDateDay(
  value: string,
  yearValue: string,
  monthValue: string,
) {
  if (value === '') {
    return true
  }
  if (!/^\d{1,2}$/.test(value) || monthValue === '') {
    return false
  }

  const month = Number(monthValue)
  const day = Number(value)

  return Number.isInteger(month)
    && month >= 1
    && month <= 12
    && day >= 1
    && day <= daysInMonth(yearValue, month)
}

export function dateValue(state: DateInputState): GenealogicalDate | null {
  if (state.unknown) {
    return { state: 'unknown' }
  }
  const start = dateParts(state.year, state.month, state.day)
  if (!start) {
    return null
  }
  if (state.kind !== 'between') {
    return { state: 'known', kind: state.kind, ...start }
  }
  const end = dateParts(state.endYear, state.endMonth, state.endDay)
  if (!end || compareStartToEnd(start, end) > 0) {
    return null
  }

  return { state: 'known', kind: 'between', ...start, end }
}

function dateParts(yearValue: string, monthValue: string, dayValue: string) {
  const year = Number(yearValue)
  if (!Number.isInteger(year) || year < 1582 || year > 9999) {
    return null
  }
  if (monthValue === '') {
    return dayValue === '' ? { year } : null
  }
  const month = Number(monthValue)
  if (!Number.isInteger(month) || month < 1 || month > 12) {
    return null
  }
  if (dayValue === '') {
    return { year, month }
  }
  const day = Number(dayValue)
  if (!Number.isInteger(day) || !Number.isInteger(month) || !checkDate(year, month, day)) {
    return null
  }

  return { year, month, day }
}

function checkDate(year: number, month: number, day: number) {
  const value = new Date(Date.UTC(year, month - 1, day))

  return value.getUTCFullYear() === year
    && value.getUTCMonth() === month - 1
    && value.getUTCDate() === day
}

function daysInMonth(yearValue: string, month: number) {
  if (month === 2) {
    const year = Number(yearValue)

    return Number.isInteger(year) && year >= 1582 && year <= 9999 && !isLeapYear(year)
      ? 28
      : 29
  }

  return [4, 6, 9, 11].includes(month) ? 30 : 31
}

function isLeapYear(year: number) {
  return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)
}

function compareStartToEnd(
  start: { year: number, month?: number, day?: number },
  end: { year: number, month?: number, day?: number },
) {
  const startTime = Date.UTC(start.year, (start.month ?? 1) - 1, start.day ?? 1)
  const endTime = end.day
    ? Date.UTC(end.year, (end.month ?? 1) - 1, end.day)
    : end.month
      ? Date.UTC(end.year, end.month, 0)
      : Date.UTC(end.year, 11, 31)

  return startTime - endTime
}
