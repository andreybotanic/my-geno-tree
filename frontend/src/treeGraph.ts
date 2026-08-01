import type {
  CoordinateUpdate,
  Coordinates,
  MarriagePeriod,
  ParentLink,
  TreeSnapshot,
} from './api/generated'
import type { Edge } from '@xyflow/react'

export const PERSON_CARD_WIDTH = 260
export const PERSON_CARD_HEIGHT = 160
const HORIZONTAL_STEP = 320
const VERTICAL_STEP = 240

export type RelativeKind = 'child' | 'father' | 'mother' | 'spouse'

export type FamilyEdgeData = {
  childPoints: Array<{
    id: string
    topX: number
    topY: number
  }>
  married: boolean
}

export type FamilyFlowEdge = Edge<FamilyEdgeData, 'family'>
export type SingleParentFlowEdge = Edge<Record<string, never>, 'singleParent'>
export type TreeFlowEdge = FamilyFlowEdge | SingleParentFlowEdge

export type ChildPlacement = {
  coordinates: Coordinates
  coordinateUpdates: Array<{
    personId: string
    coordinates: Coordinates
  }>
}

const pairKey = (first: string, second: string) => (
  [first, second].sort().join(':')
)

type Family = {
  children: Set<string>
  married: boolean
  people: [string, string]
}

export function buildTreeEdges(
  snapshot: Pick<TreeSnapshot, 'people' | 'parentLinks' | 'marriages'>,
): TreeFlowEdge[] {
  const people = new Map(snapshot.people.map((person) => [person.id, person]))
  const linksByChild = new Map<string, ParentLink[]>()
  for (const link of snapshot.parentLinks) {
    const links = linksByChild.get(link.childId) ?? []
    links.push(link)
    linksByChild.set(link.childId, links)
  }

  const families = new Map<string, Family>()
  const registerMarriage = (marriage: MarriagePeriod) => {
    const key = pairKey(marriage.malePersonId, marriage.femalePersonId)
    families.set(key, {
      children: families.get(key)?.children ?? new Set(),
      married: true,
      people: [marriage.malePersonId, marriage.femalePersonId],
    })
  }
  snapshot.marriages.forEach(registerMarriage)

  const edges: TreeFlowEdge[] = []
  for (const [childId, links] of linksByChild) {
    if (links.length === 1) {
      const onlyLink = links[0]
      if (!onlyLink) {
        continue
      }
      edges.push({
        id: `parent:${onlyLink.id}`,
        source: onlyLink.parentId,
        sourceHandle: 'bottom-source',
        target: childId,
        targetHandle: 'top-target',
        type: 'singleParent',
      })
      continue
    }

    const firstLink = links[0]
    const secondLink = links[1]
    if (!firstLink || !secondLink) {
      continue
    }
    const first = firstLink.parentId
    const second = secondLink.parentId
    const key = pairKey(first, second)
    const family = families.get(key) ?? {
      children: new Set<string>(),
      married: false,
      people: [first, second] as [string, string],
    }
    family.children.add(childId)
    families.set(key, family)
  }

  for (const [key, family] of families) {
    const first = people.get(family.people[0])
    const second = people.get(family.people[1])
    if (!first || !second) {
      continue
    }
    const [left, right] = first.coordinates.x <= second.coordinates.x
      ? [first, second]
      : [second, first]
    const childPoints = [...family.children]
      .map((childId) => people.get(childId))
      .filter((person) => person !== undefined)
      .map((person) => ({
        id: person.id,
        topX: person.coordinates.x + PERSON_CARD_WIDTH / 2,
        topY: person.coordinates.y,
      }))
      .sort((a, b) => a.topX - b.topX || a.id.localeCompare(b.id))

    edges.push({
      id: `family:${key}`,
      source: left.id,
      sourceHandle: 'bottom-source',
      target: right.id,
      targetHandle: 'bottom-target',
      type: 'family',
      data: {
        childPoints,
        married: family.married,
      },
    })
  }

  return edges.sort((a, b) => a.id.localeCompare(b.id))
}

export function newRelativeCoordinates(
  snapshot: Pick<TreeSnapshot, 'people'>,
  anchorId: string,
  kind: RelativeKind,
  secondParentId?: string,
): Coordinates {
  const anchor = snapshot.people.find((person) => person.id === anchorId)
  if (!anchor) {
    return { x: 0, y: 0 }
  }
  const secondParent = secondParentId
    ? snapshot.people.find((person) => person.id === secondParentId)
    : undefined
  const base = relativeBase(anchor.coordinates, kind, secondParent?.coordinates)
  const direction = kind === 'father' ? -1 : 1

  for (let attempt = 0; attempt < snapshot.people.length + 2; attempt += 1) {
    const ring = Math.ceil(attempt / 2)
    const sign = attempt === 0
      ? 0
      : (attempt % 2 === 1 ? direction : -direction)
    const candidate = {
      x: base.x + ring * HORIZONTAL_STEP * sign,
      y: base.y,
    }
    if (!snapshot.people.some((person) => overlaps(
      candidate,
      person.coordinates,
    ))) {
      return candidate
    }
  }

  return {
    x: base.x + (snapshot.people.length + 1) * HORIZONTAL_STEP * direction,
    y: base.y,
  }
}

export function newChildPlacement(
  snapshot: Pick<TreeSnapshot, 'people' | 'parentLinks'>,
  parentIds: string[],
): ChildPlacement {
  const parents = parentIds
    .map((parentId) => snapshot.people.find((person) => person.id === parentId))
    .filter((person) => person !== undefined)
  if (parents.length !== parentIds.length) {
    return { coordinates: { x: 0, y: 0 }, coordinateUpdates: [] }
  }

  const parentIdSet = new Set(parentIds)
  const siblings = snapshot.people
    .filter((person) => hasExactlyTheseParents(
      snapshot.parentLinks,
      person.id,
      parentIdSet,
    ))
    .sort((first, second) => (
      first.coordinates.x - second.coordinates.x
      || first.id.localeCompare(second.id)
    ))
  const familyX = parents.reduce(
    (sum, parent) => sum + parent.coordinates.x,
    0,
  ) / parents.length
  const familyY = Math.max(...parents.map((parent) => parent.coordinates.y))
  const totalChildren = siblings.length + 1
  const childY = familyY + VERTICAL_STEP
  const positionAt = (index: number): Coordinates => childPosition(
    familyX,
    childY,
    totalChildren,
    index,
  )

  return {
    coordinates: positionAt(siblings.length),
    coordinateUpdates: siblings.map((sibling, index) => ({
      personId: sibling.id,
      coordinates: positionAt(index),
    })),
  }
}

export function reflowTree(
  snapshot: Pick<TreeSnapshot, 'people' | 'parentLinks'>,
): CoordinateUpdate[] {
  const people = new Map(snapshot.people.map((person) => [person.id, person]))
  const parentsByChild = new Map<string, string[]>()
  for (const link of snapshot.parentLinks) {
    const parents = parentsByChild.get(link.childId) ?? []
    parents.push(link.parentId)
    parentsByChild.set(link.childId, parents)
  }
  const positions = new Map(snapshot.people.map((person) => [
    person.id,
    { ...person.coordinates },
  ]))
  const depthCache = new Map<string, number>()
  const calculating = new Set<string>()
  const depth = (personId: string): number => {
    const cached = depthCache.get(personId)
    if (cached !== undefined) {
      return cached
    }
    if (calculating.has(personId)) {
      return 0
    }
    calculating.add(personId)
    const parentDepths = (parentsByChild.get(personId) ?? [])
      .filter((parentId) => people.has(parentId))
      .map(depth)
    calculating.delete(personId)
    const result = parentDepths.length === 0 ? 0 : Math.max(...parentDepths) + 1
    depthCache.set(personId, result)
    return result
  }
  const families = new Map<string, { parentIds: string[]; childIds: string[] }>()
  for (const [childId, parentIds] of parentsByChild) {
    const knownParents = [...new Set(parentIds)]
      .filter((parentId) => people.has(parentId))
      .sort()
    if (knownParents.length === 0 || !people.has(childId)) {
      continue
    }
    const key = knownParents.join(':')
    const family = families.get(key) ?? { parentIds: knownParents, childIds: [] }
    family.childIds.push(childId)
    families.set(key, family)
  }

  const orderedFamilies = [...families.values()]
    .sort((first, second) => (
      Math.max(...first.parentIds.map(depth)) - Math.max(...second.parentIds.map(depth))
      || first.parentIds.join(':').localeCompare(second.parentIds.join(':'))
    ))
  const alignFamilies = () => {
    orderedFamilies.forEach((family) => {
      const parents = family.parentIds
        .map((parentId) => positions.get(parentId))
        .filter((position): position is Coordinates => position !== undefined)
      if (parents.length !== family.parentIds.length) {
        return
      }
      const familyX = parents.reduce((sum, parent) => sum + parent.x, 0) / parents.length
      const familyY = Math.max(...parents.map((parent) => parent.y))
      family.childIds
        .sort((first, second) => (
          (positions.get(first)?.x ?? 0) - (positions.get(second)?.x ?? 0)
          || first.localeCompare(second)
        ))
        .forEach((childId, index, children) => {
          positions.set(childId, childPosition(
            familyX,
            familyY + VERTICAL_STEP,
            children.length,
            index,
          ))
        })
    })
  }
  const ancestors = (personId: string): Map<string, number> => {
    const result = new Map<string, number>()
    const visit = (id: string, distance: number) => {
      const previousDistance = result.get(id)
      if (previousDistance !== undefined && previousDistance <= distance) {
        return
      }
      result.set(id, distance)
      for (const parentId of parentsByChild.get(id) ?? []) {
        if (people.has(parentId)) {
          visit(parentId, distance + 1)
        }
      }
    }
    visit(personId, 0)
    return result
  }
  const overlappingPair = (): [string, string] | undefined => {
    const orderedPeople = [...positions.entries()]
      .sort(([, first], [, second]) => first.x - second.x || first.y - second.y)
    for (let index = 0; index < orderedPeople.length; index += 1) {
      const [personId, position] = orderedPeople[index]!
      for (let previous = index - 1; previous >= 0; previous -= 1) {
        const [previousId, previousPosition] = orderedPeople[previous]!
        if (position.x - previousPosition.x >= PERSON_CARD_WIDTH + 40) {
          break
        }
        if (Math.abs(position.y - previousPosition.y) < PERSON_CARD_HEIGHT + 40) {
          return [previousId, personId]
        }
      }
    }
    return undefined
  }

  alignFamilies()
  for (let attempt = 0; attempt < snapshot.people.length * 2; attempt += 1) {
    const pair = overlappingPair()
    if (!pair) {
      break
    }
    const [leftId, rightId] = pair
    const leftAncestors = ancestors(leftId)
    const rightAncestors = [...ancestors(rightId).entries()]
    const roots = rightAncestors.filter(([personId]) => (
      (parentsByChild.get(personId) ?? []).every((parentId) => !people.has(parentId))
    ))
    const candidate = roots
      .filter(([personId]) => !leftAncestors.has(personId))
      .sort(([, firstDistance], [, secondDistance]) => firstDistance - secondDistance)[0]
      ?? roots
        .sort(([, firstDistance], [, secondDistance]) => firstDistance - secondDistance)[0]
    if (candidate === undefined) {
      break
    }
    const [rootId, distance] = candidate
    const rootPosition = positions.get(rootId)
    if (!rootPosition) {
      break
    }
    positions.set(rootId, {
      ...rootPosition,
      x: rootPosition.x + HORIZONTAL_STEP * 2 ** Math.min(distance, 3),
    })
    alignFamilies()
  }

  return snapshot.people.flatMap((person) => {
    const coordinates = positions.get(person.id)
    if (
      !coordinates
      || (
        coordinates.x === person.coordinates.x
        && coordinates.y === person.coordinates.y
      )
    ) {
      return []
    }

    return [{ personId: person.id, coordinates }]
  })
}

function childPosition(
  familyX: number,
  childY: number,
  totalChildren: number,
  index: number,
): Coordinates {
  return {
    x: familyX + (index - (totalChildren - 1) / 2) * HORIZONTAL_STEP,
    y: childY,
  }
}

function relativeBase(
  anchor: Coordinates,
  kind: RelativeKind,
  secondParent?: Coordinates,
): Coordinates {
  if (kind === 'father') {
    return { x: anchor.x - HORIZONTAL_STEP / 2, y: anchor.y - VERTICAL_STEP }
  }
  if (kind === 'mother') {
    return { x: anchor.x + HORIZONTAL_STEP / 2, y: anchor.y - VERTICAL_STEP }
  }
  if (kind === 'spouse') {
    return { x: anchor.x + HORIZONTAL_STEP, y: anchor.y }
  }
  const parentX = secondParent
    ? (anchor.x + secondParent.x) / 2
    : anchor.x

  return { x: parentX, y: Math.max(anchor.y, secondParent?.y ?? anchor.y) + VERTICAL_STEP }
}

function overlaps(first: Coordinates, second: Coordinates) {
  return (
    Math.abs(first.x - second.x) < PERSON_CARD_WIDTH + 40
    && Math.abs(first.y - second.y) < PERSON_CARD_HEIGHT + 40
  )
}

function hasExactlyTheseParents(
  parentLinks: ParentLink[],
  childId: string,
  parentIds: Set<string>,
) {
  const childParentIds = new Set(
    parentLinks
      .filter((link) => link.childId === childId)
      .map((link) => link.parentId),
  )

  return (
    childParentIds.size === parentIds.size
    && [...parentIds].every((parentId) => childParentIds.has(parentId))
  )
}
