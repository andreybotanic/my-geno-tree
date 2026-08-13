import type {
  GenealogicalDate,
  KnownText,
  Person,
} from './api/generated'

type Translate = (key: string) => string

export function formatUnbreakableDateText(value: string): string {
  return value.replaceAll(' ', '\u00a0')
}

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
  const formatParts = (parts: { year: number, month?: number, day?: number }) => {
    if (!parts.month) {
      return language.startsWith('ru') ? `${parts.year} г.` : String(parts.year)
    }

    return new Intl.DateTimeFormat(language, parts.day
      ? { day: 'numeric', month: 'long', timeZone: 'UTC', year: 'numeric' }
      : { month: 'long', timeZone: 'UTC', year: 'numeric' },
    ).format(new Date(Date.UTC(parts.year, parts.month - 1, parts.day ?? 1)))
  }
  const value = formatParts(date)

  const formatted = date.kind === 'approximate'
    ? `${translate('person.approximatePrefix')} ${value}`
    : date.kind === 'before'
      ? `${translate('person.beforeDate')} ${value}`
      : date.kind === 'after'
        ? `${translate('person.afterDate')} ${value}`
        : date.kind === 'between' && date.end
          ? `${translate('person.betweenDate')} ${value} ${translate('person.andDate')} ${formatParts(date.end)}`
          : value

  return formatUnbreakableDateText(formatted)
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
    ? formatUnbreakableDateText(translate('person.present'))
    : formatGenealogicalDate(person.deathDate, language, translate)

  return `${birth} – ${death}`
}
