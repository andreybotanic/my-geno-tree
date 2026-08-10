import { describe, expect, it } from 'vitest'
import type { GenealogicalDate, GenealogicalEvent } from './api/generated'
import {
  ambiguousEventGroups,
  canMoveEvent,
  datesAreAmbiguous,
} from './eventTimeline'

const date = (
  year: number,
  month?: number,
  day?: number,
): GenealogicalDate => day
  ? {
      state: 'known',
      year,
      month: month ?? 1,
      day,
      kind: 'exact',
    }
  : month
    ? {
        state: 'known',
        year,
        month,
        kind: 'exact',
      }
    : {
        state: 'known',
        year,
        kind: 'exact',
      }

const event = (id: string, eventDate: GenealogicalDate): GenealogicalEvent => ({
  id,
  personId: 'person',
  type: 'custom',
  source: 'manual',
  date: eventDate,
  place: null,
  title: id,
  description: null,
  data: {},
  sourceEntityType: null,
  sourceEntityId: null,
  manualOrder: null,
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
})

describe('event timeline ambiguity', () => {
  it('recognizes overlapping precision ranges and unknown dates', () => {
    expect(datesAreAmbiguous(date(1900), date(1900, 6, 10))).toBe(true)
    expect(datesAreAmbiguous(date(1900, 6), date(1900, 6, 10))).toBe(true)
    expect(datesAreAmbiguous(date(1900, 5), date(1900, 6, 10))).toBe(false)
    expect(datesAreAmbiguous(
      { state: 'unknown' },
      date(1900, 6, 10),
    )).toBe(true)
    expect(datesAreAmbiguous(
      {
        state: 'known',
        year: 1900,
        kind: 'approximate',
      },
      date(2000, 1, 1),
    )).toBe(true)
  })

  it('returns only contiguous pairwise-ambiguous ordering groups', () => {
    const first = event('first', date(1900))
    const second = event('second', date(1900, 5))
    const third = event('third', date(1901, 1, 1))
    const fourth = event('fourth', { state: 'unknown' })
    const fifth = event('fifth', date(2000, 1, 1))

    expect(ambiguousEventGroups([first, second, third, fourth, fifth]))
      .toEqual([[first, second], [third, fourth]])
  })

  it('moves only imprecise events or events from the same exact day', () => {
    const exactDay = date(1900, 6, 10)
    const anotherExactDay = date(1900, 6, 10)

    expect(canMoveEvent({ state: 'unknown' }, exactDay)).toBe(true)
    expect(canMoveEvent(exactDay, { state: 'unknown' })).toBe(false)
    expect(canMoveEvent(exactDay, anotherExactDay)).toBe(true)
    expect(canMoveEvent(exactDay, date(1900, 6, 11))).toBe(false)
  })

  it('uses one-sided and closed ranges when comparing events', () => {
    expect(datesAreAmbiguous(
      { state: 'known', kind: 'before', year: 1900 },
      date(1900, 1, 1),
    )).toBe(false)
    expect(datesAreAmbiguous(
      { state: 'known', kind: 'after', year: 1900 },
      date(1900, 12, 31),
    )).toBe(false)
    expect(datesAreAmbiguous(
      {
        state: 'known',
        kind: 'between',
        year: 1900,
        end: { year: 1901 },
      },
      date(1902),
    )).toBe(false)
  })
})
