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
    spatialIndex.add({
      height: PERSON_CARD_HEIGHT,
      width: PERSON_CARD_WIDTH,
      x: position.x,
      y: position.y,
    })
  }
  const routes = plan.nodes
    .filter((node) => !visibleIds.has(node.personId))
    .sort((left, right) => (
      left.generation - right.generation
      || left.kind.localeCompare(right.kind)
      || left.personId.localeCompare(right.personId)
    ))
  const lanes = new Map<number, number>()

  for (const node of routes) {
    const anchor = nearestAnchor(node.personId, routeNodes, positions)
    const lane = lanes.get(node.generation) ?? 0
    lanes.set(node.generation, lane + 1)
    const desired = {
      x: anchor.position.x + (lane - anchor.lane) * HORIZONTAL_STEP,
      y: anchor.position.y + (node.generation - anchor.generation) * VERTICAL_STEP,
    }
    const position = firstFreePosition(desired, spatialIndex)
    positions.set(node.personId, position)
    spatialIndex.add({
      height: PERSON_CARD_HEIGHT,
      width: PERSON_CARD_WIDTH,
      x: position.x,
      y: position.y,
    })
  }

  return {
    overlayPeople: routes.flatMap((node) => {
      const person = people.get(node.personId)
      return person ? [person] : []
    }),
    positions,
    routePersonIds: new Set(plan.nodes
      .filter((node) => node.kind === 'route')
      .map((node) => node.personId)),
  }
}

function nearestAnchor(
  personId: string,
  nodes: ReadonlyMap<string, SearchRoutePlan['nodes'][number]>,
  positions: ReadonlyMap<string, XYPosition>,
) {
  const node = nodes.get(personId)
  const candidates = [...positions]
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
  if (first) return { ...first, lane: 0 }

  return {
    generation: 0,
    id: personId,
    lane: 0,
    position: { x: 0, y: 0 },
  }
}

function firstFreePosition(
  desired: XYPosition,
  spatialIndex: SpatialIndex,
) {
  const shifts = [0]
  for (let step = 1; step <= 100; step += 1) {
    shifts.push(step * HORIZONTAL_STEP, -step * HORIZONTAL_STEP)
  }
  for (const shift of shifts) {
    const rectangle = {
      height: PERSON_CARD_HEIGHT,
      width: PERSON_CARD_WIDTH,
      x: desired.x + shift,
      y: desired.y,
    }
    if (!spatialIndex.intersects(rectangle)) {
      return { x: rectangle.x, y: rectangle.y }
    }
  }

  return desired
}
