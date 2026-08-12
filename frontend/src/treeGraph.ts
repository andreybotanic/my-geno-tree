import type { Edge, XYPosition } from '@xyflow/react'
import type { MarriagePeriod, ParentLink, TreeSnapshot } from './api/generated'
import { refineFamilyLayout } from './familyLayout'
import { effectiveReference } from './focusedTree'

export const PERSON_CARD_WIDTH = 260
export const PERSON_CARD_HEIGHT = 160
export const CHILD_BRANCH_OFFSET = 28
export const PAIR_TO_CHILD_CLEARANCE = 12
const HORIZONTAL_STEP = 320
const VERTICAL_STEP = 240
const PARTNER_OUTLET_SPACING = 32
const MIN_BRACKET_DEPTH = 16
const MIN_NESTED_BRACKET_SEPARATION = 12

export type FamilyEdgeData = {
  bracketDepth: number
  childPoints: Array<{ id: string; topX: number; topY: number }>
  married: boolean
  personIds?: [string, string]
  reference?: boolean
  sourceOffset: number
  targetOffset: number
  visualState?: 'highlight' | 'muted'
}

export type FamilyFlowEdge = Edge<FamilyEdgeData, 'family'>
export type SingleParentFlowEdge = Edge<{
  bracketDepth?: never
  visualState?: 'highlight' | 'muted'
}, 'singleParent'>
export type TreeFlowEdge = FamilyFlowEdge | SingleParentFlowEdge

type Family = {
  children: Set<string>
  married: boolean
  people: [string, string]
}

type PartnerOutlet = {
  key: string
  partnerId: string
  partnerX: number
  personId: string
}

const pairKey = (first: string, second: string) => (
  [first, second].sort((left, right) => left.localeCompare(right)).join(':')
)

export function layoutTree(snapshot: TreeSnapshot): Map<string, XYPosition> {
  const ids = snapshot.people.map((person) => person.id).sort((a, b) => a.localeCompare(b))
  if (ids.length === 0) return new Map()

  const parent = new Map(ids.map((id) => [id, id]))
  const find = (id: string): string => {
    const current = parent.get(id)!
    if (current === id) return id
    const root = find(current)
    parent.set(id, root)
    return root
  }
  const join = (first: string, second: string) => {
    const firstRoot = find(first)
    const secondRoot = find(second)
    if (firstRoot === secondRoot) return
    const ordered = [firstRoot, secondRoot].sort((a, b) => a.localeCompare(b))
    parent.set(ordered[1]!, ordered[0]!)
  }

  for (const marriage of sortedMarriages(snapshot.marriages)) {
    join(marriage.malePersonId, marriage.femalePersonId)
  }
  for (const parents of parentsByChild(snapshot.parentLinks).values()) {
    if (parents.length === 2) join(parents[0]!.parentId, parents[1]!.parentId)
  }

  const members = new Map<string, string[]>()
  for (const id of ids) {
    const root = find(id)
    const group = members.get(root) ?? []
    group.push(id)
    members.set(root, group)
  }
  for (const group of members.values()) group.sort((a, b) => a.localeCompare(b))

  const componentLinks = [...snapshot.parentLinks]
    .sort(parentLinkComparator)
    .map((link) => [find(link.parentId), find(link.childId)] as const)
    .filter(([source, target]) => source !== target)
  const reference = effectiveReference(snapshot)!
  const referenceIds = reference.type === 'person'
    ? [reference.personId]
    : reference.personIds
  const referenceRoots = new Set(referenceIds.map(find))
  const ranks = generationRanks(
    [...members.keys()],
    componentLinks,
    referenceRoots,
  )
  const distances = componentDistances([...members.keys()], componentLinks, referenceRoots)
  const rows = new Map<number, string[]>()
  for (const componentId of members.keys()) {
    const rank = ranks.get(componentId)!
    const row = rows.get(rank) ?? []
    row.push(componentId)
    rows.set(rank, row)
  }

  const seedPositions = new Map<string, XYPosition>()
  for (const [rank, components] of [...rows].sort(([a], [b]) => a - b)) {
    components.sort((left, right) => (
      (distances.get(left) ?? Number.MAX_SAFE_INTEGER)
      - (distances.get(right) ?? Number.MAX_SAFE_INTEGER)
      || left.localeCompare(right)
    ))
    const orderedPeople = components.flatMap((componentId) => {
      const group = [...members.get(componentId)!]
      return group.sort((left, right) => (
        Number(!referenceIds.includes(left)) - Number(!referenceIds.includes(right))
        || left.localeCompare(right)
      ))
    })
    const rowWidth = (orderedPeople.length - 1) * HORIZONTAL_STEP
    orderedPeople.forEach((id, index) => {
      seedPositions.set(id, {
        x: index * HORIZONTAL_STEP - rowWidth / 2,
        y: rank * VERTICAL_STEP,
      })
    })
  }

  const positions = refineFamilyLayout(snapshot, seedPositions)
  const referencePositions = referenceIds
    .map((id) => positions.get(id)!)
  const centerX = referencePositions.reduce(
    (sum, position) => sum + position.x + PERSON_CARD_WIDTH / 2,
    0,
  ) / referencePositions.length
  const centerY = referencePositions.reduce(
    (sum, position) => sum + position.y + PERSON_CARD_HEIGHT / 2,
    0,
  ) / referencePositions.length
  for (const [id, position] of positions) {
    positions.set(id, { x: position.x - centerX, y: position.y - centerY })
  }

  return positions
}

function generationRanks(
  componentIds: string[],
  componentLinks: ReadonlyArray<readonly [string, string]>,
  referenceRoots: ReadonlySet<string>,
): Map<string, number> {
  const neighbors = new Map(componentIds.map((id) => [
    id,
    [] as Array<{ id: string; offset: number }>,
  ]))
  for (const [parentId, childId] of componentLinks) {
    neighbors.get(parentId)!.push({ id: childId, offset: 1 })
    neighbors.get(childId)!.push({ id: parentId, offset: -1 })
  }
  for (const adjacent of neighbors.values()) {
    adjacent.sort((first, second) => (
      first.id.localeCompare(second.id) || first.offset - second.offset
    ))
  }

  const ranks = new Map<string, number>()
  const pending = [...referenceRoots].sort((a, b) => a.localeCompare(b))
  for (const root of pending) ranks.set(root, 0)
  for (let index = 0; index < pending.length; index += 1) {
    const current = pending[index]!
    for (const neighbor of neighbors.get(current) ?? []) {
      const candidate = ranks.get(current)! + neighbor.offset
      const existing = ranks.get(neighbor.id)
      if (existing === undefined) {
        ranks.set(neighbor.id, candidate)
        pending.push(neighbor.id)
      } else if (existing !== candidate) {
        return longestPathRanks(componentIds, componentLinks)
      }
    }
  }

  return ranks.size === componentIds.length
    ? ranks
    : longestPathRanks(componentIds, componentLinks)
}

function longestPathRanks(
  componentIds: string[],
  componentLinks: ReadonlyArray<readonly [string, string]>,
): Map<string, number> {
  const ranks = new Map(componentIds.map((id) => [id, 0]))
  for (let pass = 0; pass < componentIds.length; pass += 1) {
    let changed = false
    for (const [source, target] of componentLinks) {
      const next = ranks.get(source)! + 1
      if (next > ranks.get(target)!) {
        ranks.set(target, next)
        changed = true
      }
    }
    if (!changed) break
  }
  return ranks
}

export function buildTreeEdges(
  snapshot: Pick<TreeSnapshot, 'parentLinks' | 'marriages' | 'tree'>,
  positions: ReadonlyMap<string, XYPosition>,
): TreeFlowEdge[] {
  const families = new Map<string, Family>()
  for (const marriage of sortedMarriages(snapshot.marriages)) {
    const people = orderedPair(marriage.malePersonId, marriage.femalePersonId)
    const key = pairKey(...people)
    families.set(key, {
      children: families.get(key)?.children ?? new Set(),
      married: true,
      people,
    })
  }

  const edges: TreeFlowEdge[] = []
  for (const [childId, links] of parentsByChild(snapshot.parentLinks)) {
    if (links.length === 1) {
      const link = links[0]!
      if (positions.has(link.parentId) && positions.has(childId)) {
        edges.push({
          id: `parent:${link.id}`,
          source: link.parentId,
          sourceHandle: 'bottom-source',
          target: childId,
          targetHandle: 'top-target',
          type: 'singleParent',
        })
      }
      continue
    }
    const people = orderedPair(links[0]!.parentId, links[1]!.parentId)
    const key = pairKey(...people)
    const family = families.get(key) ?? { children: new Set(), married: false, people }
    family.children.add(childId)
    families.set(key, family)
  }

  const outlets = new Map<string, PartnerOutlet[]>()
  for (const [key, family] of families) {
    for (const [personId, partnerId] of [family.people, [...family.people].reverse()] as [string, string][]) {
      const values = outlets.get(personId) ?? []
      values.push({
        key,
        partnerId,
        partnerX: positions.get(partnerId)?.x ?? 0,
        personId,
      })
      outlets.set(personId, values)
    }
  }
  const outletLayout = new Map<string, { bracketDepth: number; offset: number }>()
  for (const values of outlets.values()) {
    values
      .sort((left, right) => (
        (partnerSide(left, positions) === 'left' ? -1 : 1)
        - (partnerSide(right, positions) === 'left' ? -1 : 1)
        || right.partnerX - left.partnerX
        || left.key.localeCompare(right.key)
      ))
      .forEach((outlet, index) => outletLayout.set(
        `${outlet.key}:${outlet.personId}`,
        {
          bracketDepth: 24,
          offset: (index - (values.length - 1) / 2) * PARTNER_OUTLET_SPACING,
        },
      ))
  }
  for (const values of outlets.values()) {
    for (const side of ['left', 'right'] as const) {
      values
        .filter((outlet) => partnerSide(outlet, positions) === side)
        .sort((left, right) => (
          bracketLength(left, outletLayout, positions)
          - bracketLength(right, outletLayout, positions)
          || left.key.localeCompare(right.key)
        ))
        .forEach((outlet, index) => {
          const outletKey = `${outlet.key}:${outlet.personId}`
          const current = outletLayout.get(outletKey)!
          outletLayout.set(outletKey, {
            ...current,
            bracketDepth: 24 + index * 18,
          })
        })
    }
  }

  const familyBracketDepths = resolveFamilyBracketDepths(
    families,
    outlets,
    outletLayout,
    positions,
  )

  const reference = snapshot.tree.reference
  const referencePair = reference?.type === 'marriage'
    ? pairKey(...reference.personIds)
    : null
  for (const [key, family] of [...families].sort(([a], [b]) => a.localeCompare(b))) {
    const [first, second] = [...family.people].sort((left, right) => (
      (positions.get(left)?.x ?? 0) - (positions.get(right)?.x ?? 0)
      || left.localeCompare(right)
    )) as [string, string]
    if (!positions.has(first) || !positions.has(second)) continue
    const sourceLayout = outletLayout.get(`${key}:${first}`)!
    const targetLayout = outletLayout.get(`${key}:${second}`)!
    const childPoints = [...family.children]
      .filter((id) => positions.has(id))
      .map((id) => ({
        id,
        topX: positions.get(id)!.x + PERSON_CARD_WIDTH / 2,
        topY: positions.get(id)!.y,
      }))
      .sort((a, b) => a.topX - b.topX || a.id.localeCompare(b.id))
    edges.push({
      id: `family:${key}`,
      source: first,
      sourceHandle: 'bottom-source',
      target: second,
      targetHandle: 'bottom-target',
      type: 'family',
      data: {
        bracketDepth: familyBracketDepths.get(key)!,
        childPoints,
        married: family.married,
        personIds: family.people,
        reference: family.married && referencePair === key,
        sourceOffset: sourceLayout.offset,
        targetOffset: targetLayout.offset,
      },
    })
  }
  return edges.sort((a, b) => a.id.localeCompare(b.id))
}

function resolveFamilyBracketDepths(
  families: ReadonlyMap<string, Family>,
  outlets: ReadonlyMap<string, PartnerOutlet[]>,
  outletLayout: ReadonlyMap<string, { bracketDepth: number; offset: number }>,
  positions: ReadonlyMap<string, XYPosition>,
): Map<string, number> {
  const maximumDepths = new Map<string, number>()
  const depths = new Map<string, number>()
  for (const [key, family] of families) {
    if (!family.people.every((id) => positions.has(id))) continue
    const parentBottom = Math.max(...family.people.map((id) => (
      positions.get(id)!.y + PERSON_CARD_HEIGHT
    )))
    const childTop = Math.min(...[...family.children]
      .filter((id) => positions.has(id))
      .map((id) => positions.get(id)!.y))
    const maximumDepth = Number.isFinite(childTop)
      ? childTop - CHILD_BRANCH_OFFSET - PAIR_TO_CHILD_CLEARANCE - parentBottom
      : Number.POSITIVE_INFINITY
    maximumDepths.set(key, maximumDepth)
    const [first, second] = family.people
    const desiredDepth = Math.max(
      outletLayout.get(`${key}:${first}`)!.bracketDepth,
      outletLayout.get(`${key}:${second}`)!.bracketDepth,
    )
    depths.set(key, Math.min(desiredDepth, maximumDepth))
  }

  for (const values of outlets.values()) {
    for (const side of ['left', 'right'] as const) {
      const nested = values
        .filter((outlet) => partnerSide(outlet, positions) === side)
        .filter((outlet) => maximumDepths.has(outlet.key))
        .filter((outlet) => Number.isFinite(maximumDepths.get(outlet.key)))
        .sort((left, right) => (
          bracketLength(left, outletLayout, positions) - bracketLength(right, outletLayout, positions)
          || left.key.localeCompare(right.key)
        ))
      let deeperDepth = Number.POSITIVE_INFINITY
      for (const outlet of [...nested].reverse()) {
        const depth = Math.min(
          depths.get(outlet.key)!,
          deeperDepth - MIN_NESTED_BRACKET_SEPARATION,
        )
        depths.set(outlet.key, Math.max(MIN_BRACKET_DEPTH, depth))
        deeperDepth = depths.get(outlet.key)!
      }
    }
  }

  return depths
}

function partnerSide(
  outlet: PartnerOutlet,
  positions: ReadonlyMap<string, XYPosition>,
): 'left' | 'right' {
  return outlet.partnerX < (positions.get(outlet.personId)?.x ?? 0) ? 'left' : 'right'
}

function bracketLength(
  outlet: PartnerOutlet,
  outletLayout: ReadonlyMap<string, { bracketDepth: number; offset: number }>,
  positions: ReadonlyMap<string, XYPosition>,
): number {
  const person = positions.get(outlet.personId)
  const partner = positions.get(outlet.partnerId)
  if (!person || !partner) return 0
  const ownOffset = outletLayout.get(`${outlet.key}:${outlet.personId}`)?.offset ?? 0
  const partnerOffset = outletLayout.get(`${outlet.key}:${outlet.partnerId}`)?.offset ?? 0
  return Math.abs(person.x + ownOffset - partner.x - partnerOffset)
}

function parentsByChild(parentLinks: ParentLink[]) {
  const result = new Map<string, ParentLink[]>()
  for (const link of [...parentLinks].sort(parentLinkComparator)) {
    const links = result.get(link.childId) ?? []
    links.push(link)
    result.set(link.childId, links)
  }
  return result
}

function parentLinkComparator(left: ParentLink, right: ParentLink) {
  return left.childId.localeCompare(right.childId)
    || left.role.localeCompare(right.role)
    || left.parentId.localeCompare(right.parentId)
    || left.id.localeCompare(right.id)
}

function sortedMarriages(marriages: MarriagePeriod[]) {
  return [...marriages].sort((left, right) => (
    pairKey(left.malePersonId, left.femalePersonId)
      .localeCompare(pairKey(right.malePersonId, right.femalePersonId))
    || left.id.localeCompare(right.id)
  ))
}

function orderedPair(first: string, second: string): [string, string] {
  return first.localeCompare(second) <= 0 ? [first, second] : [second, first]
}

function componentDistances(
  components: string[],
  links: ReadonlyArray<readonly [string, string]>,
  starts: Set<string>,
) {
  const neighbors = new Map(components.map((id) => [id, new Set<string>()]))
  for (const [first, second] of links) {
    neighbors.get(first)?.add(second)
    neighbors.get(second)?.add(first)
  }
  const distances = new Map<string, number>()
  const pending = [...starts].sort((a, b) => a.localeCompare(b))
  pending.forEach((id) => distances.set(id, 0))
  while (pending.length > 0) {
    const current = pending.shift()!
    for (const next of [...neighbors.get(current)!].sort((a, b) => a.localeCompare(b))) {
      if (distances.has(next)) continue
      distances.set(next, distances.get(current)! + 1)
      pending.push(next)
    }
  }
  return distances
}
