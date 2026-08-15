import { describe, expect, it } from 'vitest'
import type { Person } from './api/generated'
import {
  formatGenealogicalDate,
  formatLifePeriod,
  formatPersonName,
  formatPersonNameParts,
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
    expect(formatPersonNameParts(person, translate)).toEqual(['Ivanov', 'Petrovich'])
    expect(formatPersonName({
      birthSurname: { state: 'unknown' },
      givenName: { state: 'unknown' },
      patronymic: { state: 'unknown' },
    }, translate)).toBe('Unknown person')
    expect(formatPersonNameParts({
      birthSurname: { state: 'unknown' },
      givenName: { state: 'unknown' },
      patronymic: { state: 'unknown' },
    }, translate)).toEqual(['Unknown person'])
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
    )).toBe('~\u00a01900')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        kind: 'exact',
        year: 1994,
        month: 10,
      },
      'en',
      translate,
    )).toBe('October\u00a01994')
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
    )).toBe('October\u00a025,\u00a01994')
    expect(formatGenealogicalDate(
      { state: 'known', kind: 'before', year: 1900 },
      'en',
      translate,
    )).toBe('before\u00a01900')
    expect(formatGenealogicalDate(
      { state: 'known', kind: 'after', year: 1900 },
      'ru',
      translate,
    )).toBe('after\u00a01900\u00a0г.')
    expect(formatGenealogicalDate(
      {
        state: 'known',
        kind: 'between',
        year: 1900,
        end: { year: 1901 },
      },
      'ru',
      translate,
    )).toBe('between\u00a01900\u00a0г.\u00a0and\u00a01901\u00a0г.')
  })

  it('distinguishes a living person from unknown death', () => {
    expect(formatLifePeriod(person, 'en', translate))
      .toBe('~\u00a01900 – present')
    expect(formatLifePeriod({
      ...person,
      deathDate: { state: 'unknown' },
    }, 'en', translate)).toBe('~\u00a01900 – —')
    expect(formatLifePeriod(person, 'ru', (key) => (
      key === 'person.present' ? 'по настоящее время' : translate(key)
    ))).toBe('~\u00a01900\u00a0г. – по\u00a0настоящее\u00a0время')
  })
})
