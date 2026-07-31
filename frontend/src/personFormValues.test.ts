import { describe, expect, it } from 'vitest'
import {
  dateValue,
  emptyDate,
  unknownDate,
  type DateInputState,
} from './personFormValues'

const knownDate = (
  overrides: Partial<DateInputState> = {},
): DateInputState => ({
  approximate: false,
  day: '',
  month: '',
  precision: 'year',
  unknown: false,
  year: '1900',
  ...overrides,
})

describe('dateValue', () => {
  it('preserves an explicitly unknown date', () => {
    expect(emptyDate()).toEqual({
      approximate: false,
      day: '',
      month: '',
      precision: 'day',
      unknown: false,
      year: '',
    })
    expect(unknownDate()).toEqual({
      approximate: false,
      day: '',
      month: '',
      precision: 'day',
      unknown: true,
      year: '',
    })
    expect(dateValue(unknownDate())).toEqual({ state: 'unknown' })
  })

  it('accepts exact and approximate years in the supported range', () => {
    expect(dateValue(knownDate({ approximate: true }))).toEqual({
      state: 'known',
      precision: 'year',
      year: 1900,
      approximate: true,
    })
    expect(dateValue(knownDate({ year: '1582' }))).not.toBeNull()
    expect(dateValue(knownDate({ year: '9999' }))).not.toBeNull()
  })

  it.each(['', '1581', '10000', '1900.5'])(
    'rejects invalid year %s',
    (year) => {
      expect(dateValue(knownDate({ year }))).toBeNull()
    },
  )

  it('accepts a month and removes approximation', () => {
    expect(dateValue(knownDate({
      approximate: true,
      month: '12',
      precision: 'month',
    }))).toEqual({
      state: 'known',
      precision: 'month',
      year: 1900,
      month: 12,
      approximate: false,
    })
  })

  it.each(['', '0', '13', '1.5'])(
    'rejects invalid month %s',
    (month) => {
      expect(dateValue(knownDate({
        month,
        precision: 'month',
      }))).toBeNull()
    },
  )

  it('accepts a valid calendar day, including a leap day', () => {
    expect(dateValue(knownDate({
      day: '29',
      month: '2',
      precision: 'day',
      year: '2000',
    }))).toEqual({
      state: 'known',
      precision: 'day',
      year: 2000,
      month: 2,
      day: 29,
      approximate: false,
    })
  })

  it.each([
    ['', '1', '1900'],
    ['0', '1', '1900'],
    ['32', '1', '1900'],
    ['1.5', '1', '1900'],
    ['29', '2', '1900'],
    ['31', '4', '1900'],
  ])('rejects invalid calendar day %s-%s-%s', (day, month, year) => {
    expect(dateValue(knownDate({
      day,
      month,
      precision: 'day',
      year,
    }))).toBeNull()
  })
})
