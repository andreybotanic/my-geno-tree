import type { Edge, XYPosition } from '@xyflow/react'
import type { MarriagePeriod, ParentLink, TreeSnapshot } from './api/generated'
import { effectiveReference } from './focusedTree'

export const PERSON_CARD_WIDTH = 260
export const PERSON_CARD_HEIGHT = 160
const HORIZONTAL_STEP = 320
const VERTICAL_STEP = 240
const PARTNER_OUTLET_SPACING = 32

export type FamilyEdgeData = {
  bracketDepth: number
  childPoints: Array<{ id: string; topX: number; topY: number }>
  married: boolean
  personIds?: [string, string]
  reference?: boolean
  sourceOffset: number
  targetOffset: number
}

export type FamilyFlowEdge = Edge<FamilyEdgeData, 'family'>
export type SingleParentFlowEdge = Edge<Record<string, never>, 'singleParent'>
export type TreeFlowEdge = FamilyFlowEdge | SingleParentFlowEdge

type Family = {
  children: Set<string>
  married: boolean
  people: [string, string]
}

const pairKey = (first: string, second: string) => (
  [first, second].sort((left, right) => left.localeCompare(right)).join(':')
)

export function layoutTree(snapshot: TreeSnapshot): Map<string, XYPosition> {
  const ids = snapshot.people.map((person) => person.id).sort((a, b) => a.localeCompare(b))
  if (ids.length === 0) return new Map()

  const parent = new Map(ids.map((id) => [id, id]))
  const find = (id: string): string => {
    const current = parent.get(id) ?? id
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
  const ranks = new Map([...members.keys()].map((id) => [id, 0]))
  for (let pass = 0; pass < members.size; pass += 1) {
    let changed = false
    for (const [source, target] of componentLinks) {
      const next = (ranks.get(source) ?? 0) + 1
      if (next > (ranks.get(target) ?? 0)) {
        ranks.set(target, next)
        changed = true
      }
    }
    if (!changed) break
  }

  const reference = effectiveReference(snapshot)
  const referenceIds = reference?.type === 'person'
    ? [reference.personId]
    : reference?.personIds ?? []
  const referenceRoots = new Set(referenceIds.map(find))
  const distances = componentDistances([...members.keys()], componentLinks, referenceRoots)
  const rows = new Map<number, string[]>()
  for (const componentId of members.keys()) {
    const rank = ranks.get(componentId) ?? 0
    const row = rows.get(rank) ?? []
    row.push(componentId)
    rows.set(rank, row)
  }

  const positions = new Map<string, XYPosition>()
  for (const [rank, components] of [...rows].sort(([a], [b]) => a - b)) {
    components.sort((left, right) => (
      (distances.get(left) ?? Number.MAX_SAFE_INTEGER)
      - (distances.get(right) ?? Number.MAX_SAFE_INTEGER)
      || left.localeCompare(right)
    ))
    const orderedPeople = components.flatMap((componentId) => {
      const group = [...(members.get(componentId) ?? [])]
      return group.sort((left, right) => (
        Number(!referenceIds.includes(left)) - Number(!referenceIds.includes(right))
        || left.localeCompare(right)
      ))
    })
    const rowWidth = (orderedPeople.length - 1) * HORIZONTAL_STEP
    orderedPeople.forEach((id, index) => {
      positions.set(id, {
        x: index * HORIZONTAL_STEP - rowWidth / 2,
        y: rank * VERTICAL_STEP,
      })
    })
  }

  const referencePositions = referenceIds
    .map((id) => positions.get(id))
    .filter((position): position is XYPosition => position !== undefined)
  if (referencePositions.length > 0) {
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
  }

  return positions
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

  const outlets = new Map<string, Array<{ key: string; partnerX: number }>>()
  for (const [key, family] of families) {
    for (const [personId, partnerId] of [family.people, [...family.people].reverse()] as [string, string][]) {
      const values = outlets.get(personId) ?? []
      values.push({ key, partnerX: positions.get(partnerId)?.x ?? 0 })
      outlets.set(personId, values)
    }
  }
  const offsets = new Map<string, number>()
  for (const [personId, values] of outlets) {
    const ownX = positions.get(personId)?.x ?? 0
    values.sort((a, b) => a.partnerX - b.partnerX || a.key.localeCompare(b.key))
    values.forEach((value, index) => offsets.set(
      `${value.key}:${personId}`,
      (index - (values.length - 1) / 2) * PARTNER_OUTLET_SPACING
        + Math.sign(value.partnerX - ownX) * Math.min(index, 1) * 4,
    ))
  }

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
        bracketDepth: 24,
        childPoints,
        married: family.married,
        personIds: family.people,
        reference: family.married && referencePair === key,
        sourceOffset: offsets.get(`${key}:${first}`) ?? 0,
        targetOffset: offsets.get(`${key}:${second}`) ?? 0,
      },
    })
  }
  return edges.sort((a, b) => a.id.localeCompare(b.id))
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
    for (const next of [...(neighbors.get(current) ?? [])].sort((a, b) => a.localeCompare(b))) {
      if (distances.has(next)) continue
      distances.set(next, (distances.get(current) ?? 0) + 1)
      pending.push(next)
    }
  }
  return distances
}
