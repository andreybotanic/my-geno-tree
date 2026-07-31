import { describe, expect, it } from 'vitest'
import type { Person } from './api/generated'
import {
  formatGenealogicalDate,
  formatLifePeriod,
  formatPersonName,
} from './personFormatting'

const translate = (key: string) => ({
  'person.approximateYear': 'c. {{year}}',
  'person.present': 'present',
  'person.unknownPerson': 'Unknown person',
})[key] ?? key

const person = {
  birthSurname: { state: 'known', value: 'Ivanov' },
  givenName: { state: 'unknown' },
  patronymic: { state: 'known', value: 'Petrovich' },
  birthDate: {
    state: 'known',
    precision: 'year',
    year: 1900,
    approximate: true,
  },
  deathDate: null,
} satisfies Pick<
  Person,
  | 'birthSurname'
  | 'givenName'
  | 'patronymic'
  | 'birthDate'
  | 'deathDate'
>

describe('person formatting', () => {
  it('omits unknown name parts without decorative gaps', () => {
    expect(formatPersonName(person, translate)).toBe('Ivanov Petrovich')
    expect(formatPersonName({
      birthSurname: { state: 'unknown' },
      givenName: { state: 'unknown' },
      patronymic: { state: 'unknown' },
    }, translate)).toBe('Unknown person')
  })

  it('formats every structured date precision', () => {
    expect(formatGenealogicalDate(
      { state: 'unknown' },
      'en',
      translate,
    )).toBe('—')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        precision: 'year',
        year: 1900,
        approximate: true,
      },
      'en',
      translate,
    )).toBe('c. 1900')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        precision: 'month',
        year: 1994,
        month: 10,
        approximate: false,
      },
      'en',
      translate,
    )).toBe('October 1994')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        precision: 'day',
        year: 1994,
        month: 10,
        day: 25,
        approximate: false,
      },
      'en',
      translate,
    )).toBe('October 25, 1994')
  })

  it('distinguishes a living person from unknown death', () => {
    expect(formatLifePeriod(person, 'en', translate))
      .toBe('c. 1900 – present')
    expect(formatLifePeriod({
      ...person,
      deathDate: { state: 'unknown' },
    }, 'en', translate)).toBe('c. 1900 – —')
  })
})
