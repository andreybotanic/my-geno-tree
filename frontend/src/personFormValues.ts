import type { GenealogicalDate } from './api/generated'

export type DateInputState = {
  approximate: boolean
  day: string
  month: string
  precision: 'day' | 'month' | 'year'
  unknown: boolean
  year: string
}

export const unknownDate = (): DateInputState => ({
  approximate: false,
  day: '',
  month: '',
  precision: 'year',
  unknown: true,
  year: '',
})

export function dateValue(state: DateInputState): GenealogicalDate | null {
  if (state.unknown) {
    return { state: 'unknown' }
  }

  const year = Number(state.year)
  const month = Number(state.month)
  const day = Number(state.day)
  if (!Number.isInteger(year) || year < 1582 || year > 9999) {
    return null
  }
  if (state.precision === 'year') {
    return {
      state: 'known',
      precision: 'year',
      year,
      approximate: state.approximate,
    }
  }
  if (!Number.isInteger(month) || month < 1 || month > 12) {
    return null
  }
  if (state.precision === 'month') {
    return {
      state: 'known',
      precision: 'month',
      year,
      month,
      approximate: false,
    }
  }

  const candidate = new Date(Date.UTC(year, month - 1, day))
  if (
    !Number.isInteger(day)
    || day < 1
    || candidate.getUTCFullYear() !== year
    || candidate.getUTCMonth() !== month - 1
    || candidate.getUTCDate() !== day
  ) {
    return null
  }

  return {
    state: 'known',
    precision: 'day',
    year,
    month,
    day,
    approximate: false,
  }
}
