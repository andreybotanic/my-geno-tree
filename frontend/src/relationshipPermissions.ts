import type {
  MarriagePeriod,
  ParentLink,
  TreeSnapshot,
} from './api/generated'

type Removal = {
  marriageId?: string
  parentLinkId?: string
  personId?: string
}

export function canRemoveParentLink(
  snapshot: TreeSnapshot,
  link: ParentLink,
) {
  return remainsConnected(snapshot, { parentLinkId: link.id })
}

export function canRemoveMarriage(
  snapshot: TreeSnapshot,
  marriage: MarriagePeriod,
) {
  return remainsConnected(snapshot, { marriageId: marriage.id })
}

export function canDeletePerson(
  snapshot: TreeSnapshot,
  personId: string,
) {
  const hasParent = snapshot.parentLinks.some(
    (link) => link.childId === personId,
  )
  const hasChild = snapshot.parentLinks.some(
    (link) => link.parentId === personId,
  )
  if (hasParent && hasChild) {
    return false
  }

  const currentPartners = new Set(
    snapshot.marriages
      .filter((marriage) => (
        marriage.dissolutionDate === null
        && (
          marriage.malePersonId === personId
          || marriage.femalePersonId === personId
        )
      ))
      .map((marriage) => (
        marriage.malePersonId === personId
          ? marriage.femalePersonId
          : marriage.malePersonId
      )),
  )
  const children = new Set(
    snapshot.parentLinks
      .filter((link) => link.parentId === personId)
      .map((link) => link.childId),
  )
  const hasCurrentSpouseChild = snapshot.parentLinks.some((link) => (
    children.has(link.childId) && currentPartners.has(link.parentId)
  ))
  if (hasCurrentSpouseChild) {
    return false
  }

  return remainsConnected(snapshot, { personId })
}

function remainsConnected(snapshot: TreeSnapshot, removal: Removal) {
  const people = snapshot.people
    .map((person) => person.id)
    .filter((personId) => personId !== removal.personId)
  if (people.length <= 1) {
    return true
  }

  const adjacency = new Map(
    people.map((personId) => [personId, new Set<string>()]),
  )
  const connect = (first: string, second: string) => {
    if (!adjacency.has(first) || !adjacency.has(second)) {
      return
    }
    adjacency.get(first)?.add(second)
    adjacency.get(second)?.add(first)
  }
  snapshot.parentLinks
    .filter((link) => link.id !== removal.parentLinkId)
    .forEach((link) => connect(link.parentId, link.childId))
  snapshot.marriages
    .filter((marriage) => marriage.id !== removal.marriageId)
    .forEach((marriage) => connect(
      marriage.malePersonId,
      marriage.femalePersonId,
    ))

  const first = people[0]
  if (!first) {
    return true
  }
  const visited = new Set<string>()
  const pending = [first]
  while (pending.length > 0) {
    const current = pending.pop()
    if (!current || visited.has(current)) {
      continue
    }
    visited.add(current)
    adjacency.get(current)?.forEach((next) => pending.push(next))
  }

  return visited.size === people.length
}
