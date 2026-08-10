import { describe, expect, it } from 'vitest'
import type { Person } from './api/generated'
import {
  formatGenealogicalDate,
  formatLifePeriod,
  formatPersonName,
} from './personFormatting'

const translate = (key: string) => ({
  'person.afterDate': 'after',
  'person.andDate': 'and',
  'person.approximatePrefix': '~',
  'person.beforeDate': 'before',
  'person.betweenDate': 'between',
  'person.present': 'present',
  'person.unknownPerson': 'Unknown person',
})[key] ?? key

const person = {
  birthSurname: { state: 'known', value: 'Ivanov' },
  givenName: { state: 'unknown' },
  patronymic: { state: 'known', value: 'Petrovich' },
  birthDate: {
    state: 'known',
    kind: 'approximate',
    year: 1900,
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

  it('formats date parts and every date kind', () => {
    expect(formatGenealogicalDate(
      { state: 'unknown' },
      'en',
      translate,
    )).toBe('—')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        kind: 'approximate',
        year: 1900,
      },
      'en',
      translate,
    )).toBe('~ 1900')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        kind: 'exact',
        year: 1994,
        month: 10,
      },
      'en',
      translate,
    )).toBe('October 1994')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        kind: 'exact',
        year: 1994,
        month: 10,
        day: 25,
      },
      'en',
      translate,
    )).toBe('October 25, 1994')
    expect(formatGenealogicalDate(
      { state: 'known', kind: 'before', year: 1900 },
      'en',
      translate,
    )).toBe('before 1900')
    expect(formatGenealogicalDate(
      { state: 'known', kind: 'after', year: 1900 },
      'ru',
      translate,
    )).toBe('after 1900 г.')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        kind: 'between',
        year: 1900,
        end: { year: 1901 },
      },
      'ru',
      translate,
    )).toBe('between 1900 г. and 1901 г.')
  })

  it('distinguishes a living person from unknown death', () => {
    expect(formatLifePeriod(person, 'en', translate))
      .toBe('~ 1900 – present')
    expect(formatLifePeriod({
      ...person,
      deathDate: { state: 'unknown' },
    }, 'en', translate)).toBe('~ 1900 – —')
  })
})
