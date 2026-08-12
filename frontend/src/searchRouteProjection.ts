import type { XYPosition } from '@xyflow/react'
import type {
  Person,
  SearchRoutePlan,
} from './api/generated'
import {
  PERSON_CARD_HEIGHT,
  PERSON_CARD_WIDTH,
} from './treeGraph'

const HORIZONTAL_STEP = 320
const VERTICAL_STEP = 240
const GAP = 24
const CELL = 320

export type SearchRouteProjection = {
  overlayPeople: Person[]
  positions: Map<string, XYPosition>
  routePersonIds: Set<string>
}

type Rectangle = {
  height: number
  width: number
  x: number
  y: number
}

class SpatialIndex {
  private readonly cells = new Map<string, Rectangle[]>()

  add(rectangle: Rectangle) {
    for (const key of this.keys(rectangle)) {
      const cell = this.cells.get(key) ?? []
      cell.push(rectangle)
      this.cells.set(key, cell)
    }
  }

  intersects(rectangle: Rectangle) {
    const checked = new Set<Rectangle>()
    for (const key of this.keys(rectangle)) {
      for (const candidate of this.cells.get(key) ?? []) {
        if (checked.has(candidate)) continue
        checked.add(candidate)
        if (
          rectangle.x < candidate.x + candidate.width + GAP
          && rectangle.x + rectangle.width + GAP > candidate.x
          && rectangle.y < candidate.y + candidate.height + GAP
          && rectangle.y + rectangle.height + GAP > candidate.y
        ) {
          return true
        }
      }
    }

    return false
  }

  private keys(rectangle: Rectangle) {
    const keys: string[] = []
    const startX = Math.floor((rectangle.x - GAP) / CELL)
    const endX = Math.floor((rectangle.x + rectangle.width + GAP) / CELL)
    const startY = Math.floor((rectangle.y - GAP) / CELL)
    const endY = Math.floor((rectangle.y + rectangle.height + GAP) / CELL)
    for (let x = startX; x <= endX; x += 1) {
      for (let y = startY; y <= endY; y += 1) keys.push(`${x}:${y}`)
    }

    return keys
  }
}

export function projectSearchRoute(
  plan: SearchRoutePlan,
  visiblePeople: readonly Person[],
  visiblePositions: ReadonlyMap<string, XYPosition>,
): SearchRouteProjection {
  const visibleIds = new Set(visiblePeople.map((person) => person.id))
  const people = new Map(plan.people.map((person) => [person.id, person]))
  const routeNodes = new Map(plan.nodes.map((node) => [node.personId, node]))
  const positions = new Map(visiblePositions)
  const spatialIndex = new SpatialIndex()
  for (const position of visiblePositions.values()) {
    spatialIndex.add(rectangleAt(position))
  }

  const parentIdsByChild = new Map<string, string[]>()
  const relatedPeople = new Map<string, Set<string>>()
  for (const link of plan.parentLinks) {
    addRelation(relatedPeople, link.parentId, link.childId)
    const parents = parentIdsByChild.get(link.childId) ?? []
    if (!parents.includes(link.parentId)) parents.push(link.parentId)
    parentIdsByChild.set(link.childId, parents.sort((left, right) => left.localeCompare(right)))
  }
  for (const marriage of plan.marriages) {
    addRelation(relatedPeople, marriage.malePersonId, marriage.femalePersonId)
  }
  for (const family of plan.familyBlocks) {
    addRelation(relatedPeople, family.personIds[0], family.personIds[1])
  }

  const pending = new Map(plan.nodes
    .filter((node) => !visibleIds.has(node.personId))
    .sort(nodeComparator)
    .map((node) => [node.personId, node]))

  while (pending.size > 0) {
    let placed = false
    for (const node of [...pending.values()].sort(nodeComparator)) {
      if (!pending.has(node.personId)) continue
      const parents = parentIdsByChild.get(node.personId) ?? []
      const parentPositions = parents
        .map((parentId) => positions.get(parentId))
        .filter((position): position is XYPosition => position !== undefined)
      if (parents.length === 2 && parentPositions.length === 2) {
        const position = firstFreeCenteredChildPosition(parentPositions, spatialIndex)
        place(node.personId, position, positions, spatialIndex)
        pending.delete(node.personId)
        placed = true
        continue
      }

      const sibling = pairSiblingWithPositionedChild(node.personId, parentIdsByChild, pending, positions)
      if (sibling) {
        const pair = firstFreeParentPairPosition(sibling.childPosition, spatialIndex)
        place(sibling.firstParentId, pair.first, positions, spatialIndex)
        place(sibling.secondParentId, pair.second, positions, spatialIndex)
        pending.delete(sibling.firstParentId)
        pending.delete(sibling.secondParentId)
        placed = true
        continue
      }

      const anchor = nearestAnchor(
        node.personId,
        routeNodes,
        positions,
        relatedPeople,
      )
      const position = firstFreePosition({
        x: anchor.position.x,
        y: anchor.position.y + (node.generation - anchor.generation) * VERTICAL_STEP,
      }, spatialIndex)
      place(node.personId, position, positions, spatialIndex)
      pending.delete(node.personId)
      placed = true
    }

    if (!placed) break
  }

  const routePersonIds = new Set(plan.nodes.map((node) => node.personId))

  return {
    overlayPeople: plan.nodes
      .filter((node) => !visibleIds.has(node.personId))
      .sort(nodeComparator)
      .flatMap((node) => {
        const person = people.get(node.personId)
        return person ? [person] : []
      }),
    positions,
    routePersonIds,
  }
}

function addRelation(relatedPeople: Map<string, Set<string>>, first: string, second: string) {
  const relations: Array<readonly [string, string]> = [[first, second], [second, first]]
  for (const [personId, relativeId] of relations) {
    const relatives = relatedPeople.get(personId) ?? new Set<string>()
    relatives.add(relativeId)
    relatedPeople.set(personId, relatives)
  }
}

function nodeComparator(
  left: SearchRoutePlan['nodes'][number],
  right: SearchRoutePlan['nodes'][number],
) {
  return left.generation - right.generation
    || left.kind.localeCompare(right.kind)
    || left.personId.localeCompare(right.personId)
}

function pairSiblingWithPositionedChild(
  personId: string,
  parentIdsByChild: ReadonlyMap<string, string[]>,
  pending: ReadonlyMap<string, SearchRoutePlan['nodes'][number]>,
  positions: ReadonlyMap<string, XYPosition>,
) {
  for (const [childId, parents] of parentIdsByChild) {
    if (parents.length !== 2 || !parents.includes(personId)) continue
    const firstParentId = parents[0]
    const secondParentId = parents[1]
    const childPosition = positions.get(childId)
    if (
      !childPosition
      || !firstParentId
      || !secondParentId
      || !pending.has(firstParentId)
      || !pending.has(secondParentId)
    ) {
      continue
    }

    return { childPosition, firstParentId, secondParentId }
  }

  return null
}

function nearestAnchor(
  personId: string,
  nodes: ReadonlyMap<string, SearchRoutePlan['nodes'][number]>,
  positions: ReadonlyMap<string, XYPosition>,
  relatedPeople: ReadonlyMap<string, ReadonlySet<string>>,
) {
  const node = nodes.get(personId)
  const related = relatedPeople.get(personId) ?? new Set<string>()
  const candidates = [...positions]
    .filter(([id]) => related.size === 0 || related.has(id))
    .map(([id, position]) => ({
      generation: nodes.get(id)?.generation ?? 0,
      id,
      position,
    }))
    .sort((left, right) => (
      Math.abs(left.generation - (node?.generation ?? 0))
      - Math.abs(right.generation - (node?.generation ?? 0))
      || left.id.localeCompare(right.id)
    ))
  const first = candidates[0]
  if (first) return first

  return {
    generation: 0,
    id: personId,
    position: { x: 0, y: 0 },
  }
}

function firstFreeCenteredChildPosition(
  parents: readonly XYPosition[],
  spatialIndex: SpatialIndex,
) {
  const center = parents.reduce(
    (sum, position) => sum + position.x + PERSON_CARD_WIDTH / 2,
    0,
  ) / parents.length
  const top = Math.max(...parents.map((position) => position.y)) + VERTICAL_STEP
  for (let step = 0; step <= 100; step += 1) {
    const position = { x: center - PERSON_CARD_WIDTH / 2, y: top + step * VERTICAL_STEP }
    if (!spatialIndex.intersects(rectangleAt(position))) return position
  }

  return { x: center - PERSON_CARD_WIDTH / 2, y: top }
}

function firstFreeParentPairPosition(
  childPosition: XYPosition,
  spatialIndex: SpatialIndex,
) {
  const childCenter = childPosition.x + PERSON_CARD_WIDTH / 2
  for (let step = 1; step <= 100; step += 1) {
    const y = childPosition.y - step * VERTICAL_STEP
    const first = { x: childCenter - PERSON_CARD_WIDTH / 2 - HORIZONTAL_STEP / 2, y }
    const second = { x: childCenter - PERSON_CARD_WIDTH / 2 + HORIZONTAL_STEP / 2, y }
    if (
      !spatialIndex.intersects(rectangleAt(first))
      && !spatialIndex.intersects(rectangleAt(second))
    ) {
      return { first, second }
    }
  }

  return {
    first: { x: childCenter - PERSON_CARD_WIDTH / 2 - HORIZONTAL_STEP / 2, y: childPosition.y - VERTICAL_STEP },
    second: { x: childCenter - PERSON_CARD_WIDTH / 2 + HORIZONTAL_STEP / 2, y: childPosition.y - VERTICAL_STEP },
  }
}

function firstFreePosition(desired: XYPosition, spatialIndex: SpatialIndex) {
  const shifts = [0]
  for (let step = 1; step <= 100; step += 1) {
    shifts.push(step * HORIZONTAL_STEP, -step * HORIZONTAL_STEP)
  }
  for (const shift of shifts) {
    const position = { x: desired.x + shift, y: desired.y }
    if (!spatialIndex.intersects(rectangleAt(position))) return position
  }

  return desired
}

function place(
  personId: string,
  position: XYPosition,
  positions: Map<string, XYPosition>,
  spatialIndex: SpatialIndex,
) {
  positions.set(personId, position)
  spatialIndex.add(rectangleAt(position))
}

function rectangleAt(position: XYPosition): Rectangle {
  return {
    height: PERSON_CARD_HEIGHT,
    width: PERSON_CARD_WIDTH,
    x: position.x,
    y: position.y,
  }
}
