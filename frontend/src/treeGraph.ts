import type {
  CoordinateUpdate,
  Coordinates,
  MarriagePeriod,
  ParentLink,
  Person,
  TreeSnapshot,
} from './api/generated'
import type { Edge } from '@xyflow/react'

export const PERSON_CARD_WIDTH = 260
export const PERSON_CARD_HEIGHT = 160
const HORIZONTAL_STEP = 320
const VERTICAL_STEP = 240
const PARTNER_OUTLET_SPACING = 32
const HALF_SIBLING_GAP = 380
const COORDINATE_TOLERANCE = 0.001

export type RelativeKind = 'child' | 'father' | 'mother' | 'spouse'

export type FamilyEdgeData = {
  bracketDepth: number
  childPoints: Array<{
    id: string
    topX: number
    topY: number
  }>
  married: boolean
  sourceOffset: number
  targetOffset: number
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

type ReflowSnapshot = Pick<TreeSnapshot, 'people' | 'parentLinks'> & {
  marriages?: MarriagePeriod[]
  partnerPairs?: Array<[string, string]>
}

type PartnerOutlet = {
  familyKey: string
  partnerId: string
  partnerX: number
  personId: string
}

type PartnerOutletLayout = {
  bracketDepth: number
  offset: number
}

type PartnerSide = 'left' | 'right'

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

  const outletsByPerson = new Map<string, PartnerOutlet[]>()
  for (const [key, family] of families) {
    const first = people.get(family.people[0])
    const second = people.get(family.people[1])
    if (!first || !second) {
      continue
    }
    const [left, right] = first.coordinates.x < second.coordinates.x
      || (
        first.coordinates.x === second.coordinates.x
        && first.id.localeCompare(second.id) < 0
      )
      ? [first, second]
      : [second, first]
    for (const outlet of [{
      familyKey: key,
      partnerId: right.id,
      partnerX: right.coordinates.x,
      personId: left.id,
    }, {
      familyKey: key,
      partnerId: left.id,
      partnerX: left.coordinates.x,
      personId: right.id,
    }]) {
      const allPersonOutlets = outletsByPerson.get(outlet.personId) ?? []
      allPersonOutlets.push(outlet)
      outletsByPerson.set(outlet.personId, allPersonOutlets)
    }
  }
  const outletLayout = new Map<string, PartnerOutletLayout>()
  const partnerSide = (outlet: PartnerOutlet): PartnerSide => (
    outlet.partnerX < (people.get(outlet.personId)?.coordinates.x ?? 0)
      ? 'left'
      : 'right'
  )
  for (const outlets of outletsByPerson.values()) {
    outlets
      .sort((first, second) => (
        (partnerSide(first) === 'left' ? -1 : 1)
        - (partnerSide(second) === 'left' ? -1 : 1)
        || second.partnerX - first.partnerX
        || first.familyKey.localeCompare(second.familyKey)
      ))
      .forEach((outlet, index) => {
        const outletKey = `${outlet.familyKey}:${outlet.personId}`
        outletLayout.set(outletKey, {
          bracketDepth: 24,
          offset: (index - (outlets.length - 1) / 2) * PARTNER_OUTLET_SPACING,
        })
      })
  }
  const bracketLength = (outlet: PartnerOutlet) => {
    const person = people.get(outlet.personId)
    const partner = people.get(outlet.partnerId)
    if (!person || !partner) {
      return 0
    }
    const ownOffset = outletLayout.get(`${outlet.familyKey}:${outlet.personId}`)?.offset ?? 0
    const partnerOffset = outletLayout.get(`${outlet.familyKey}:${outlet.partnerId}`)?.offset ?? 0
    return Math.abs(
      person.coordinates.x + ownOffset - partner.coordinates.x - partnerOffset,
    )
  }
  for (const outlets of outletsByPerson.values()) {
    for (const side of ['left', 'right'] as const) {
      outlets
        .filter((outlet) => partnerSide(outlet) === side)
        .sort((first, second) => (
          bracketLength(first) - bracketLength(second)
          || first.familyKey.localeCompare(second.familyKey)
        ))
        .forEach((outlet, index) => {
          const outletKey = `${outlet.familyKey}:${outlet.personId}`
          const layout = outletLayout.get(outletKey)
          if (!layout) {
            return
          }
          outletLayout.set(outletKey, {
            ...layout,
            bracketDepth: 24 + index * 18,
          })
        })
    }
  }

  for (const [key, family] of families) {
    const first = people.get(family.people[0])
    const second = people.get(family.people[1])
    if (!first || !second) {
      continue
    }
    const [left, right] = first.coordinates.x < second.coordinates.x
      || (
        first.coordinates.x === second.coordinates.x
        && first.id.localeCompare(second.id) < 0
      )
      ? [first, second]
      : [second, first]
    const sourceLayout = outletLayout.get(`${key}:${left.id}`) ?? {
      bracketDepth: 24,
      offset: 0,
    }
    const targetLayout = outletLayout.get(`${key}:${right.id}`) ?? {
      bracketDepth: 24,
      offset: 0,
    }
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
        bracketDepth: Math.max(
          sourceLayout.bracketDepth,
          targetLayout.bracketDepth,
        ),
        childPoints,
        married: family.married,
        sourceOffset: sourceLayout.offset,
        targetOffset: targetLayout.offset,
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
  snapshot: ReflowSnapshot,
  options: { compact?: boolean } = {},
): CoordinateUpdate[] {
  const people = new Map(snapshot.people.map((person) => [person.id, person]))
  const parentsByChild = new Map<string, string[]>()
  for (const link of snapshot.parentLinks) {
    const parents = parentsByChild.get(link.childId) ?? []
    parents.push(link.parentId)
    parentsByChild.set(link.childId, parents)
  }
  const familyPairs = relationshipPairs(snapshot, people, parentsByChild)
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
  const isHalfSiblingPair = (firstId: string, secondId: string) => {
    const firstParents = new Set(parentsByChild.get(firstId) ?? [])
    const secondParents = new Set(parentsByChild.get(secondId) ?? [])
    if (firstParents.size === 0 || secondParents.size === 0) {
      return false
    }

    const sharedParents = [...firstParents].filter((parentId) => secondParents.has(parentId))

    return sharedParents.length > 0 && sharedParents.length < Math.min(
      firstParents.size,
      secondParents.size,
    )
  }
  const minimumHorizontalDistance = (firstId: string, secondId: string) => (
    isHalfSiblingPair(firstId, secondId)
      ? HALF_SIBLING_GAP
      : PERSON_CARD_WIDTH + 40
  )

  const rootRows = new Map<number, string[]>()
  for (const person of snapshot.people) {
    const hasKnownParent = (parentsByChild.get(person.id) ?? [])
      .some((parentId) => people.has(parentId))
    if (hasKnownParent) {
      continue
    }
    const roots = rootRows.get(person.coordinates.y) ?? []
    roots.push(person.id)
    rootRows.set(person.coordinates.y, roots)
  }
  const rootFamilyBlocks = arrangeRootFamilyBlocks(
    snapshot.people,
    parentsByChild,
    familyPairs,
    positions,
  )
  const rootOrder = new Map<string, number>()
  for (const rootIds of rootRows.values()) {
    const originalRoots = [...rootIds].sort((first, second) => (
      (people.get(first)?.coordinates.x ?? 0) - (people.get(second)?.coordinates.x ?? 0)
      || first.localeCompare(second)
    ))
    const originalIndex = new Map(originalRoots.map((personId, index) => [
      personId,
      index,
    ]))
    const seen = new Set<string>()
    const orderedRoots = originalRoots.flatMap((rootId) => {
      if (seen.has(rootId)) {
        return []
      }
      const block = (rootFamilyBlocks.get(rootId) ?? [rootId])
        .filter((personId) => originalIndex.has(personId))
        .sort((first, second) => (
          (originalIndex.get(first) ?? 0) - (originalIndex.get(second) ?? 0)
        ))
      block.forEach((personId) => seen.add(personId))

      return block
    })
    orderedRoots.forEach((personId, index) => rootOrder.set(personId, index))
  }
  const rootIds = new Set([...rootRows.values()].flat())
  const compactRootRows = () => {
    for (const rootIds of rootRows.values()) {
      const orderedRoots = [...rootIds].sort((first, second) => (
        (rootOrder.get(first) ?? 0) - (rootOrder.get(second) ?? 0)
      ))
      const firstX = options.compact
        ? 0
        : (positions.get(orderedRoots[0] ?? '')?.x ?? 0)
      orderedRoots.forEach((personId, index) => {
        const position = positions.get(personId)
        if (position) {
          positions.set(personId, {
            ...position,
            x: firstX + index * HORIZONTAL_STEP,
          })
        }
      })
    }
  }

  const partnersByPerson = new Map<string, Set<string>>()
  for (const [firstId, secondId] of familyPairs) {
    const firstPartners = partnersByPerson.get(firstId) ?? new Set<string>()
    firstPartners.add(secondId)
    partnersByPerson.set(firstId, firstPartners)
    const secondPartners = partnersByPerson.get(secondId) ?? new Set<string>()
    secondPartners.add(firstId)
    partnersByPerson.set(secondId, secondPartners)
  }
  const partnerAnchor = (personId: string) => {
    const partners = [...(partnersByPerson.get(personId) ?? [])]
      .map((partnerId) => positions.get(partnerId)?.x)
      .filter((x): x is number => x !== undefined)
    if (partners.length === 0) {
      return positions.get(personId)?.x ?? 0
    }
    return partners.reduce((sum, x) => sum + x, 0) / partners.length
  }

  const fixedGenerationIds = new Set<string>()

  const alignRootPartners = () => {
    for (const rootIdsInRow of rootRows.values()) {
      for (const rootId of rootIdsInRow) {
        if (fixedGenerationIds.has(rootId)) {
          continue
        }
        const partners = [...(partnersByPerson.get(rootId) ?? [])]
          .filter((partnerId) => !rootIds.has(partnerId))
        if (partners.length !== 1) {
          continue
        }
        const root = positions.get(rootId)
        const partner = positions.get(partners[0]!)
        if (!root || !partner || root.y !== partner.y) {
          continue
        }
        positions.set(rootId, { ...root, x: partner.x + HORIZONTAL_STEP })
      }
    }
  }

  const familiesByParent = new Map<string, Array<{ parentIds: string[]; childIds: string[] }>>()
  for (const family of families.values()) {
    for (const parentId of family.parentIds) {
      const childFamilies = familiesByParent.get(parentId) ?? []
      childFamilies.push(family)
      familiesByParent.set(parentId, childFamilies)
    }
  }
  const subtreeSpanCache = new Map<string, number>()
  const measuringSubtree = new Set<string>()
  const subtreeSpan = (personId: string): number => {
    const cached = subtreeSpanCache.get(personId)
    if (cached !== undefined) {
      return cached
    }
    if (measuringSubtree.has(personId)) {
      return HORIZONTAL_STEP
    }
    measuringSubtree.add(personId)
    const span = Math.max(
      HORIZONTAL_STEP,
      ...(familiesByParent.get(personId) ?? []).map((family) => (
        family.childIds.reduce((total, childId) => total + subtreeSpan(childId), 0)
      )),
    )
    measuringSubtree.delete(personId)
    subtreeSpanCache.set(personId, span)

    return span
  }

  const childSpacing = (childIds: string[]) => Math.max(
    HORIZONTAL_STEP,
    ...childIds.map((childId) => {
      const hasPartner = (partnersByPerson.get(childId)?.size ?? 0) > 0

      return hasPartner
        ? Math.max(HORIZONTAL_STEP * 2, subtreeSpan(childId))
        : subtreeSpan(childId)
    }),
  )

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
      if (family.childIds.every((childId) => fixedGenerationIds.has(childId))) {
        const fixedCenter = family.childIds.reduce((sum, childId) => (
          sum + (positions.get(childId)?.x ?? familyX)
        ), 0) / family.childIds.length
        const shift = fixedCenter - familyX
        if (Math.abs(shift) > COORDINATE_TOLERANCE) {
          family.parentIds.forEach((parentId) => {
            const parent = positions.get(parentId)
            if (parent) {
              positions.set(parentId, { ...parent, x: parent.x + shift })
            }
          })
        }

        return
      }
      const spacing = childSpacing(family.childIds)
      family.childIds
        .sort((first, second) => (
          partnerAnchor(first) - partnerAnchor(second)
          || first.localeCompare(second)
        ))
        .forEach((childId, index, children) => {
          positions.set(childId, childPosition(
            familyX,
            familyY + VERTICAL_STEP,
            children.length,
            index,
            spacing,
          ))
        })
      alignRootPartners()
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
  const childrenByParent = new Map<string, Set<string>>()
  for (const link of snapshot.parentLinks) {
    const children = childrenByParent.get(link.parentId) ?? new Set<string>()
    children.add(link.childId)
    childrenByParent.set(link.parentId, children)
  }
  const belongsToDirectFamily = (personId: string, childId: string) => (
    (parentsByChild.get(childId) ?? []).some((parentId) => (
      parentId === personId || partnersByPerson.get(parentId)?.has(personId)
    ))
  )
  const isPartnerOfDirectChild = (personId: string, parentId: string) => (
    [...(childrenByParent.get(parentId) ?? [])].some((childId) => (
      partnersByPerson.get(childId)?.has(personId)
    ))
  )
  const mayShareVerticalSpace = (firstId: string, secondId: string) => (
    belongsToDirectFamily(firstId, secondId)
    || belongsToDirectFamily(secondId, firstId)
    || isPartnerOfDirectChild(firstId, secondId)
    || isPartnerOfDirectChild(secondId, firstId)
  )
  const overlappingPair = (): [string, string] | undefined => {
    const orderedPeople = [...positions.entries()]
      .sort(([, first], [, second]) => first.x - second.x || first.y - second.y)
    for (let index = 0; index < orderedPeople.length; index += 1) {
      const [personId, position] = orderedPeople[index]!
      for (let previous = index - 1; previous >= 0; previous -= 1) {
        const [previousId, previousPosition] = orderedPeople[previous]!
        const horizontalDistance = position.x - previousPosition.x
        if (horizontalDistance >= HALF_SIBLING_GAP) {
          break
        }
        const verticalDistance = Math.abs(position.y - previousPosition.y)
        if (
          minimumHorizontalDistance(previousId, personId) - horizontalDistance
            > COORDINATE_TOLERANCE
          && (
          verticalDistance < PERSON_CARD_HEIGHT + 40
          || (
            verticalDistance === VERTICAL_STEP
            && rootIds.has(
              position.y < previousPosition.y ? personId : previousId,
            )
            && !mayShareVerticalSpace(previousId, personId)
          )
          )
        ) {
          return [previousId, personId]
        }
      }
    }
    return undefined
  }
  const shiftRootSuffix = (rootId: string, shift: number) => {
    const root = positions.get(rootId)
    if (!root) {
      return false
    }
    const orderedRoots = [...(rootRows.get(root.y) ?? [])].sort((first, second) => (
      (rootOrder.get(first) ?? 0) - (rootOrder.get(second) ?? 0)
    ))
    const rootIndex = orderedRoots.indexOf(rootId)
    if (rootIndex === -1) {
      return false
    }
    orderedRoots.slice(rootIndex).forEach((personId) => {
      const position = positions.get(personId)
      if (position) {
        positions.set(personId, { ...position, x: position.x + shift })
      }
    })

    return true
  }

  const influenceCache = new Map<string, number>()
  const rootInfluence = (rootId: string, personId: string): number => {
    const key = `${rootId}:${personId}`
    const cached = influenceCache.get(key)
    if (cached !== undefined) {
      return cached
    }
    if (rootId === personId) {
      influenceCache.set(key, 1)
      return 1
    }
    const parentIds = (parentsByChild.get(personId) ?? [])
      .filter((parentId) => people.has(parentId))
    if (parentIds.length === 0) {
      influenceCache.set(key, 0)
      return 0
    }
    const influence = parentIds.reduce(
      (sum, parentId) => sum + rootInfluence(rootId, parentId),
      0,
    ) / parentIds.length
    influenceCache.set(key, influence)

    return influence
  }
  const relatedRootIds = (personId: string) => {
    const roots = new Map<string, number>()
    for (const [ancestorId, distance] of ancestors(personId)) {
      if (rootIds.has(ancestorId)) {
        roots.set(ancestorId, distance)
      }
      for (const partnerId of partnersByPerson.get(ancestorId) ?? []) {
        if (rootIds.has(partnerId)) {
          roots.set(partnerId, Math.min(
            roots.get(partnerId) ?? Number.POSITIVE_INFINITY,
            distance - 0.5,
          ))
        }
      }
    }

    return [...roots.entries()]
  }
  const rootSuffixInfluence = (rootId: string, leftId: string, rightId: string) => {
    const root = positions.get(rootId)
    if (!root) {
      return 0
    }
    const orderedRoots = [...(rootRows.get(root.y) ?? [])].sort((first, second) => (
      (rootOrder.get(first) ?? 0) - (rootOrder.get(second) ?? 0)
    ))
    const rootIndex = orderedRoots.indexOf(rootId)
    if (rootIndex === -1) {
      return 0
    }

    return orderedRoots.slice(rootIndex).reduce((influence, shiftedRootId) => (
      influence
      + rootInfluence(shiftedRootId, rightId)
      - rootInfluence(shiftedRootId, leftId)
    ), 0)
  }

  const rootShiftsForSeparation = (leftId: string, rightId: string, distance: number) => {
    const relatedRoots = new Map<string, number>()
    for (const personId of [leftId, rightId]) {
      for (const [rootId, rootDistance] of relatedRootIds(personId)) {
        relatedRoots.set(rootId, Math.min(
          relatedRoots.get(rootId) ?? Number.POSITIVE_INFINITY,
          rootDistance,
        ))
      }
    }

    return [...relatedRoots.entries()]
      .map(([rootId, rootDistance]) => {
        const influence = rootSuffixInfluence(rootId, leftId, rightId)
        return {
          rootId,
          rootDistance,
          influence,
          shift: distance / influence,
        }
      })
      .filter((candidate) => Number.isFinite(candidate.shift))
      .sort((first, second) => (
        Math.abs(first.shift) - Math.abs(second.shift)
        || first.rootDistance - second.rootDistance
        || (positions.get(first.rootId)?.x ?? 0) - (positions.get(second.rootId)?.x ?? 0)
        || first.rootId.localeCompare(second.rootId)
      ))
  }

  const widestGeneration = () => {
    const peopleByRow = new Map<number, string[]>()
    for (const [personId, position] of positions) {
      const people = peopleByRow.get(position.y) ?? []
      people.push(personId)
      peopleByRow.set(position.y, people)
    }

    return [...peopleByRow.entries()]
      .sort(([firstY, firstPeople], [secondY, secondPeople]) => (
        secondPeople.length - firstPeople.length || firstY - secondY
      ))[0]?.[0]
  }
  const fixWidestGeneration = () => {
    const row = widestGeneration()
    if (row === undefined) {
      return
    }
    const peopleInGeneration = [...positions.entries()]
      .filter(([, position]) => position.y === row)
      .sort(([firstId, first], [secondId, second]) => (
        first.x - second.x || firstId.localeCompare(secondId)
      ))
    let previousId: string | undefined
    let previousX: number | undefined
    peopleInGeneration.forEach(([personId, position]) => {
      const x = previousId === undefined || previousX === undefined
        ? position.x
        : Math.max(
          position.x,
          previousX + minimumHorizontalDistance(previousId, personId),
        )
      if (x !== position.x) {
        positions.set(personId, { ...position, x })
      }
      fixedGenerationIds.add(personId)
      previousId = personId
      previousX = x
    })
  }
  const reconcileFamilyCentres = () => {
    for (const family of [...orderedFamilies].reverse()) {
      const parents = family.parentIds
        .map((parentId) => positions.get(parentId))
        .filter((position): position is Coordinates => position !== undefined)
      const children = family.childIds
        .map((childId) => positions.get(childId))
        .filter((position): position is Coordinates => position !== undefined)
      if (parents.length !== family.parentIds.length || children.length === 0) {
        continue
      }
      const parentCenter = parents.reduce((sum, parent) => sum + parent.x, 0) / parents.length
      const childCenter = children.reduce((sum, child) => sum + child.x, 0) / children.length
      const shift = childCenter - parentCenter
      if (Math.abs(shift) <= COORDINATE_TOLERANCE) {
        continue
      }
      family.parentIds.forEach((parentId) => {
        const parent = positions.get(parentId)
        if (parent && !fixedGenerationIds.has(parentId)) {
          positions.set(parentId, { ...parent, x: parent.x + shift })
        }
      })
    }
  }
  const arrangeGeneration = (row: number) => {
    let changed = false
    const appliedSeparations = new Set<string>()
    while (true) {
      const peopleInGeneration = [...positions.entries()]
        .filter(([, position]) => position.y === row)
        .sort(([firstId, first], [secondId, second]) => (
          first.x - second.x || firstId.localeCompare(secondId)
        ))
      let collision: { leftId: string; rightId: string; distance: number } | undefined
      for (let index = 1; index < peopleInGeneration.length; index += 1) {
        const [leftId, left] = peopleInGeneration[index - 1]!
        const [rightId, right] = peopleInGeneration[index]!
        const distance = minimumHorizontalDistance(leftId, rightId) - (right.x - left.x)
        if (distance > COORDINATE_TOLERANCE) {
          collision = {
            leftId,
            rightId,
            distance,
          }
          break
        }
      }
      if (!collision) {
        return changed
      }
      const candidates = rootShiftsForSeparation(
        collision.leftId,
        collision.rightId,
        collision.distance,
      )
      const candidate = candidates.find((item) => !appliedSeparations.has([
        collision.leftId,
        collision.rightId,
        item.rootId,
        Math.sign(item.shift),
      ].join(':')))
      if (candidate === undefined) {
        return changed
      }
      if (!candidate || !shiftRootSuffix(
        candidate.rootId,
        candidate.shift,
      )) {
        return changed
      }
      appliedSeparations.add([
        collision.leftId,
        collision.rightId,
        candidate.rootId,
        Math.sign(candidate.shift),
      ].join(':'))
      changed = true
      alignFamilies()
    }

  }
  const arrangeGenerationsFromWidest = () => {
    const anchorRow = widestGeneration()
    if (anchorRow === undefined) {
      return
    }
    const rows = [...new Set([...positions.values()].map((position) => position.y))]
      .sort((first, second) => (
        Math.abs(first - anchorRow) - Math.abs(second - anchorRow)
        || first - second
      ))
    for (let pass = 0; pass < rows.length; pass += 1) {
      let changed = false
      for (const row of rows) {
        changed = arrangeGeneration(row) || changed
      }
      if (!changed) {
        return
      }
    }
  }

  const resolveCollisions = () => {
    const appliedSeparations = new Set<string>()
    while (true) {
      const pair = overlappingPair()
      if (!pair) {
        break
      }
      const [leftId, rightId] = pair
      const leftPosition = positions.get(leftId)
      const rightPosition = positions.get(rightId)
      if (!leftPosition || !rightPosition) {
        break
      }
      const requiredDistance = minimumHorizontalDistance(leftId, rightId)
        - (rightPosition.x - leftPosition.x)
      const candidates = rootShiftsForSeparation(leftId, rightId, requiredDistance)
      const candidate = candidates.find((item) => !appliedSeparations.has([
        leftId,
        rightId,
        item.rootId,
        Math.sign(item.shift),
      ].join(':')))
      if (candidate === undefined) {
        break
      }
      const separationKey = [
        leftId,
        rightId,
        candidate.rootId,
        Math.sign(candidate.shift),
      ].join(':')
      if (!shiftRootSuffix(candidate.rootId, candidate.shift)) {
        break
      }
      appliedSeparations.add(separationKey)
      alignFamilies()
    }
  }

  const balanceSharedRootPartners = () => {
    for (const rowRootIds of rootRows.values()) {
      const orderedRoots = [...rowRootIds].sort((first, second) => (
        (rootOrder.get(first) ?? 0) - (rootOrder.get(second) ?? 0)
      ))
      for (let index = 1; index < orderedRoots.length - 1; index += 1) {
        const previousId = orderedRoots[index - 1]!
        const personId = orderedRoots[index]!
        const nextId = orderedRoots[index + 1]!
        if (
          !partnersByPerson.get(personId)?.has(previousId)
          || !partnersByPerson.get(personId)?.has(nextId)
        ) {
          continue
        }
        const previous = positions.get(previousId)
        const person = positions.get(personId)
        const next = positions.get(nextId)
        if (!previous || !person || !next) {
          continue
        }
        const minimumX = previous.x + HORIZONTAL_STEP
        const maximumX = next.x - HORIZONTAL_STEP
        if (minimumX > maximumX) {
          continue
        }
        const balancedX = Math.min(
          maximumX,
          Math.max(minimumX, (previous.x + next.x) / 2),
        )
        positions.set(personId, { ...person, x: balancedX })
      }
    }
  }

  compactRootRows()
  alignFamilies()
  fixWidestGeneration()
  reconcileFamilyCentres()
  arrangeGenerationsFromWidest()
  resolveCollisions()
  balanceSharedRootPartners()
  alignFamilies()
  arrangeGenerationsFromWidest()
  resolveCollisions()
  arrangeGenerationsFromWidest()
  reconcileFamilyCentres()

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

function relationshipPairs(
  snapshot: ReflowSnapshot,
  people: Map<string, Person>,
  parentsByChild: Map<string, string[]>,
): Array<[string, string]> {
  const pairs = new Map<string, [string, string]>()
  const registerPair = (firstId: string, secondId: string) => {
    if (firstId === secondId || !people.has(firstId) || !people.has(secondId)) {
      return
    }
    const sortedIds = [firstId, secondId].sort()
    const first = sortedIds[0]!
    const second = sortedIds[1]!
    pairs.set(`${first}:${second}`, [first, second])
  }

  for (const marriage of snapshot.marriages ?? []) {
    registerPair(marriage.malePersonId, marriage.femalePersonId)
  }
  for (const [firstId, secondId] of snapshot.partnerPairs ?? []) {
    registerPair(firstId, secondId)
  }
  for (const parentIds of parentsByChild.values()) {
    const knownParentIds = [...new Set(parentIds)].filter((parentId) => (
      people.has(parentId)
    ))
    if (knownParentIds.length === 2) {
      registerPair(knownParentIds[0]!, knownParentIds[1]!)
    }
  }

  return [...pairs.values()]
}

function arrangeRootFamilyBlocks(
  people: Person[],
  parentsByChild: Map<string, string[]>,
  familyPairs: Array<[string, string]>,
  positions: Map<string, Coordinates>,
): Map<string, string[]> {
  const rootsByRow = new Map<number, string[]>()
  const rootFamilyBlocks = new Map<string, string[]>()
  for (const person of people) {
    const hasKnownParent = (parentsByChild.get(person.id) ?? [])
      .some((parentId) => positions.has(parentId))
    if (hasKnownParent) {
      continue
    }
    const roots = rootsByRow.get(person.coordinates.y) ?? []
    roots.push(person.id)
    rootsByRow.set(person.coordinates.y, roots)
  }

  for (const rootIds of rootsByRow.values()) {
    const orderedRoots = [...rootIds].sort((first, second) => (
      (positions.get(first)?.x ?? 0) - (positions.get(second)?.x ?? 0)
      || first.localeCompare(second)
    ))
    const rootSet = new Set(orderedRoots)
    const partners = new Map<string, Set<string>>()
    for (const [firstId, secondId] of familyPairs) {
      if (!rootSet.has(firstId) || !rootSet.has(secondId)) {
        continue
      }
      const firstPartners = partners.get(firstId) ?? new Set<string>()
      firstPartners.add(secondId)
      partners.set(firstId, firstPartners)
      const secondPartners = partners.get(secondId) ?? new Set<string>()
      secondPartners.add(firstId)
      partners.set(secondId, secondPartners)
    }

    const originalIndex = new Map(orderedRoots.map((personId, index) => [
      personId,
      index,
    ]))
    const seen = new Set<string>()
    const blocks: string[][] = []
    for (const rootId of orderedRoots) {
      if (seen.has(rootId)) {
        continue
      }
      const block: string[] = []
      const pending = [rootId]
      seen.add(rootId)
      while (pending.length > 0) {
        const personId = pending.pop()!
        block.push(personId)
        for (const partnerId of partners.get(personId) ?? []) {
          if (!seen.has(partnerId)) {
            seen.add(partnerId)
            pending.push(partnerId)
          }
        }
      }
      block.sort((first, second) => (
        (originalIndex.get(first) ?? 0) - (originalIndex.get(second) ?? 0)
      ))
      blocks.push(block)
    }
    blocks.sort((first, second) => (
      (originalIndex.get(first[0]!) ?? 0) - (originalIndex.get(second[0]!) ?? 0)
    ))
    for (const block of blocks) {
      for (const personId of block) {
        rootFamilyBlocks.set(personId, block)
      }
    }

    const arrangedRoots = blocks.flat()
    const slotXs = orderedRoots.map((personId) => (
      positions.get(personId)?.x ?? 0
    ))
    arrangedRoots.forEach((personId, index) => {
      const position = positions.get(personId)
      const slotX = slotXs[index]
      if (position && slotX !== undefined) {
        positions.set(personId, { ...position, x: slotX })
      }
    })
  }

  return rootFamilyBlocks
}

function childPosition(
  familyX: number,
  childY: number,
  totalChildren: number,
  index: number,
  spacing = HORIZONTAL_STEP,
): Coordinates {
  return {
    x: familyX + (index - (totalChildren - 1) / 2) * spacing,
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
