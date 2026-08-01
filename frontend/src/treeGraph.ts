import type {
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
  const siblingIds = new Set(siblings.map((sibling) => sibling.id))
  const candidateFamilyX = familyCentreWithoutOverlaps(
    snapshot.people,
    siblingIds,
    familyX,
    childY,
    totalChildren,
  )
  const positionAt = (index: number): Coordinates => childPosition(
    candidateFamilyX,
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

function familyCentreWithoutOverlaps(
  people: TreeSnapshot['people'],
  siblingIds: Set<string>,
  familyX: number,
  childY: number,
  totalChildren: number,
) {
  for (let attempt = 0; attempt < people.length + 2; attempt += 1) {
    const ring = Math.ceil(attempt / 2)
    const sign = attempt === 0 ? 0 : (attempt % 2 === 1 ? 1 : -1)
    const candidate = familyX + ring * HORIZONTAL_STEP * sign
    const positions = Array.from(
      { length: totalChildren },
      (_, index) => childPosition(candidate, childY, totalChildren, index),
    )
    if (positions.every((position) => people.every((person) => (
      siblingIds.has(person.id) || !overlaps(position, person.coordinates)
    )))) {
      return candidate
    }
  }

  return familyX
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
