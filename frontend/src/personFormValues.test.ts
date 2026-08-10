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
  day: '',
  endDay: '',
  endMonth: '',
  endYear: '',
  kind: 'exact',
  month: '',
  unknown: false,
  year: '1900',
  ...overrides,
})

describe('dateValue', () => {
  it('preserves an explicitly unknown date', () => {
    expect(emptyDate()).toEqual({
      day: '',
      endDay: '',
      endMonth: '',
      endYear: '',
      kind: 'exact',
      month: '',
      unknown: false,
      year: '',
    })
    expect(unknownDate()).toEqual({
      day: '',
      endDay: '',
      endMonth: '',
      endYear: '',
      kind: 'exact',
      month: '',
      unknown: true,
      year: '',
    })
    expect(dateValue(unknownDate())).toEqual({ state: 'unknown' })
  })

  it('accepts exact and approximate years in the supported range', () => {
    expect(dateValue(knownDate({ kind: 'approximate' }))).toEqual({
      state: 'known',
      kind: 'approximate',
      year: 1900,
    })
    expect(dateValue(knownDate({ year: '1582' }))).not.toBeNull()
    expect(dateValue(knownDate({ year: '9999' }))).not.toBeNull()
  })

  it.each(['before', 'after'] as const)(
    'serializes a %s bound without an end date',
    (kind) => {
      expect(dateValue(knownDate({ kind }))).toEqual({
        state: 'known',
        kind,
        year: 1900,
      })
    },
  )

  it.each(['', '1581', '10000', '1900.5'])(
    'rejects invalid year %s',
    (year) => {
      expect(dateValue(knownDate({ year }))).toBeNull()
    },
  )

  it('accepts a month for an approximate date', () => {
    expect(dateValue(knownDate({
      kind: 'approximate',
      month: '12',
    }))).toEqual({
      state: 'known',
      kind: 'approximate',
      year: 1900,
      month: 12,
    })
  })

  it.each(['0', '13', '1.5'])(
    'rejects invalid month %s',
    (month) => {
      expect(dateValue(knownDate({
        month,
      }))).toBeNull()
    },
  )

  it('accepts a valid calendar day, including a leap day', () => {
    expect(dateValue(knownDate({
      day: '29',
      month: '2',
      year: '2000',
    }))).toEqual({
      state: 'known',
      kind: 'exact',
      year: 2000,
      month: 2,
      day: 29,
    })
  })

  it.each([
    ['0', '1', '1900'],
    ['32', '1', '1900'],
    ['1.5', '1', '1900'],
    ['29', '2', '1900'],
    ['31', '4', '1900'],
  ])('rejects invalid calendar day %s-%s-%s', (day, month, year) => {
    expect(dateValue(knownDate({
      day,
      month,
      year,
    }))).toBeNull()
  })

  it('allows a partial date without a day', () => {
    expect(dateValue(knownDate({ month: '1' }))).toEqual({
      state: 'known',
      kind: 'exact',
      year: 1900,
      month: 1,
    })
  })

  it('does not allow a day without a month', () => {
    expect(dateValue(knownDate({ day: '1' }))).toBeNull()
  })

  it('requires and serializes an ordered end date for a between range', () => {
    expect(dateValue(knownDate({
      kind: 'between',
      endYear: '1901',
    }))).toEqual({
      state: 'known',
      kind: 'between',
      year: 1900,
      end: { year: 1901 },
    })
    expect(dateValue(knownDate({ kind: 'between' }))).toBeNull()
    expect(dateValue(knownDate({
      kind: 'between',
      endYear: '1899',
    }))).toBeNull()
  })

  it('allows independently partial endpoints in a between range', () => {
    expect(dateValue(knownDate({
      day: '31',
      endMonth: '2',
      endYear: '1901',
      kind: 'between',
      month: '1',
    }))).toEqual({
      state: 'known',
      kind: 'between',
      year: 1900,
      month: 1,
      day: 31,
      end: {
        year: 1901,
        month: 2,
      },
    })
  })

  it('compares a full end date in a between range', () => {
    expect(dateValue(knownDate({
      endDay: '1',
      endMonth: '1',
      endYear: '1900',
      kind: 'between',
    }))).toEqual({
      state: 'known',
      kind: 'between',
      year: 1900,
      end: {
        year: 1900,
        month: 1,
        day: 1,
      },
    })
  })
})
