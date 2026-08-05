import type { XYPosition } from '@xyflow/react'
import type { MarriagePeriod, ParentLink, TreeSnapshot } from './api/generated'

const PERSON_CARD_WIDTH = 260
const PERSON_CARD_HEIGHT = 160
const HORIZONTAL_STEP = 320
const VERTICAL_STEP = 240
const HALF_SIBLING_GAP = 380
const COORDINATE_TOLERANCE = 0.001

type Coordinates = XYPosition
type CoordinateUpdate = {
  personId: string
  coordinates: Coordinates
}
type Person = {
  id: string
  coordinates: Coordinates
}
type ReflowSnapshot = {
  people: Person[]
  parentLinks: ParentLink[]
  marriages?: MarriagePeriod[]
  partnerPairs?: Array<[string, string]>
}

/**
 * Refines a deterministic generation seed into family-aware transient positions.
 * The result is derived exclusively from the current snapshot and is never persisted.
 */
export function refineFamilyLayout(
  snapshot: TreeSnapshot,
  seedPositions: ReadonlyMap<string, XYPosition>,
): Map<string, XYPosition> {
  const people = [...snapshot.people]
    .sort((left, right) => left.id.localeCompare(right.id))
    .map((person) => ({
      id: person.id,
      coordinates: { ...(seedPositions.get(person.id) ?? { x: 0, y: 0 }) },
    }))
  const parentLinks = [...snapshot.parentLinks].sort((left, right) => (
    left.childId.localeCompare(right.childId)
    || left.role.localeCompare(right.role)
    || left.parentId.localeCompare(right.parentId)
    || left.id.localeCompare(right.id)
  ))
  const marriages = [...snapshot.marriages].sort((left, right) => (
    [left.malePersonId, left.femalePersonId].sort().join(':')
      .localeCompare([right.malePersonId, right.femalePersonId].sort().join(':'))
    || left.id.localeCompare(right.id)
  ))
  const positions = new Map(people.map((person) => [
    person.id,
    { ...person.coordinates },
  ]))
  for (const update of reflowTree(
    { people, parentLinks, marriages },
    { compact: true },
  )) {
    positions.set(update.personId, update.coordinates)
  }

  return positions
}

function reflowTree(
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
  const descendantFamilyBlockCache = new Map<string, Set<string>>()
  const descendantFamilyBlock = (personId: string): Set<string> => {
    const cached = descendantFamilyBlockCache.get(personId)
    if (cached) {
      return cached
    }
    const block = new Set<string>()
    const pending = [personId]
    while (pending.length > 0) {
      const currentId = pending.shift()!
      if (block.has(currentId) || !people.has(currentId)) {
        continue
      }
      block.add(currentId)
      for (const partnerId of partnersByPerson.get(currentId) ?? []) {
        if (!block.has(partnerId)) {
          pending.push(partnerId)
        }
      }
      for (const childId of childrenByParent.get(currentId) ?? []) {
        if (!block.has(childId)) {
          pending.push(childId)
        }
      }
    }
    descendantFamilyBlockCache.set(personId, block)

    return block
  }
  const siblingGroups = [...childrenByParent.entries()]
    .filter(([, childIds]) => childIds.size > 1)
    .map(([parentId, childIds]) => ({
      childIds: [...childIds].sort(),
      parentDepth: depth(parentId),
    }))
    .filter((group, index, groups) => (
      groups.findIndex((candidate) => (
        candidate.childIds.join(':') === group.childIds.join(':')
      )) === index
    ))
    .sort((first, second) => (
      second.parentDepth - first.parentDepth
      || first.childIds.join(':').localeCompare(second.childIds.join(':'))
    ))
  // Keep every sibling's partners and descendants in one horizontal band so
  // cousin branches cannot alternate. Deep groups are fixed before ancestors.
  const separateSiblingSubtrees = () => {
    for (const siblingGroup of siblingGroups) {
      const branches = siblingGroup.childIds.map((personId) => ({
        members: descendantFamilyBlock(personId),
        personId,
      }))
      const branchesOverlap = branches.some((branch, index) => (
        branches.slice(index + 1).some((candidate) => (
          [...branch.members].some((personId) => candidate.members.has(personId))
        ))
      ))
      if (branchesOverlap) {
        continue
      }
      branches.sort((first, second) => (
        (positions.get(first.personId)?.x ?? 0) - (positions.get(second.personId)?.x ?? 0)
        || first.personId.localeCompare(second.personId)
      ))
      let previousRight: number | undefined
      for (const branch of branches) {
        const branchPositions = [...branch.members]
          .map((personId) => positions.get(personId))
          .filter((position): position is Coordinates => position !== undefined)
        if (branchPositions.length === 0) {
          continue
        }
        const left = Math.min(...branchPositions.map((position) => position.x))
        const right = Math.max(...branchPositions.map((position) => position.x))
        const shift = previousRight === undefined
          ? 0
          : Math.max(0, previousRight + PERSON_CARD_WIDTH + 40 - left)
        if (shift > COORDINATE_TOLERANCE) {
          branch.members.forEach((personId) => {
            const position = positions.get(personId)
            if (position) {
              positions.set(personId, { ...position, x: position.x + shift })
            }
          })
        }
        previousRight = Math.max(previousRight ?? Number.NEGATIVE_INFINITY, right + shift)
      }
    }
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
  separateSiblingSubtrees()
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
  separateSiblingSubtrees()
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
