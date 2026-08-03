import type { Edge, Node } from '@xyflow/react'
import type { Person, TreeSnapshot } from './api/generated'

export const BLOOD_NETWORK_PERSON_SIZE = 72
export const BLOOD_NETWORK_FAMILY_SIZE = 18

type BloodNetworkPersonData = {
  person: Person
}

type BloodNetworkFamilyData = {
  childIds: string[]
  parentIds: string[]
}

export type BloodNetworkPersonNode = Node<
  BloodNetworkPersonData,
  'bloodPerson'
>
export type BloodNetworkFamilyNode = Node<
  BloodNetworkFamilyData,
  'bloodFamily'
>
export type BloodNetworkFlowNode =
  | BloodNetworkFamilyNode
  | BloodNetworkPersonNode
export type BloodNetworkFlowEdge = Edge<Record<string, never>, 'straight'>

type ForceNode = {
  id: string
  radius: number
  x: number
  y: number
}

type BloodFamily = {
  childIds: string[]
  id: string
  parentIds: string[]
}

type ForceEdge = {
  source: string
  target: string
}

const FORCE_ITERATIONS = 320
const FORCE_REPULSION = 8_800
const FORCE_SPRING_LENGTH = 155
const FORCE_SPRING_STRENGTH = 0.035
const FORCE_DAMPING = 0.82
const FORCE_GRAVITY = 0.002
const FORCE_MAX_STEP = 18
const FORCE_COLLISION_PADDING = 18

function hashId(value: string): number {
  let hash = 2_166_136_261
  for (let index = 0; index < value.length; index += 1) {
    hash ^= value.charCodeAt(index)
    hash = Math.imul(hash, 16_777_619)
  }

  return hash >>> 0
}

function directionBetween(
  first: ForceNode,
  second: ForceNode,
): { distance: number; x: number; y: number } {
  const dx = second.x - first.x
  const dy = second.y - first.y
  const distance = Math.hypot(dx, dy)
  if (distance > 0.001) {
    return { distance, x: dx / distance, y: dy / distance }
  }

  const angle = (hashId(`${first.id}:${second.id}`) / 0xffff_ffff) * Math.PI * 2
  return { distance: 0.001, x: Math.cos(angle), y: Math.sin(angle) }
}

function layoutForceNodes(nodes: ForceNode[], edges: ForceEdge[]): Map<string, {
  x: number
  y: number
}> {
  const velocity = new Map(nodes.map((node) => [node.id, { x: 0, y: 0 }]))
  const byId = new Map(nodes.map((node) => [node.id, node]))

  for (let iteration = 0; iteration < FORCE_ITERATIONS; iteration += 1) {
    const force = new Map(nodes.map((node) => [node.id, { x: 0, y: 0 }]))
    for (let firstIndex = 0; firstIndex < nodes.length; firstIndex += 1) {
      const first = nodes[firstIndex]
      if (!first) {
        continue
      }
      for (let secondIndex = firstIndex + 1; secondIndex < nodes.length; secondIndex += 1) {
        const second = nodes[secondIndex]
        if (!second) {
          continue
        }
        const direction = directionBetween(first, second)
        const minimumDistance = first.radius + second.radius + FORCE_COLLISION_PADDING
        const collisionForce = direction.distance < minimumDistance
          ? (minimumDistance - direction.distance) * 0.45
          : 0
        const magnitude = FORCE_REPULSION / (direction.distance * direction.distance)
          + collisionForce
        const firstForce = force.get(first.id)
        const secondForce = force.get(second.id)
        if (!firstForce || !secondForce) {
          continue
        }
        firstForce.x -= direction.x * magnitude
        firstForce.y -= direction.y * magnitude
        secondForce.x += direction.x * magnitude
        secondForce.y += direction.y * magnitude
      }
    }

    for (const edge of edges) {
      const source = byId.get(edge.source)
      const target = byId.get(edge.target)
      if (!source || !target) {
        continue
      }
      const direction = directionBetween(source, target)
      const magnitude = (direction.distance - FORCE_SPRING_LENGTH)
        * FORCE_SPRING_STRENGTH
      const sourceForce = force.get(source.id)
      const targetForce = force.get(target.id)
      if (!sourceForce || !targetForce) {
        continue
      }
      sourceForce.x += direction.x * magnitude
      sourceForce.y += direction.y * magnitude
      targetForce.x -= direction.x * magnitude
      targetForce.y -= direction.y * magnitude
    }

    for (const node of nodes) {
      const nodeForce = force.get(node.id)
      const nodeVelocity = velocity.get(node.id)
      if (!nodeForce || !nodeVelocity) {
        continue
      }
      nodeForce.x -= node.x * FORCE_GRAVITY
      nodeForce.y -= node.y * FORCE_GRAVITY
      nodeVelocity.x = (nodeVelocity.x + nodeForce.x) * FORCE_DAMPING
      nodeVelocity.y = (nodeVelocity.y + nodeForce.y) * FORCE_DAMPING
      const speed = Math.hypot(nodeVelocity.x, nodeVelocity.y)
      if (speed > FORCE_MAX_STEP) {
        nodeVelocity.x = nodeVelocity.x / speed * FORCE_MAX_STEP
        nodeVelocity.y = nodeVelocity.y / speed * FORCE_MAX_STEP
      }
      node.x += nodeVelocity.x
      node.y += nodeVelocity.y
    }
  }

  return new Map(nodes.map((node) => [node.id, { x: node.x, y: node.y }]))
}

function buildBloodFamilies(
  snapshot: Pick<TreeSnapshot, 'people' | 'parentLinks'>,
): BloodFamily[] {
  const people = new Set(snapshot.people.map((person) => person.id))
  const parentIdsByChild = new Map<string, Set<string>>()
  for (const link of snapshot.parentLinks) {
    if (!people.has(link.parentId) || !people.has(link.childId)) {
      continue
    }
    const parentIds = parentIdsByChild.get(link.childId) ?? new Set<string>()
    parentIds.add(link.parentId)
    parentIdsByChild.set(link.childId, parentIds)
  }

  const childIdsByParents = new Map<string, string[]>()
  for (const [childId, parentIds] of parentIdsByChild) {
    const key = [...parentIds].sort().join(':')
    const childIds = childIdsByParents.get(key) ?? []
    childIds.push(childId)
    childIdsByParents.set(key, childIds)
  }

  return [...childIdsByParents]
    .map(([parentKey, childIds]) => ({
      childIds: childIds.sort(),
      id: `blood-family:${parentKey}`,
      parentIds: parentKey.split(':'),
    }))
    .sort((first, second) => first.id.localeCompare(second.id))
}

/**
 * Builds a temporary, blood-only projection. A small neutral node represents
 * a shared parent set, so siblings meet at one point without implying marriage.
 */
export function buildBloodNetwork(
  snapshot: Pick<TreeSnapshot, 'people' | 'parentLinks'>,
): {
  edges: BloodNetworkFlowEdge[]
  nodes: BloodNetworkFlowNode[]
} {
  const people = [...snapshot.people].sort((first, second) => (
    first.id.localeCompare(second.id)
  ))
  const families = buildBloodFamilies({ people, parentLinks: snapshot.parentLinks })
  const forceNodes: ForceNode[] = []
  const goldenAngle = Math.PI * (3 - Math.sqrt(5))
  const initializeNode = (id: string, radius: number, index: number) => {
    const angle = goldenAngle * index + (hashId(id) % 360) * Math.PI / 1_800
    const distance = 85 * Math.sqrt(index + 1)
    forceNodes.push({
      id,
      radius,
      x: Math.cos(angle) * distance,
      y: Math.sin(angle) * distance,
    })
  }
  people.forEach((person, index) => initializeNode(
    person.id,
    BLOOD_NETWORK_PERSON_SIZE / 2,
    index,
  ))
  families.forEach((family, index) => initializeNode(
    family.id,
    BLOOD_NETWORK_FAMILY_SIZE / 2,
    people.length + index,
  ))

  const forceEdges: ForceEdge[] = families.flatMap((family) => [
    ...family.parentIds.map((parentId) => ({ source: parentId, target: family.id })),
    ...family.childIds.map((childId) => ({ source: family.id, target: childId })),
  ])
  const positions = layoutForceNodes(forceNodes, forceEdges)
  const positionFor = (id: string, size: number) => {
    const position = positions.get(id) ?? { x: 0, y: 0 }
    return { x: position.x - size / 2, y: position.y - size / 2 }
  }

  return {
    nodes: [
      ...people.map((person) => ({
        data: { person },
        id: person.id,
        position: positionFor(person.id, BLOOD_NETWORK_PERSON_SIZE),
        type: 'bloodPerson' as const,
      })),
      ...families.map((family) => ({
        data: {
          childIds: family.childIds,
          parentIds: family.parentIds,
        },
        id: family.id,
        position: positionFor(family.id, BLOOD_NETWORK_FAMILY_SIZE),
        type: 'bloodFamily' as const,
      })),
    ],
    edges: forceEdges.map((edge) => ({
      id: `blood:${edge.source}:${edge.target}`,
      source: edge.source,
      target: edge.target,
      type: 'straight' as const,
    })),
  }
}
