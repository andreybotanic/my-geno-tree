import type {
  GenealogicalDate,
  GenealogicalEvent,
} from './api/generated'

type DateRange = {
  earliest: number | null
  latest: number | null
}

export function ambiguousEventGroups(events: GenealogicalEvent[]) {
  const groups: GenealogicalEvent[][] = []
  let current: GenealogicalEvent[] = []
  for (const event of events) {
    if (
      current.length > 0
      && !current.every((member) => datesAreAmbiguous(
        member.date,
        event.date,
      ))
    ) {
      if (current.length > 1) {
        groups.push(current)
      }
      current = []
    }
    current.push(event)
  }
  if (current.length > 1) {
    groups.push(current)
  }

  return groups
}

export function datesAreAmbiguous(
  first: GenealogicalDate,
  second: GenealogicalDate,
) {
  const firstRange = dateRange(first)
  const secondRange = dateRange(second)
  if (!firstRange || !secondRange) {
    return true
  }

  return !(
    (firstRange.latest !== null && secondRange.earliest !== null && firstRange.latest < secondRange.earliest)
    || (secondRange.latest !== null && firstRange.earliest !== null && secondRange.latest < firstRange.earliest)
  )
}

export function canMoveEvent(
  event: GenealogicalDate,
  neighbor: GenealogicalDate,
) {
  if (!datesAreAmbiguous(event, neighbor)) {
    return false
  }

  if (event.state === 'unknown' || event.kind !== 'exact' || event.day === undefined) {
    return true
  }

  return neighbor.state === 'known'
    && neighbor.kind === 'exact'
    && neighbor.day !== undefined
    && event.year === neighbor.year
    && event.month === neighbor.month
    && event.day === neighbor.day
}

function dateRange(date: GenealogicalDate): DateRange | null {
  if (date.state === 'unknown' || date.kind === 'approximate') {
    return null
  }
  const firstMonth = (date.month ?? 1) - 1
  const firstDay = date.day ?? 1
  const earliest = Date.UTC(date.year, firstMonth, firstDay)
  const latest = date.day ? earliest : date.month ? Date.UTC(date.year, date.month, 0) : Date.UTC(date.year, 11, 31)
  if (date.kind === 'before') return { earliest: null, latest: earliest - 86_400_000 }
  if (date.kind === 'after') return { earliest: latest + 86_400_000, latest: null }
  if (date.kind === 'between' && date.end) {
    const endStart = Date.UTC(date.end.year, (date.end.month ?? 1) - 1, date.end.day ?? 1)
    return { earliest, latest: date.end.day ? endStart : date.end.month ? Date.UTC(date.end.year, date.end.month, 0) : Date.UTC(date.end.year, 11, 31) }
  }

  return { earliest, latest }
}
