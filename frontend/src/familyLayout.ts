import {
  Expression,
  Operator,
  Solver,
  Strength,
  Variable,
} from '@lume/kiwi'
import type { XYPosition } from '@xyflow/react'
import type { ParentLink, TreeSnapshot } from './api/generated'
import { chooseChildGapDistances } from './childGapPolicy'
import {
  scheduleGenerations,
  selectAnchorGeneration,
  type GenerationCandidate,
} from './layeredGenerationPolicy'

const PERSON_CARD_WIDTH = 260
const HORIZONTAL_STEP = 320
const SPOUSE_CARD_GAP = 60
const HALF_SIBLING_CARD_GAP = 60
const SIBLING_CARD_GAP = 40
const OTHER_CARD_GAP = 80
const BEAM_WIDTH = 32
const MAX_ORDERING_SWEEPS = 8
const COORDINATE_TOLERANCE = 0.001
const TRACED_TREE_ID = '019fbc81-55ed-7721-ae53-c517ec550b6a'

type LayoutTraceEvent = {
  details: Record<string, unknown>
  phase: string
}

type LayoutTrace = {
  events: LayoutTraceEvent[]
  labels: Record<string, string>
  treeId: string
}

type Family = {
  childIds: string[]
  key: string
  parentIds: string[]
}

type RowStructure = {
  innerIdsByOuter: ReadonlyMap<string, string[]>
  outerIds: string[]
  peopleByInner: ReadonlyMap<string, string[]>
}

type OrderingState = {
  innerOrderByOuter: Map<string, string[]>
  outerOrder: string[]
  personOrderByInner: Map<string, string[]>
}

type LayoutContext = {
  childrenByParent: ReadonlyMap<string, Set<string>>
  families: Family[]
  ids: string[]
  marriedPartnersByPerson: ReadonlyMap<string, Set<string>>
  parentLinks: ParentLink[]
  parentsByChild: ReadonlyMap<string, Set<string>>
  partnersByPerson: ReadonlyMap<string, Set<string>>
  referenceIds: Set<string>
  roleByParentAndChild: ReadonlyMap<string, ParentLink['role']>
  rowByPerson: ReadonlyMap<string, number>
  rows: number[]
  rowStructures: ReadonlyMap<number, RowStructure>
  seedPositions: ReadonlyMap<string, XYPosition>
  trace: LayoutTrace | null
}

type ChildGapPolicy = {
  childIds: string[]
  distances: number[]
  variable: boolean
}

type Score = readonly [number, number, number, number, number, number, string]

function traceEvent(
  context: LayoutContext,
  phase: string,
  details: Record<string, unknown>,
): void {
  context.trace?.events.push({ details, phase })
}

function traceScore(score: Score): Record<string, unknown> {
  return {
    roleOrBranchOrientationViolations: score[0],
    maximumNeighborDeviation: score[1],
    edgeOrderInversions: score[2],
    totalNeighborDeviation: score[3],
    excessChildSpace: score[4],
    partnerSpan: score[5],
    uuidOrder: score[6],
  }
}

function tracedOrder(context: LayoutContext, ids: readonly string[]): Array<Record<string, string>> {
  return ids.map((id) => ({ id, label: context.trace?.labels[id] ?? id }))
}

class DisjointSet {
  private readonly parents: Map<string, string>

  constructor(ids: readonly string[]) {
    this.parents = new Map(ids.map((id) => [id, id]))
  }

  find(id: string): string {
    const parent = this.parents.get(id) ?? id
    if (parent === id) return id
    const root = this.find(parent)
    this.parents.set(id, root)
    return root
  }

  join(first: string, second: string): void {
    const roots = [this.find(first), this.find(second)].sort((a, b) => a.localeCompare(b))
    if (roots[0] !== roots[1]) this.parents.set(roots[1]!, roots[0]!)
  }
}

/**
 * Builds transient coordinates from the graph alone. The supplied positions only
 * carry deterministic generation numbers; their horizontal values are not reused.
 */
export function refineFamilyLayout(
  snapshot: TreeSnapshot,
  seedPositions: ReadonlyMap<string, XYPosition>,
): Map<string, XYPosition> {
  const context = createContext(snapshot, seedPositions)
  if (context.ids.length === 0) return new Map()

  const anchorRow = selectAnchorRow(context)
  const orders = orderRows(context, anchorRow)
  const preliminary = solveCoordinates(context, orders, [], 'preliminary')
  const childGapPolicies = buildChildGapPolicies(context, orders, preliminary)
  const coordinates = solveCoordinates(context, orders, childGapPolicies, 'final')

  if (context.trace) {
    const host = globalThis as typeof globalThis & { __familyLayoutTrace?: LayoutTrace }
    host.__familyLayoutTrace = context.trace
    const serializedTrace = JSON.stringify(context.trace, null, 2)
    const traceElement = document.querySelector<HTMLScriptElement>('#family-layout-trace')
      ?? document.body.appendChild(document.createElement('script'))
    traceElement.id = 'family-layout-trace'
    traceElement.type = 'application/json'
    traceElement.textContent = serializedTrace
    console.info(
      `[family-layout] Trace for ${context.trace.treeId} is available as globalThis.__familyLayoutTrace`,
      context.trace,
    )
  }

  return new Map(context.ids.map((id) => [id, {
    x: coordinates.get(id)! - PERSON_CARD_WIDTH / 2,
    y: context.rowByPerson.get(id)!,
  }]))
}

function createContext(
  snapshot: TreeSnapshot,
  seedPositions: ReadonlyMap<string, XYPosition>,
): LayoutContext {
  const ids = snapshot.people.map(({ id }) => id).sort((a, b) => a.localeCompare(b))
  const idSet = new Set(ids)
  const parentLinks = [...snapshot.parentLinks]
    .filter(({ parentId, childId }) => idSet.has(parentId) && idSet.has(childId))
    .sort(parentLinkComparator)
  const parentsByChild = new Map<string, Set<string>>()
  const childrenByParent = new Map<string, Set<string>>()
  const roleByParentAndChild = new Map<string, ParentLink['role']>()
  for (const link of parentLinks) {
    addToSet(parentsByChild, link.childId, link.parentId)
    addToSet(childrenByParent, link.parentId, link.childId)
    roleByParentAndChild.set(`${link.parentId}:${link.childId}`, link.role)
  }

  const pairMap = new Map<string, [string, string]>()
  const marriedPartnersByPerson = new Map<string, Set<string>>()
  const registerPair = (first: string, second: string) => {
    if (first === second || !idSet.has(first) || !idSet.has(second)) return
    const pair = orderedPair(first, second)
    pairMap.set(pair.join(':'), pair)
  }
  for (const item of [...snapshot.marriages].sort((a, b) => a.id.localeCompare(b.id))) {
    registerPair(item.malePersonId, item.femalePersonId)
    addToSet(marriedPartnersByPerson, item.malePersonId, item.femalePersonId)
    addToSet(marriedPartnersByPerson, item.femalePersonId, item.malePersonId)
  }
  for (const parentIds of parentsByChild.values()) {
    const ordered = [...parentIds].sort((a, b) => a.localeCompare(b))
    if (ordered.length === 2) registerPair(ordered[0]!, ordered[1]!)
  }
  const partnersByPerson = new Map<string, Set<string>>()
  for (const [first, second] of [...pairMap.values()].sort(pairComparator)) {
    addToSet(partnersByPerson, first, second)
    addToSet(partnersByPerson, second, first)
  }

  const rowByPerson = new Map(ids.map((id) => [
    id,
    seedPositions.get(id)?.y ?? 0,
  ]))
  const rows = [...new Set(rowByPerson.values())].sort((a, b) => a - b)
  const familiesByParents = new Map<string, Family>()
  for (const [childId, parentSet] of [...parentsByChild].sort(([a], [b]) => a.localeCompare(b))) {
    const parentIds = [...parentSet].sort((a, b) => a.localeCompare(b))
    const key = parentIds.join(':')
    const family = familiesByParents.get(key) ?? { childIds: [], key, parentIds }
    family.childIds.push(childId)
    familiesByParents.set(key, family)
  }
  const families = [...familiesByParents.values()]
    .map((family) => ({ ...family, childIds: family.childIds.sort((a, b) => a.localeCompare(b)) }))
    .sort((a, b) => a.key.localeCompare(b.key))

  const rowStructures = new Map<number, RowStructure>()
  for (const row of rows) {
    const rowIds = ids.filter((id) => rowByPerson.get(id) === row)
    rowStructures.set(row, buildRowStructure(rowIds, partnersByPerson, childrenByParent))
  }
  const reference = snapshot.tree.reference
  const referenceIds = new Set(reference?.type === 'person'
    ? [reference.personId]
    : reference?.personIds ?? [])
  const trace = snapshot.tree.id === TRACED_TREE_ID
    ? {
        events: [],
        labels: Object.fromEntries(snapshot.people.map((person) => {
          const label = [person.birthSurname, person.givenName, person.patronymic]
            .flatMap((part) => part.state === 'known' ? [part.value] : [])
            .join(' ')
          return [person.id, label || 'Неизвестная персона']
        })),
        treeId: snapshot.tree.id,
      }
    : null

  return {
    childrenByParent,
    families,
    ids,
    marriedPartnersByPerson,
    parentLinks,
    parentsByChild,
    partnersByPerson,
    referenceIds,
    roleByParentAndChild,
    rowByPerson,
    rows,
    rowStructures,
    seedPositions,
    trace,
  }
}

/**
 * Partner components are indivisible. Sibling components contain those partner
 * components, so siblings may only be separated by one of their spouses.
 */
function buildRowStructure(
  rowIds: string[],
  partnersByPerson: ReadonlyMap<string, Set<string>>,
  childrenByParent: ReadonlyMap<string, Set<string>>,
): RowStructure {
  const rowSet = new Set(rowIds)
  const partnerSet = new DisjointSet(rowIds)
  for (const id of rowIds) {
    for (const partnerId of partnersByPerson.get(id) ?? []) {
      if (rowSet.has(partnerId)) partnerSet.join(id, partnerId)
    }
  }
  const peopleByInner = groupByRoot(rowIds, partnerSet)
  const innerByPerson = new Map<string, string>()
  for (const [innerId, people] of peopleByInner) {
    people.forEach((id) => innerByPerson.set(id, innerId))
  }

  const innerIds = [...peopleByInner.keys()].sort((a, b) => a.localeCompare(b))
  const outerSet = new DisjointSet(innerIds)
  for (const children of childrenByParent.values()) {
    const childInnerIds = [...children]
      .filter((id) => rowSet.has(id))
      .map((id) => innerByPerson.get(id)!)
      .filter((id, index, values) => values.indexOf(id) === index)
      .sort((a, b) => a.localeCompare(b))
    for (let index = 1; index < childInnerIds.length; index += 1) {
      outerSet.join(childInnerIds[0]!, childInnerIds[index]!)
    }
  }
  const innerIdsByOuter = groupByRoot(innerIds, outerSet)

  return {
    innerIdsByOuter,
    outerIds: [...innerIdsByOuter.keys()].sort((a, b) => a.localeCompare(b)),
    peopleByInner,
  }
}

function groupByRoot(ids: string[], set: DisjointSet): Map<string, string[]> {
  const result = new Map<string, string[]>()
  for (const id of [...ids].sort((a, b) => a.localeCompare(b))) {
    const root = set.find(id)
    const values = result.get(root) ?? []
    values.push(id)
    result.set(root, values)
  }
  return result
}

function selectAnchorRow(context: LayoutContext): number {
  const peopleByRow = peopleByRowMap(context)
  const referenceRows = [...context.referenceIds]
    .map((id) => context.rowByPerson.get(id))
    .filter((row): row is number => row !== undefined)
  const referenceRow = referenceRows.length === 0
    ? 0
    : referenceRows.reduce((sum, row) => sum + row, 0) / referenceRows.length
  const candidates = generationCandidates(context, peopleByRow)
  const selectedRow = selectAnchorGeneration(candidates, referenceRow)
  traceEvent(context, 'anchor-generation', {
    candidates: candidates.map((candidate) => ({
      ...candidate,
      people: tracedOrder(context, peopleByRow.get(candidate.row) ?? []),
      referenceDistance: Math.abs(candidate.row - referenceRow),
    })),
    referenceRow,
    selectedRow,
  })

  return selectedRow
}

function estimatedRowWidth(context: LayoutContext, ids: string[]): number {
  const ordered = [...ids].sort((a, b) => a.localeCompare(b))
  return ordered.slice(1).reduce((width, id, index) => (
    width + minimumDistance(context, ordered[index]!, id)
  ), PERSON_CARD_WIDTH)
}

function orderRows(context: LayoutContext, anchorRow: number): Map<number, string[]> {
  const peopleByRow = peopleByRowMap(context)
  const orders = new Map<number, string[]>()
  const scheduledRows = scheduleGenerations(
    generationCandidates(context, peopleByRow),
    anchorRow,
  )
  traceEvent(context, 'generation-schedule', {
    anchorRow,
    scheduledRows,
  })
  for (const next of scheduledRows) {
    const order = optimizeRowOrder(context, next, orders, 'initial-placement')
    orders.set(next, order)
    traceEvent(context, 'generation-placed', {
      order: tracedOrder(context, order),
      row: next,
    })
  }

  const sweepRows = [...context.rows].sort((first, second) => (
    Math.abs(first - anchorRow) - Math.abs(second - anchorRow) || first - second
  ))
  for (let sweep = 0; sweep < MAX_ORDERING_SWEEPS; sweep += 1) {
    let changed = false
    for (const row of sweep % 2 === 0 ? sweepRows : [...sweepRows].reverse()) {
      const previous = orders.get(row)!
      const next = optimizeRowOrder(context, row, orders, `global-sweep-${sweep + 1}`)
      if (next.join(':') !== previous.join(':')) {
        changed = true
        traceEvent(context, 'global-sweep-reordered', {
          after: tracedOrder(context, next),
          before: tracedOrder(context, previous),
          row,
          sweep: sweep + 1,
        })
      }
      orders.set(row, next)
    }
    traceEvent(context, 'global-sweep-completed', { changed, sweep: sweep + 1 })
    if (!changed) break
  }
  return orders
}

function generationCandidates(
  context: LayoutContext,
  peopleByRow: ReadonlyMap<number, string[]>,
): GenerationCandidate[] {
  return context.rows.map((row) => {
    const ids = peopleByRow.get(row) ?? []
    return {
      minimumWidth: estimatedRowWidth(context, ids),
      personCount: ids.length,
      row,
    }
  })
}

function optimizeRowOrder(
  context: LayoutContext,
  row: number,
  fixedOrders: ReadonlyMap<number, string[]>,
  reason: string,
): string[] {
  const structure = context.rowStructures.get(row)!
  const targets = orderingTargets(context, row, fixedOrders)
  const initial = initialOrderingState(context, structure, targets)
  let beam = [initial]
  let best = initial
  let bestScore = scoreOrdering(context, row, best, fixedOrders)
  const seen = new Set([orderingKey(initial)])
  const runDetails: Record<string, unknown> = {
    final: null,
    fixedRows: [...fixedOrders].map(([fixedRow, ids]) => ({
      order: tracedOrder(context, ids),
      row: fixedRow,
    })),
    initial: {
      order: tracedOrder(context, flattenOrdering(initial)),
      score: traceScore(bestScore),
    },
    reason,
    row,
    sweeps: [],
    targets: Object.fromEntries([...targets].map(([id, target]) => [id, target])),
  }
  const sweepTrace = runDetails.sweeps as Array<Record<string, unknown>>

  for (let sweep = 0; sweep < MAX_ORDERING_SWEEPS; sweep += 1) {
    const candidates: OrderingState[] = [...beam]
    for (const state of beam) candidates.push(...orderingNeighbors(state))
    const scored = candidates
      .filter((state) => {
        const key = orderingKey(state)
        if (seen.has(key) && !beam.includes(state)) return false
        seen.add(key)
        return true
      })
      .map((state) => ({ score: scoreOrdering(context, row, state, fixedOrders), state }))
      .sort((a, b) => compareScore(a.score, b.score))
    const ranked = scored.slice(0, BEAM_WIDTH)
    if (ranked.length === 0) break
    beam = ranked.map(({ state }) => state)
    const improved = compareScore(ranked[0]!.score, bestScore) < 0
    sweepTrace.push({
      acceptedAsBest: improved,
      attempted: scored.map(({ score, state }) => ({
        order: tracedOrder(context, flattenOrdering(state)),
        score: traceScore(score),
      })),
      generatedCandidateCount: candidates.length,
      retainedCandidateCount: ranked.length,
      sweep: sweep + 1,
    })
    if (improved) {
      best = ranked[0]!.state
      bestScore = ranked[0]!.score
    }
  }
  runDetails.final = {
    order: tracedOrder(context, flattenOrdering(best)),
    score: traceScore(bestScore),
  }
  traceEvent(context, 'row-order-search', runDetails)
  return flattenOrdering(best)
}

function initialOrderingState(
  context: LayoutContext,
  structure: RowStructure,
  targets: ReadonlyMap<string, number>,
): OrderingState {
  const personOrderByInner = new Map<string, string[]>()
  const innerTarget = new Map<string, number>()
  for (const [innerId, people] of structure.peopleByInner) {
    const ordered = [...people].sort((first, second) => (
      roleBias(context, first, people) - roleBias(context, second, people)
      || (targets.get(first) ?? 0) - (targets.get(second) ?? 0)
      || Number(!context.referenceIds.has(first)) - Number(!context.referenceIds.has(second))
      || first.localeCompare(second)
    ))
    personOrderByInner.set(innerId, ordered)
    innerTarget.set(innerId, average(ordered.map((id) => targets.get(id) ?? 0)))
  }

  const innerOrderByOuter = new Map<string, string[]>()
  const outerTarget = new Map<string, number>()
  for (const [outerId, innerIds] of structure.innerIdsByOuter) {
    const ordered = orientInnerComponents(context, [...innerIds].sort((first, second) => (
      (innerTarget.get(first) ?? 0) - (innerTarget.get(second) ?? 0)
      || first.localeCompare(second)
    )), personOrderByInner, structure.peopleByInner)
    innerOrderByOuter.set(outerId, ordered)
    outerTarget.set(outerId, average(ordered.map((id) => innerTarget.get(id) ?? 0)))
  }
  const outerOrder = [...structure.outerIds].sort((first, second) => (
    (outerTarget.get(first) ?? 0) - (outerTarget.get(second) ?? 0)
    || first.localeCompare(second)
  ))
  return { innerOrderByOuter, outerOrder, personOrderByInner }
}

function orientInnerComponents(
  context: LayoutContext,
  innerIds: string[],
  personOrderByInner: ReadonlyMap<string, string[]>,
  peopleByInner: ReadonlyMap<string, string[]>,
): string[] {
  for (const centreId of innerIds) {
    const centrePeople = personOrderByInner.get(centreId) ?? []
    if (centrePeople.length < 2) continue
    const leftPerson = centrePeople[0]!
    const rightPerson = centrePeople.at(-1)!
    const leftParents = context.parentsByChild.get(leftPerson) ?? new Set<string>()
    const rightParents = context.parentsByChild.get(rightPerson) ?? new Set<string>()
    const sharesParent = (candidateId: string, parents: Set<string>) => (
      [...(context.parentsByChild.get(candidateId) ?? [])].some((id) => parents.has(id))
    )
    const left: string[] = []
    const right: string[] = []
    const remaining: string[] = []
    for (const innerId of innerIds) {
      if (innerId === centreId) continue
      const people = peopleByInner.get(innerId) ?? []
      if (people.some((id) => sharesParent(id, leftParents))) left.push(innerId)
      else if (people.some((id) => sharesParent(id, rightParents))) right.push(innerId)
      else remaining.push(innerId)
    }
    if (left.length > 0 && right.length > 0) {
      return [...left, ...remaining.slice(0, Math.floor(remaining.length / 2)), centreId,
        ...remaining.slice(Math.floor(remaining.length / 2)), ...right]
    }
  }
  return innerIds
}

function roleBias(context: LayoutContext, personId: string, component: string[]): number {
  let result = 0
  for (const partnerId of component) {
    if (partnerId === personId) continue
    const sharedChildren = [...(context.childrenByParent.get(personId) ?? [])]
      .filter((childId) => context.childrenByParent.get(partnerId)?.has(childId))
    for (const childId of sharedChildren) {
      const role = context.roleByParentAndChild.get(`${personId}:${childId}`)
      if (role === 'father') result -= 1
      if (role === 'mother') result += 1
    }
  }
  return result
}

function orderingTargets(
  context: LayoutContext,
  row: number,
  fixedOrders: ReadonlyMap<number, string[]>,
): Map<string, number> {
  const fixedIndexes = new Map<string, number>()
  for (const [fixedRow, ids] of fixedOrders) {
    if (fixedRow === row) continue
    const offset = (ids.length - 1) / 2
    ids.forEach((id, index) => fixedIndexes.set(id, index - offset))
  }
  const targets = new Map<string, number>()
  for (const id of context.ids.filter((personId) => context.rowByPerson.get(personId) === row)) {
    const neighbors = relationshipNeighbors(context, id)
      .map((neighborId) => fixedIndexes.get(neighborId))
      .filter((value): value is number => value !== undefined)
    targets.set(id, neighbors.length > 0
      ? average(neighbors)
      : (context.seedPositions.get(id)?.x ?? 0) / HORIZONTAL_STEP)
  }
  return targets
}

function orderingNeighbors(state: OrderingState): OrderingState[] {
  const result: OrderingState[] = []
  for (let index = 1; index < state.outerOrder.length; index += 1) {
    const next = cloneOrdering(state)
    swap(next.outerOrder, index - 1, index)
    result.push(next)
  }
  for (const outerId of state.outerOrder) {
    const innerOrder = state.innerOrderByOuter.get(outerId) ?? []
    for (let index = 1; index < innerOrder.length; index += 1) {
      const next = cloneOrdering(state)
      swap(next.innerOrderByOuter.get(outerId)!, index - 1, index)
      result.push(next)
    }
  }
  for (const [innerId, people] of state.personOrderByInner) {
    for (let index = 1; index < people.length; index += 1) {
      const next = cloneOrdering(state)
      swap(next.personOrderByInner.get(innerId)!, index - 1, index)
      result.push(next)
    }
  }
  return result
}

function scoreOrdering(
  context: LayoutContext,
  row: number,
  state: OrderingState,
  fixedOrders: ReadonlyMap<number, string[]>,
): Score {
  const order = flattenOrdering(state)
  const indexById = new Map(order.map((id, index) => [id, index - (order.length - 1) / 2]))
  const fixedIndexes = new Map<string, number>()
  for (const [fixedRow, fixedOrder] of fixedOrders) {
    if (fixedRow === row) continue
    fixedOrder.forEach((id, index) => fixedIndexes.set(id, index - (fixedOrder.length - 1) / 2))
  }
  const edges: Array<[number, number]> = []
  for (const link of context.parentLinks) {
    const currentId = context.rowByPerson.get(link.parentId) === row
      ? link.parentId
      : context.rowByPerson.get(link.childId) === row
        ? link.childId
        : undefined
    const fixedId = currentId === link.parentId ? link.childId : link.parentId
    const currentIndex = currentId ? indexById.get(currentId) : undefined
    const fixedIndex = fixedIndexes.get(fixedId)
    if (currentIndex !== undefined && fixedIndex !== undefined) edges.push([currentIndex, fixedIndex])
  }
  let crossings = 0
  for (let index = 0; index < edges.length; index += 1) {
    for (let other = index + 1; other < edges.length; other += 1) {
      const first = edges[index]!
      const second = edges[other]!
      if ((first[0] - second[0]) * (first[1] - second[1]) < 0) crossings += 1
    }
  }
  const deviations = order.map((id) => {
    const neighbors = relationshipNeighbors(context, id)
      .map((neighborId) => fixedIndexes.get(neighborId))
      .filter((value): value is number => value !== undefined)
    return neighbors.length === 0
      ? 0
      : Math.abs(indexById.get(id)! - average(neighbors))
  })
  const partnerSpan = [...context.partnersByPerson]
    .flatMap(([id, partners]) => [...partners]
      .filter((partnerId) => id < partnerId && indexById.has(id) && indexById.has(partnerId))
      .map((partnerId) => Math.max(0, Math.abs(
        indexById.get(id)! - indexById.get(partnerId)!,
      ) - 1)))
    .reduce((sum, value) => sum + value, 0)
  const roleInversions = context.families.filter((family) => {
    if (family.parentIds.length !== 2) return false
    const [first, second] = family.parentIds
    if (!indexById.has(first!) || !indexById.has(second!)) return false
    const hasOtherSpouse = (personId: string, partnerId: string) => (
      [...(context.marriedPartnersByPerson.get(personId) ?? [])]
        .some((candidateId) => candidateId !== partnerId)
    )
    if (hasOtherSpouse(first!, second!) || hasOtherSpouse(second!, first!)) return false
    const childId = family.childIds[0]!
    const firstRole = context.roleByParentAndChild.get(`${first}:${childId}`)
    const fatherId = firstRole === 'father' ? first : second
    const motherId = firstRole === 'mother' ? first : second
    return indexById.get(fatherId!)! > indexById.get(motherId!)!
  }).length
  const branchOrientationViolations = context.families.reduce((violations, family) => {
    if (family.parentIds.length !== 2) return violations
    const [first, second] = [...family.parentIds].sort((left, right) => (
      (indexById.get(left) ?? 0) - (indexById.get(right) ?? 0)
    ))
    if (!indexById.has(first!) || !indexById.has(second!)) return violations
    const countWrongSide = (personId: string, direction: -1 | 1) => {
      const ownParents = context.parentsByChild.get(personId) ?? new Set<string>()
      return context.ids.filter((candidateId) => {
        if (candidateId === personId || !indexById.has(candidateId)) return false
        const candidateParents = context.parentsByChild.get(candidateId) ?? new Set<string>()
        if (![...ownParents].some((id) => candidateParents.has(id))) return false
        return direction * (indexById.get(candidateId)! - indexById.get(personId)!) < 0
      }).length
    }
    if (
      (context.parentsByChild.get(first!)?.size ?? 0) === 0
      || (context.parentsByChild.get(second!)?.size ?? 0) === 0
    ) return violations
    return violations + countWrongSide(first!, -1) + countWrongSide(second!, 1)
  }, 0)
  const excessChildSpace = context.families.reduce((excess, family) => {
    const children = family.childIds
      .filter((id) => indexById.has(id))
      .sort((first, second) => indexById.get(first)! - indexById.get(second)!)
    if (children.length < 2) return excess
    const required = children.slice(1).map((rightId, index) => (
      Math.max(
        minimumDistance(context, children[index]!, rightId),
        (
          estimatedBranchWidth(context, children[index]!)
          + estimatedBranchWidth(context, rightId)
        ) / 2 + SIBLING_CARD_GAP,
      )
    ))
    const uniform = Math.max(...required)
    return excess + required.reduce((sum, distance) => sum + uniform - distance, 0)
  }, 0)
  return [
    roleInversions + branchOrientationViolations,
    Math.max(0, ...deviations),
    crossings,
    deviations.reduce((sum, value) => sum + value, 0),
    excessChildSpace,
    partnerSpan,
    order.join(':'),
  ]
}

function estimatedBranchWidth(context: LayoutContext, rootId: string): number {
  const rootRow = context.rowByPerson.get(rootId)!
  const members = new Set<string>()
  const pending = [rootId]
  while (pending.length > 0) {
    const id = pending.shift()!
    if (members.has(id) || context.rowByPerson.get(id)! < rootRow) continue
    members.add(id)
    for (const partnerId of context.partnersByPerson.get(id) ?? []) pending.push(partnerId)
    for (const childId of context.childrenByParent.get(id) ?? []) pending.push(childId)
  }
  const counts = new Map<number, number>()
  for (const id of members) {
    const row = context.rowByPerson.get(id)!
    counts.set(row, (counts.get(row) ?? 0) + 1)
  }
  return Math.max(...[...counts.values()].map((count) => (
    PERSON_CARD_WIDTH + Math.max(0, count - 1) * (PERSON_CARD_WIDTH + SIBLING_CARD_GAP)
  )))
}

function solveCoordinates(
  context: LayoutContext,
  orders: ReadonlyMap<number, string[]>,
  childGapPolicies: ChildGapPolicy[],
  pass: 'preliminary' | 'final',
): Map<string, number> {
  const solver = new Solver()
  solver.maxIterations = 100_000
  const variables = new Map(context.ids.map((id) => [id, new Variable(id)]))
  const maxFamilyDeviation = new Variable('max-family-deviation')
  solver.createConstraint(maxFamilyDeviation, Operator.Ge, 0)
  solver.createConstraint(maxFamilyDeviation, Operator.Eq, 0, Strength.strong)
  const ancestorFan = isPureAncestorFan(context)

  for (const row of context.rows) {
    const order = orders.get(row)!
    const idealWidth = order.slice(1).reduce((width, id, index) => (
      width + minimumDistance(context, order[index]!, id)
    ), 0)
    let desired = -idealWidth / 2
    order.forEach((id, index) => {
      const variable = variables.get(id)!
      if (index > 0) {
        const previousId = order[index - 1]!
        solver.createConstraint(
          variable.minus(variables.get(previousId)!),
          Operator.Ge,
          minimumDistance(context, previousId, id),
        )
      }
      solver.createConstraint(variable, Operator.Eq, desired, Strength.weak)
      if (index + 1 < order.length) desired += minimumDistance(context, id, order[index + 1]!)
    })
  }

  for (const [personId, partners] of [...context.partnersByPerson].sort(([a], [b]) => (
    a.localeCompare(b)
  ))) {
    for (const partnerId of [...partners].sort((a, b) => a.localeCompare(b))) {
      if (personId >= partnerId) continue
      const rowOrder = orders.get(context.rowByPerson.get(personId)!)!
      const direction = Math.sign(rowOrder.indexOf(partnerId) - rowOrder.indexOf(personId)) || 1
      solver.createConstraint(
        variables.get(partnerId)!.minus(variables.get(personId)!),
        Operator.Eq,
        direction * HORIZONTAL_STEP,
        Strength.medium,
      )
    }
  }

  for (const family of context.families) {
    if (family.childIds.length === 0) continue
    const parentExpression = averageExpression(family.parentIds.map((id) => variables.get(id)!))
    const orderedChildren = [...family.childIds].sort((first, second) => (
      orders.get(context.rowByPerson.get(first)!)!.indexOf(first)
      - orders.get(context.rowByPerson.get(second)!)!.indexOf(second)
      || first.localeCompare(second)
    ))
    const extremeChildren = orderedChildren.length === 1
      ? [orderedChildren[0]!]
      : [orderedChildren[0]!, orderedChildren.at(-1)!]
    const childExpression = averageExpression(extremeChildren.map((id) => variables.get(id)!))
    const deviation = parentExpression.minus(childExpression)
    solver.createConstraint(deviation, Operator.Le, maxFamilyDeviation)
    solver.createConstraint(deviation.multiply(-1), Operator.Le, maxFamilyDeviation)
    solver.createConstraint(
      deviation,
      Operator.Eq,
      0,
      ancestorFan ? Strength.medium : Strength.strong,
    )
  }

  for (const policy of childGapPolicies) {
    for (let index = 1; index < policy.childIds.length; index += 1) {
      solver.createConstraint(
        variables.get(policy.childIds[index]!)!.minus(variables.get(policy.childIds[index - 1]!)!),
        Operator.Eq,
        policy.distances[index - 1]!,
        policy.variable ? Strength.strong : Strength.medium,
      )
    }
  }

  solver.updateVariables()
  const coordinates = new Map(context.ids.map((id) => [
    id,
    normalized(variables.get(id)!.value()),
  ]))
  traceCoordinateSolution(context, orders, childGapPolicies, coordinates, pass)
  return coordinates
}

function traceCoordinateSolution(
  context: LayoutContext,
  orders: ReadonlyMap<number, string[]>,
  childGapPolicies: ChildGapPolicy[],
  coordinates: ReadonlyMap<string, number>,
  pass: 'preliminary' | 'final',
): void {
  if (!context.trace) return
  const policyByPair = new Map<string, number>()
  for (const policy of childGapPolicies) {
    policy.childIds.slice(1).forEach((rightId, index) => {
      policyByPair.set(`${policy.childIds[index]}:${rightId}`, policy.distances[index]!)
    })
  }
  const rows = [...orders].map(([row, order]) => ({
    gaps: order.slice(1).map((rightId, index) => {
      const leftId = order[index]!
      const minimum = minimumDistance(context, leftId, rightId)
      const actual = normalized(coordinates.get(rightId)! - coordinates.get(leftId)!)
      const contourRequirement = policyByPair.get(`${leftId}:${rightId}`)
        ?? policyByPair.get(`${rightId}:${leftId}`)
      return {
        actual,
        addedSpace: normalized(actual - minimum),
        contourRequirement: contourRequirement ?? null,
        inferredCause: actual <= minimum + COORDINATE_TOLERANCE
          ? 'minimum-card-gap'
          : contourRequirement !== undefined && actual >= contourRequirement - COORDINATE_TOLERANCE
            ? 'descendant-contours'
            : 'family-axis-or-partner-constraint',
        left: tracedOrder(context, [leftId])[0],
        minimum,
        right: tracedOrder(context, [rightId])[0],
      }
    }),
    people: order.map((id) => ({
      ...tracedOrder(context, [id])[0],
      x: coordinates.get(id),
    })),
    row,
  }))
  const families = context.families
    .filter((family) => family.childIds.length > 0)
    .map((family) => {
      const orderedChildren = [...family.childIds].sort((first, second) => (
        coordinates.get(first)! - coordinates.get(second)! || first.localeCompare(second)
      ))
      const extremeChildren = orderedChildren.length === 1
        ? [orderedChildren[0]!]
        : [orderedChildren[0]!, orderedChildren.at(-1)!]
      const parentCenter = average(family.parentIds.map((id) => coordinates.get(id)!))
      const childCenter = average(extremeChildren.map((id) => coordinates.get(id)!))
      return {
        childCenter,
        children: tracedOrder(context, orderedChildren),
        deviation: normalized(parentCenter - childCenter),
        extremeChildren: tracedOrder(context, extremeChildren),
        parentCenter,
        parents: tracedOrder(context, family.parentIds),
      }
    })
  traceEvent(context, 'coordinate-solution', {
    childGapPolicies: childGapPolicies.map((policy) => ({
      children: tracedOrder(context, policy.childIds),
      distances: policy.distances,
      variable: policy.variable,
    })),
    families,
    pass,
    rows,
  })
}

function isPureAncestorFan(context: LayoutContext): boolean {
  return context.referenceIds.size === 1
    && [...context.childrenByParent.values()].every((children) => children.size <= 1)
    && context.families.every((family) => family.childIds.length <= 1)
}

/**
 * Child spacing is deliberately two-stage. We first retain one common step for
 * the best legal branch order. Only a reducible interval above 100 px unlocks
 * pair-specific distances; the order is then frozen and only those gaps shrink.
 */
function buildChildGapPolicies(
  context: LayoutContext,
  orders: ReadonlyMap<number, string[]>,
  coordinates: ReadonlyMap<string, number>,
): ChildGapPolicy[] {
  const policies: ChildGapPolicy[] = []
  const descendantBlocks = new Map<string, Set<string>>()
  for (const family of context.families) {
    if (family.childIds.length < 2) continue
    const childIds = [...family.childIds].sort((first, second) => (
      orders.get(context.rowByPerson.get(first)!)!.indexOf(first)
      - orders.get(context.rowByPerson.get(second)!)!.indexOf(second)
      || first.localeCompare(second)
    ))
    const blocks = childIds.map((id) => descendantFamilyBlock(context, id, descendantBlocks))
    if (blocks.some((block, index) => blocks.slice(index + 1).some((other) => (
      [...block].some((id) => other.has(id))
    )))) {
      traceEvent(context, 'child-gap-policy-skipped', {
        children: tracedOrder(context, childIds),
        reason: 'descendant-blocks-overlap',
      })
      continue
    }

    const required = childIds.slice(1).map((rightId, index) => requiredBranchDistance(
      context,
      childIds[index]!,
      rightId,
      blocks[index]!,
      blocks[index + 1]!,
      coordinates,
    ))
    const decision = chooseChildGapDistances(required)
    traceEvent(context, 'child-gap-policy', {
      blocks: blocks.map((block, index) => ({
        members: tracedOrder(context, [...block].sort((a, b) => a.localeCompare(b))),
        root: tracedOrder(context, [childIds[index]!])[0],
      })),
      children: tracedOrder(context, childIds),
      decision,
      required,
    })
    policies.push({
      childIds,
      distances: decision.distances,
      variable: decision.variable,
    })
  }
  return policies
}

function descendantFamilyBlock(
  context: LayoutContext,
  rootId: string,
  cache: Map<string, Set<string>>,
): Set<string> {
  const cached = cache.get(rootId)
  if (cached) return cached
  const result = new Set<string>()
  const pending = [rootId]
  const rootRow = context.rowByPerson.get(rootId)!
  while (pending.length > 0) {
    const id = pending.shift()!
    if (result.has(id) || context.rowByPerson.get(id)! < rootRow) continue
    result.add(id)
    for (const partnerId of context.partnersByPerson.get(id) ?? []) pending.push(partnerId)
    for (const childId of context.childrenByParent.get(id) ?? []) pending.push(childId)
  }
  cache.set(rootId, result)
  return result
}

function requiredBranchDistance(
  context: LayoutContext,
  leftRootId: string,
  rightRootId: string,
  leftBlock: Set<string>,
  rightBlock: Set<string>,
  coordinates: ReadonlyMap<string, number>,
): number {
  const leftRootX = coordinates.get(leftRootId)!
  const rightRootX = coordinates.get(rightRootId)!
  const rows = new Set([...leftBlock].map((id) => context.rowByPerson.get(id)!))
  let required = minimumDistance(context, leftRootId, rightRootId)
  for (const row of rows) {
    const leftIds = [...leftBlock].filter((id) => context.rowByPerson.get(id) === row)
    const rightIds = [...rightBlock].filter((id) => context.rowByPerson.get(id) === row)
    if (leftIds.length === 0 || rightIds.length === 0) continue
    const leftContour = Math.max(...leftIds.map((id) => (
      coordinates.get(id)! - leftRootX + PERSON_CARD_WIDTH / 2
    )))
    const rightContour = Math.min(...rightIds.map((id) => (
      coordinates.get(id)! - rightRootX - PERSON_CARD_WIDTH / 2
    )))
    const cardGap = minimumCardGap(context, leftRootId, rightRootId)
    required = Math.max(required, leftContour + cardGap - rightContour)
  }
  return normalized(required)
}

function averageExpression(variables: Variable[]): Expression {
  return new Expression(...variables.map((variable) => [1 / variables.length, variable]))
}

function minimumDistance(context: LayoutContext, first: string, second: string): number {
  return PERSON_CARD_WIDTH + minimumCardGap(context, first, second)
}

function minimumCardGap(context: LayoutContext, first: string, second: string): number {
  if (context.partnersByPerson.get(first)?.has(second)) return SPOUSE_CARD_GAP
  if (areHalfSiblings(context, first, second)) return HALF_SIBLING_CARD_GAP
  if (areSiblings(context, first, second)) return SIBLING_CARD_GAP
  return OTHER_CARD_GAP
}

function areHalfSiblings(context: LayoutContext, first: string, second: string): boolean {
  const firstParents = context.parentsByChild.get(first) ?? new Set<string>()
  const secondParents = context.parentsByChild.get(second) ?? new Set<string>()
  if (firstParents.size === 0 || secondParents.size === 0) return false
  const shared = [...firstParents].filter((id) => secondParents.has(id)).length
  return shared > 0 && shared < Math.max(firstParents.size, secondParents.size)
}

function areSiblings(context: LayoutContext, first: string, second: string): boolean {
  const firstParents = context.parentsByChild.get(first) ?? new Set<string>()
  const secondParents = context.parentsByChild.get(second) ?? new Set<string>()
  return [...firstParents].some((id) => secondParents.has(id))
}

function relationshipNeighbors(context: LayoutContext, id: string): string[] {
  return [
    ...(context.parentsByChild.get(id) ?? []),
    ...(context.childrenByParent.get(id) ?? []),
    ...(context.partnersByPerson.get(id) ?? []),
  ].sort((a, b) => a.localeCompare(b))
}

function peopleByRowMap(context: LayoutContext): Map<number, string[]> {
  const result = new Map<number, string[]>()
  for (const id of context.ids) {
    const row = context.rowByPerson.get(id)!
    const values = result.get(row) ?? []
    values.push(id)
    result.set(row, values)
  }
  return result
}

function cloneOrdering(state: OrderingState): OrderingState {
  return {
    outerOrder: [...state.outerOrder],
    innerOrderByOuter: new Map([...state.innerOrderByOuter].map(([id, values]) => [id, [...values]])),
    personOrderByInner: new Map([...state.personOrderByInner].map(([id, values]) => [id, [...values]])),
  }
}

function flattenOrdering(state: OrderingState): string[] {
  return state.outerOrder.flatMap((outerId) => (
    state.innerOrderByOuter.get(outerId)!.flatMap((innerId) => (
      state.personOrderByInner.get(innerId)!
    ))
  ))
}

function orderingKey(state: OrderingState): string {
  return flattenOrdering(state).join(':')
}

function compareScore(first: Score, second: Score): number {
  for (let index = 0; index < first.length - 1; index += 1) {
    const difference = (first[index] as number) - (second[index] as number)
    if (Math.abs(difference) > COORDINATE_TOLERANCE) return difference
  }
  return first[6].localeCompare(second[6])
}

function normalized(value: number): number {
  const rounded = Math.round(value * 1_000_000) / 1_000_000
  return Object.is(rounded, -0) ? 0 : rounded
}

function average(values: number[]): number {
  return values.length === 0 ? 0 : values.reduce((sum, value) => sum + value, 0) / values.length
}

function addToSet(map: Map<string, Set<string>>, key: string, value: string): void {
  const values = map.get(key) ?? new Set<string>()
  values.add(value)
  map.set(key, values)
}

function swap(values: string[], first: number, second: number): void {
  const value = values[first]!
  values[first] = values[second]!
  values[second] = value
}

function orderedPair(first: string, second: string): [string, string] {
  return first.localeCompare(second) <= 0 ? [first, second] : [second, first]
}

function pairComparator(first: [string, string], second: [string, string]): number {
  return first[0].localeCompare(second[0]) || first[1].localeCompare(second[1])
}

function parentLinkComparator(first: ParentLink, second: ParentLink): number {
  return first.childId.localeCompare(second.childId)
    || first.role.localeCompare(second.role)
    || first.parentId.localeCompare(second.parentId)
    || first.id.localeCompare(second.id)
}
