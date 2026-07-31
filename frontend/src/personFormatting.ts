import type {
  GenealogicalDate,
  KnownText,
  Person,
} from './api/generated'

type Translate = (key: string) => string

function knownValue(text: KnownText): string | null {
  return text.state === 'known' ? text.value : null
}

export function formatPersonName(
  person: Pick<Person, 'birthSurname' | 'givenName' | 'patronymic'>,
  translate: Translate,
): string {
  const parts = [
    knownValue(person.birthSurname),
    knownValue(person.givenName),
    knownValue(person.patronymic),
  ].filter((part): part is string => part !== null)

  return parts.length > 0 ? parts.join(' ') : translate('person.unknownPerson')
}

export function formatGenealogicalDate(
  date: GenealogicalDate,
  language: string,
  translate: Translate,
): string {
  if (date.state === 'unknown') {
    return '—'
  }
  if (date.precision === 'year') {
    return date.approximate
      ? translate('person.approximateYear').replace(
          '{{year}}',
          String(date.year),
        )
      : String(date.year)
  }

  const value = new Date(Date.UTC(
    date.year,
    date.month - 1,
    date.precision === 'day' ? date.day : 1,
  ))

  return new Intl.DateTimeFormat(language, date.precision === 'day'
    ? {
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
        year: 'numeric',
      }
    : {
        month: 'long',
        timeZone: 'UTC',
        year: 'numeric',
      }).format(value)
}

export function formatLifePeriod(
  person: Pick<Person, 'birthDate' | 'deathDate'>,
  language: string,
  translate: Translate,
): string {
  const birth = formatGenealogicalDate(
    person.birthDate,
    language,
    translate,
  )
  const death = person.deathDate === null
    ? translate('person.present')
    : formatGenealogicalDate(person.deathDate, language, translate)

  return `${birth} – ${death}`
}
