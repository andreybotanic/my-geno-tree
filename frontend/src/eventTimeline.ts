import type {
  GenealogicalDate,
  GenealogicalEvent,
} from './api/generated'

type DateRange = {
  earliest: number
  latest: number
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
    firstRange.latest < secondRange.earliest
    || secondRange.latest < firstRange.earliest
  )
}

function dateRange(date: GenealogicalDate): DateRange | null {
  if (
    date.state === 'unknown'
    || (date.precision === 'year' && date.approximate)
  ) {
    return null
  }
  const firstMonth = date.precision === 'year' ? 0 : date.month - 1
  const firstDay = date.precision === 'day' ? date.day : 1
  const earliest = Date.UTC(date.year, firstMonth, firstDay)
  if (date.precision === 'day') {
    return { earliest, latest: earliest }
  }
  if (date.precision === 'month') {
    return {
      earliest,
      latest: Date.UTC(date.year, date.month, 0),
    }
  }

  return {
    earliest,
    latest: Date.UTC(date.year, 11, 31),
  }
}
