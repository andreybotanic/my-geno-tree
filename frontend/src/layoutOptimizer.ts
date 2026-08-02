import type { Coordinates, TreeSnapshot } from './api/generated'
import { PERSON_CARD_WIDTH, reflowTree } from './treeGraph'

export const LAYOUT_WARNING_THRESHOLD = 10_000n
export const LIMITED_LAYOUT_SEARCH = 10_000n

export type LayoutSearchMode = 'full' | 'limited'

export type LayoutOptimizationProgress = {
  checked: bigint
  total: bigint
}

export type LayoutOptimizationResult = {
  candidateCount: bigint
  checkedCount: bigint
  coordinateUpdates: Array<{ personId: string; coordinates: Coordinates }>
  currentWidth: number
  limited: boolean
  optimizedWidth: number
}

type OrderDimension = {
  apply: (rank: bigint, positions: Map<string, Coordinates>) => void
  memberIds: Set<string>
  size: bigint
}

type ChildBlock = {
  childIds: string[]
  canReorderInternally: boolean
}

const factorial = (value: number): bigint => {
  let result = 1n
  for (let index = 2; index <= value; index += 1) {
    result *= BigInt(index)
  }
  return result
}

/** Returns the `rank`-th stable permutation. Rank zero is the current order. */
const unrankPermutation = <Value>(values: Value[], rank: bigint): Value[] => {
  const remaining = [...values]
  const result: Value[] = []
  let remainder = rank
  for (let count = remaining.length; count > 0; count -= 1) {
    const blockSize = factorial(count - 1)
    const index = Number(remainder / blockSize)
    remainder %= blockSize
    result.push(remaining.splice(index, 1)[0]!)
  }
  return result
}

const personOrder = (snapshot: TreeSnapshot, personIds: Iterable<string>) => {
  const positions = new Map(snapshot.people.map((person) => [person.id, person.coordinates]))
  return [...personIds].sort((first, second) => (
    (positions.get(first)?.x ?? 0) - (positions.get(second)?.x ?? 0)
    || first.localeCompare(second)
  ))
}

const assignOrderToSlots = (
  personIds: string[],
  orderedIds: string[],
  positions: Map<string, Coordinates>,
) => {
  const slots = [...personIds]
    .sort((first, second) => (
      (positions.get(first)?.x ?? 0) - (positions.get(second)?.x ?? 0)
      || first.localeCompare(second)
    ))
    .map((personId) => positions.get(personId)?.x ?? 0)
  orderedIds.forEach((personId, index) => {
    const position = positions.get(personId)
    const x = slots[index]
    if (position && x !== undefined) {
      positions.set(personId, { ...position, x })
    }
  })
}

const parentSets = (snapshot: TreeSnapshot) => {
  const result = new Map<string, Set<string>>()
  snapshot.parentLinks.forEach((link) => {
    const parents = result.get(link.childId) ?? new Set<string>()
    parents.add(link.parentId)
    result.set(link.childId, parents)
  })
  return result
}

const relationshipPartners = (snapshot: TreeSnapshot) => {
  const partners = new Map<string, Set<string>>()
  const add = (first: string, second: string) => {
    if (first === second) {
      return
    }
    ;[first, second].forEach((personId, index, ids) => {
      const related = partners.get(personId) ?? new Set<string>()
      related.add(ids[1 - index]!)
      partners.set(personId, related)
    })
  }
  snapshot.marriages.forEach((marriage) => add(marriage.malePersonId, marriage.femalePersonId))
  const parents = parentSets(snapshot)
  parents.forEach((ids) => {
    const known = [...ids].sort()
    for (let first = 0; first < known.length; first += 1) {
      for (let second = first + 1; second < known.length; second += 1) {
        add(known[first]!, known[second]!)
      }
    }
  })
  return partners
}

const buildDimensions = (snapshot: TreeSnapshot): OrderDimension[] => {
  const knownPeople = new Set(snapshot.people.map((person) => person.id))
  const parentsByChild = parentSets(snapshot)
  const partnersByPerson = relationshipPartners(snapshot)
  const childrenByParent = new Map<string, Set<string>>()
  snapshot.parentLinks.forEach((link) => {
    const children = childrenByParent.get(link.parentId) ?? new Set<string>()
    children.add(link.childId)
    childrenByParent.set(link.parentId, children)
  })
  const hasExternalFamily = (personId: string) => (
    (partnersByPerson.get(personId)?.size ?? 0) > 0
    || (childrenByParent.get(personId)?.size ?? 0) > 0
  )
  const subtreeSignatures = new Map<string, string>()
  const subtreeSignature = (personId: string, path = new Set<string>()): string => {
    const cached = subtreeSignatures.get(personId)
    if (cached) {
      return cached
    }
    if (path.has(personId)) {
      return 'cycle'
    }
    const nextPath = new Set(path).add(personId)
    const childSignatures = [...(childrenByParent.get(personId) ?? [])]
      .map((childId) => subtreeSignature(childId, nextPath))
      .sort()
    const signature = [
      `partners:${partnersByPerson.get(personId)?.size ?? 0}`,
      `children:${childSignatures.join('|')}`,
    ].join(';')
    subtreeSignatures.set(personId, signature)
    return signature
  }
  const dimensions: OrderDimension[] = []
  const claimed = new Set<string>()

  // A multi-partner star is safe to permute as a single unit: every person
  // between the center and a partner is another partner of that same center.
  const partnerCenters = [...partnersByPerson]
    .filter(([personId, partners]) => knownPeople.has(personId) && partners.size >= 2)
    .sort(([firstId, first], [secondId, second]) => (
      second.size - first.size
      || (snapshot.people.find((person) => person.id === firstId)?.coordinates.x ?? 0)
        - (snapshot.people.find((person) => person.id === secondId)?.coordinates.x ?? 0)
      || firstId.localeCompare(secondId)
    ))
  partnerCenters.forEach(([centerId, partners]) => {
    const memberIds = personOrder(snapshot, [centerId, ...partners])
    if (memberIds.some((personId) => claimed.has(personId))) {
      return
    }
    const size = factorial(memberIds.length)
    dimensions.push({
      memberIds: new Set(memberIds),
      size,
      apply: (rank, positions) => {
        assignOrderToSlots(memberIds, unrankPermutation(memberIds, rank), positions)
      },
    })
    memberIds.forEach((personId) => claimed.add(personId))
  })

  // Each known-parent set is a candidate-order block. This prevents the
  // optimizer from inserting a half sibling into a full-sibling group;
  // reflow may still separate cards when spouse-pair constraints require it.
  // Internal permutations are useful only when a sibling has their own spouse
  // or child outside this group.
  const childrenByParentKey = new Map<string, Map<string, string[]>>()
  parentsByChild.forEach((parents, childId) => {
    const knownParents = [...parents].filter((personId) => knownPeople.has(personId)).sort()
    const parentKey = knownParents.join(':')
    knownParents.forEach((parentId) => {
      const groups = childrenByParentKey.get(parentId) ?? new Map<string, string[]>()
      const children = groups.get(parentKey) ?? []
      children.push(childId)
      groups.set(parentKey, children)
      childrenByParentKey.set(parentId, groups)
    })
  });
  [...childrenByParentKey.entries()]
    .sort(([first], [second]) => first.localeCompare(second))
    .forEach(([, groups]) => {
      const blocks: ChildBlock[] = [...groups.values()]
        .map((childIds) => ({
          childIds: personOrder(snapshot, childIds),
          canReorderInternally: childIds.some(hasExternalFamily)
            && new Set(childIds.map((childId) => subtreeSignature(childId))).size > 1,
        }))
        .sort((first, second) => (
          (snapshot.people.find((person) => person.id === first.childIds[0])?.coordinates.x ?? 0)
          - (snapshot.people.find((person) => person.id === second.childIds[0])?.coordinates.x ?? 0)
          || first.childIds[0]!.localeCompare(second.childIds[0]!)
        ))
      const childIds = blocks.flatMap((block) => block.childIds)
      if (
        childIds.length < 2
        || childIds.some((personId) => claimed.has(personId))
        || !blocks.some((block) => block.canReorderInternally)
      ) {
        return
      }
      const internalSize = blocks.reduce((product, block) => (
        product * (block.canReorderInternally ? factorial(block.childIds.length) : 1n)
      ), 1n)
      const blockSize = factorial(blocks.length)
      dimensions.push({
        memberIds: new Set(childIds),
        size: blockSize * internalSize,
        apply: (rank, positions) => {
          let remainder = rank
          const orderedBlocks = unrankPermutation(blocks, remainder % blockSize)
          remainder /= blockSize
          const orderedChildren = orderedBlocks.flatMap((block) => {
            if (!block.canReorderInternally) {
              return block.childIds
            }
            const size = factorial(block.childIds.length)
            const ordered = unrankPermutation(block.childIds, remainder % size)
            remainder /= size
            return ordered
          })
          assignOrderToSlots(childIds, orderedChildren, positions)
        },
      })
      childIds.forEach((personId) => claimed.add(personId))
    })

  return dimensions
}

export const measureTreeWidth = (snapshot: Pick<TreeSnapshot, 'people'>) => {
  if (snapshot.people.length === 0) {
    return 0
  }
  const left = Math.min(...snapshot.people.map((person) => person.coordinates.x))
  const right = Math.max(...snapshot.people.map((person) => person.coordinates.x + PERSON_CARD_WIDTH))
  return right - left
}

export const estimateLayoutCandidates = (snapshot: TreeSnapshot) => buildDimensions(snapshot)
  .reduce((product, dimension) => product * dimension.size, 1n)

const withReflowedCoordinates = (snapshot: TreeSnapshot) => {
  const coordinates = new Map(reflowTree(snapshot).map((update) => [
    update.personId,
    update.coordinates,
  ]))
  return {
    ...snapshot,
    people: snapshot.people.map((person) => ({
      ...person,
      coordinates: coordinates.get(person.id) ?? person.coordinates,
    })),
  }
}

export async function optimizeTreeLayout(
  snapshot: TreeSnapshot,
  options: {
    isCancelled?: () => boolean
    limit?: bigint
    onProgress?: (progress: LayoutOptimizationProgress) => void
    yieldControl?: () => Promise<void>
  } = {},
): Promise<LayoutOptimizationResult | null> {
  const dimensions = buildDimensions(snapshot)
  const candidateCount = dimensions.reduce((product, dimension) => product * dimension.size, 1n)
  const total = options.limit === undefined
    ? candidateCount
    : candidateCount < options.limit ? candidateCount : options.limit
  const currentWidth = measureTreeWidth(snapshot)
  let bestSnapshot: TreeSnapshot | null = null
  let bestWidth = currentWidth
  let checked = 0n

  for (let candidate = 0n; candidate < total; candidate += 1n) {
    if (options.isCancelled?.()) {
      return null
    }
    let remainder = candidate
    const positions = new Map(snapshot.people.map((person) => [person.id, person.coordinates]))
    dimensions.forEach((dimension) => {
      dimension.apply(remainder % dimension.size, positions)
      remainder /= dimension.size
    })
    const candidateSnapshot = withReflowedCoordinates({
      ...snapshot,
      people: snapshot.people.map((person) => ({
        ...person,
        coordinates: positions.get(person.id) ?? person.coordinates,
      })),
    })
    const width = measureTreeWidth(candidateSnapshot)
    if (width < bestWidth) {
      bestWidth = width
      bestSnapshot = candidateSnapshot
    }
    checked += 1n
    if (checked % 25n === 0n || checked === total) {
      options.onProgress?.({ checked, total })
      await options.yieldControl?.()
    }
  }

  const coordinateUpdates = bestSnapshot === null
    ? []
    : bestSnapshot.people.flatMap((person) => {
      const original = snapshot.people.find((item) => item.id === person.id)
      return original
        && (original.coordinates.x !== person.coordinates.x || original.coordinates.y !== person.coordinates.y)
        ? [{ personId: person.id, coordinates: person.coordinates }]
        : []
    })
  return {
    candidateCount,
    checkedCount: checked,
    coordinateUpdates,
    currentWidth,
    limited: total < candidateCount,
    optimizedWidth: bestWidth,
  }
}
