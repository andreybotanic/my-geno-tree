import type { XYPosition } from '@xyflow/react'
import type {
  ParentLink,
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
        ) return true
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
  const nodes = new Map(plan.nodes.map((node) => [node.personId, node]))
  const positions = new Map(visiblePositions)
  const index = new SpatialIndex()
  for (const position of visiblePositions.values()) index.add(rectangleAt(position))

  const parentLinksByChild = new Map<string, ParentLink[]>()
  for (const link of plan.parentLinks) {
    const links = parentLinksByChild.get(link.childId) ?? []
    links.push(link)
    parentLinksByChild.set(link.childId, links)
  }
  for (const links of parentLinksByChild.values()) {
    links.sort((left, right) => left.parentId.localeCompare(right.parentId))
  }

  const marriagesByPerson = new Map<string, string[]>()
  for (const marriage of plan.marriages) {
    addMarriageNeighbor(marriagesByPerson, marriage.malePersonId, marriage.femalePersonId)
    addMarriageNeighbor(marriagesByPerson, marriage.femalePersonId, marriage.malePersonId)
  }
  for (const family of plan.familyBlocks) {
    addMarriageNeighbor(marriagesByPerson, family.personIds[0], family.personIds[1])
    addMarriageNeighbor(marriagesByPerson, family.personIds[1], family.personIds[0])
  }

  const pending = new Set(plan.nodes
    .filter((node) => !visibleIds.has(node.personId))
    .map((node) => node.personId))

  while (pending.size > 0) {
    if (placePendingParentPairAbovePlacedChild(pending, parentLinksByChild, positions, index)) continue
    if (placePendingChildBelowPlacedParentPair(pending, parentLinksByChild, positions, index)) continue
    if (placePendingParentOppositePlacedParent(pending, parentLinksByChild, positions, index)) continue
    if (placePendingSingleParent(pending, plan.parentLinks, positions, index)) continue
    if (placePendingSingleChild(pending, plan.parentLinks, positions, index)) continue
    if (placePendingSpouse(pending, marriagesByPerson, positions, index)) continue

    const personId = [...pending].sort((left, right) => nodeComparator(nodes, left, right))[0]
    if (!personId) break
    const position = fallbackPosition(personId, nodes, positions, index)
    place(personId, position, positions, index)
    pending.delete(personId)
  }

  return {
    overlayPeople: plan.nodes
      .filter((node) => !visibleIds.has(node.personId))
      .sort((left, right) => nodeComparator(nodes, left.personId, right.personId))
      .flatMap((node) => {
        const person = people.get(node.personId)
        return person ? [person] : []
      }),
    positions,
    routePersonIds: new Set(plan.nodes.map((node) => node.personId)),
  }
}

function placePendingParentPairAbovePlacedChild(
  pending: Set<string>,
  byChild: ReadonlyMap<string, ParentLink[]>,
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  for (const [childId, links] of sortedFamilies(byChild)) {
    if (links.length !== 2 || !positions.has(childId) || !links.every((link) => pending.has(link.parentId))) continue
    const child = positions.get(childId)!
    const pair = firstFreeParentPair(child, index)
    place(links[0]!.parentId, pair.first, positions, index)
    place(links[1]!.parentId, pair.second, positions, index)
    pending.delete(links[0]!.parentId)
    pending.delete(links[1]!.parentId)
    return true
  }

  return false
}

function placePendingChildBelowPlacedParentPair(
  pending: Set<string>,
  byChild: ReadonlyMap<string, ParentLink[]>,
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  for (const [childId, links] of sortedFamilies(byChild)) {
    if (links.length !== 2 || !pending.has(childId) || !links.every((link) => positions.has(link.parentId))) continue
    place(childId, firstFreeCenteredChild(links.map((link) => positions.get(link.parentId)!), index), positions, index)
    pending.delete(childId)
    return true
  }

  return false
}

function placePendingParentOppositePlacedParent(
  pending: Set<string>,
  byChild: ReadonlyMap<string, ParentLink[]>,
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  for (const [childId, links] of sortedFamilies(byChild)) {
    if (links.length !== 2 || !positions.has(childId)) continue
    const missing = links.find((link) => pending.has(link.parentId))
    const placedParent = links.find((link) => positions.has(link.parentId))
    if (!missing || !placedParent) continue
    place(missing.parentId, firstFreeMirroredParent(positions.get(childId)!, positions.get(placedParent.parentId)!, index), positions, index)
    pending.delete(missing.parentId)
    return true
  }

  return false
}

function placePendingSingleParent(
  pending: Set<string>,
  links: readonly ParentLink[],
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  for (const link of [...links].sort(parentLinkComparator)) {
    if (!pending.has(link.parentId) || !positions.has(link.childId)) continue
    place(link.parentId, firstFreeParent(positions.get(link.childId)!, index), positions, index)
    pending.delete(link.parentId)
    return true
  }

  return false
}

function placePendingSingleChild(
  pending: Set<string>,
  links: readonly ParentLink[],
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  for (const link of [...links].sort(parentLinkComparator)) {
    if (!pending.has(link.childId) || !positions.has(link.parentId)) continue
    place(link.childId, firstFreeChild(positions.get(link.parentId)!, index), positions, index)
    pending.delete(link.childId)
    return true
  }

  return false
}

function placePendingSpouse(
  pending: Set<string>,
  byPerson: ReadonlyMap<string, string[]>,
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  for (const personId of [...pending].sort()) {
    const spouse = byPerson.get(personId)?.find((candidate) => positions.has(candidate))
    if (!spouse) continue
    place(personId, firstFreeSpouse(positions.get(spouse)!, index), positions, index)
    pending.delete(personId)
    return true
  }

  return false
}

function firstFreeParentPair(child: XYPosition, index: SpatialIndex) {
  const childCenter = child.x + PERSON_CARD_WIDTH / 2
  for (let row = 1; row <= 100; row += 1) {
    const y = child.y - row * VERTICAL_STEP
    const first = { x: childCenter - PERSON_CARD_WIDTH / 2 - HORIZONTAL_STEP / 2, y }
    const second = { x: childCenter - PERSON_CARD_WIDTH / 2 + HORIZONTAL_STEP / 2, y }
    if (!index.intersects(rectangleAt(first)) && !index.intersects(rectangleAt(second))) return { first, second }
  }

  return {
    first: { x: childCenter - PERSON_CARD_WIDTH / 2 - HORIZONTAL_STEP / 2, y: child.y - VERTICAL_STEP },
    second: { x: childCenter - PERSON_CARD_WIDTH / 2 + HORIZONTAL_STEP / 2, y: child.y - VERTICAL_STEP },
  }
}

function firstFreeCenteredChild(parents: readonly XYPosition[], index: SpatialIndex) {
  const center = parents.reduce((sum, parent) => sum + parent.x + PERSON_CARD_WIDTH / 2, 0) / parents.length
  const startY = Math.max(...parents.map((parent) => parent.y)) + VERTICAL_STEP
  for (const shift of horizontalShifts()) {
    const position = { x: center - PERSON_CARD_WIDTH / 2 + shift, y: startY }
    if (!index.intersects(rectangleAt(position))) return position
  }

  for (let row = 1; row <= 100; row += 1) {
    const position = { x: center - PERSON_CARD_WIDTH / 2, y: startY + row * VERTICAL_STEP }
    if (!index.intersects(rectangleAt(position))) return position
  }

  return { x: center - PERSON_CARD_WIDTH / 2, y: startY }
}

function horizontalShifts() {
  const shifts = [0]
  for (let step = 1; step <= 100; step += 1) {
    shifts.push(step * HORIZONTAL_STEP, -step * HORIZONTAL_STEP)
  }

  return shifts
}

function firstFreeMirroredParent(child: XYPosition, parent: XYPosition, index: SpatialIndex) {
  const x = 2 * (child.x + PERSON_CARD_WIDTH / 2) - (parent.x + PERSON_CARD_WIDTH / 2) - PERSON_CARD_WIDTH / 2
  for (let row = 0; row <= 100; row += 1) {
    const position = { x, y: child.y - (row + 1) * VERTICAL_STEP }
    if (!index.intersects(rectangleAt(position))) return position
  }

  return { x, y: child.y - VERTICAL_STEP }
}

function firstFreeParent(child: XYPosition, index: SpatialIndex) {
  for (let row = 1; row <= 100; row += 1) {
    const position = { x: child.x, y: child.y - row * VERTICAL_STEP }
    if (!index.intersects(rectangleAt(position))) return position
  }

  return { x: child.x, y: child.y - VERTICAL_STEP }
}

function firstFreeChild(parent: XYPosition, index: SpatialIndex) {
  for (let row = 1; row <= 100; row += 1) {
    const position = { x: parent.x, y: parent.y + row * VERTICAL_STEP }
    if (!index.intersects(rectangleAt(position))) return position
  }

  return { x: parent.x, y: parent.y + VERTICAL_STEP }
}

function firstFreeSpouse(person: XYPosition, index: SpatialIndex) {
  for (let step = 1; step <= 100; step += 1) {
    for (const direction of [1, -1]) {
      const position = { x: person.x + direction * step * HORIZONTAL_STEP, y: person.y }
      if (!index.intersects(rectangleAt(position))) return position
    }
  }

  return { x: person.x + HORIZONTAL_STEP, y: person.y }
}

function fallbackPosition(
  personId: string,
  nodes: ReadonlyMap<string, SearchRoutePlan['nodes'][number]>,
  positions: ReadonlyMap<string, XYPosition>,
  index: SpatialIndex,
) {
  const node = nodes.get(personId)
  const anchor = [...positions]
    .map(([id, position]) => ({ id, position, generation: nodes.get(id)?.generation ?? 0 }))
    .sort((left, right) => Math.abs(left.generation - (node?.generation ?? 0)) - Math.abs(right.generation - (node?.generation ?? 0)) || left.id.localeCompare(right.id))[0]
  const desired = anchor
    ? { x: anchor.position.x, y: anchor.position.y + ((node?.generation ?? 0) - anchor.generation) * VERTICAL_STEP }
    : { x: 0, y: 0 }
  for (let step = 0; step <= 100; step += 1) {
    const position = { x: desired.x + step * HORIZONTAL_STEP, y: desired.y }
    if (!index.intersects(rectangleAt(position))) return position
  }

  return desired
}

function sortedFamilies(byChild: ReadonlyMap<string, ParentLink[]>) {
  return [...byChild].sort(([left], [right]) => left.localeCompare(right))
}

function parentLinkComparator(left: ParentLink, right: ParentLink) {
  return left.childId.localeCompare(right.childId) || left.parentId.localeCompare(right.parentId)
}

function nodeComparator(
  nodes: ReadonlyMap<string, SearchRoutePlan['nodes'][number]>,
  leftId: string,
  rightId: string,
) {
  const left = nodes.get(leftId)
  const right = nodes.get(rightId)
  return (left?.generation ?? 0) - (right?.generation ?? 0)
    || (left?.kind ?? '').localeCompare(right?.kind ?? '')
    || leftId.localeCompare(rightId)
}

function addMarriageNeighbor(byPerson: Map<string, string[]>, personId: string, spouseId: string) {
  const spouses = byPerson.get(personId) ?? []
  if (!spouses.includes(spouseId)) spouses.push(spouseId)
  spouses.sort()
  byPerson.set(personId, spouses)
}

function place(
  personId: string,
  position: XYPosition,
  positions: Map<string, XYPosition>,
  index: SpatialIndex,
) {
  positions.set(personId, position)
  index.add(rectangleAt(position))
}

function rectangleAt(position: XYPosition): Rectangle {
  return { height: PERSON_CARD_HEIGHT, width: PERSON_CARD_WIDTH, x: position.x, y: position.y }
}
