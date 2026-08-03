import { MarkerType, Position, type Edge, type Node } from '@xyflow/react'
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
type BloodNetworkEdgeData = {
  relation: 'familyToChild' | 'parentToFamily'
}

export type BloodNetworkFlowEdge = Edge<BloodNetworkEdgeData, 'straight'>

type ForceNode = {
  id: string
  mass: number
  radius: number
  rank: number
  x: number
  y: number
}

type BloodFamily = {
  childIds: string[]
  id: string
  parentIds: string[]
}

type ForceEdge = {
  relation: BloodNetworkEdgeData['relation']
  source: string
  target: string
}

const FORCE_REPULSION = 1.25
const FORCE_SPRING_LENGTH = 155
const FORCE_SPRING_STRENGTH = 0.12
const FORCE_DAMPING = 0.84
const FORCE_HORIZONTAL_GRAVITY = 0.45
const FORCE_LAYER_GAP = 140
const FORCE_LAYER_STRENGTH = 0.08
const FORCE_MAX_STEP = 3.2
const FORCE_COLLISION_PADDING = 18
const FORCE_COLLISION_STRENGTH = 0.65
const FORCE_INITIAL_ALPHA = 0.9
const FORCE_MINIMUM_ALPHA = 0.006
const FORCE_ALPHA_DECAY = 0.994
const FORCE_MAX_TICKS = 1_000
const FORCE_MAX_REPULSION_MAGNITUDE = 0.8
const FORCE_MAX_COLLISION_MAGNITUDE = 8
const FORCE_MAX_SPRING_MAGNITUDE = 10

function networkHandles(size: number) {
  return [
    {
      height: 0,
      id: 'network-target',
      position: Position.Top,
      type: 'target' as const,
      width: 0,
      x: size / 2,
      y: 0,
    },
    {
      height: 0,
      id: 'network-source',
      position: Position.Bottom,
      type: 'source' as const,
      width: 0,
      x: size / 2,
      y: size,
    },
  ]
}

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

export type BloodNetworkSimulation = {
  getNodes: () => BloodNetworkFlowNode[]
  tick: () => boolean
}

export function createBloodNetworkSimulation(
  network: {
    edges: BloodNetworkFlowEdge[]
    nodes: BloodNetworkFlowNode[]
  },
): BloodNetworkSimulation {
  const nodes = network.nodes.map((node) => {
    const size = node.type === 'bloodPerson'
      ? BLOOD_NETWORK_PERSON_SIZE
      : BLOOD_NETWORK_FAMILY_SIZE
    return {
      id: node.id,
      mass: 1,
      radius: size / 2,
      rank: 0,
      x: node.position.x + size / 2,
      y: node.position.y + size / 2,
    }
  })
  const edges = network.edges.map((edge) => ({
    relation: edge.data?.relation ?? 'familyToChild',
    source: edge.source,
    target: edge.target,
  }))
  const velocity = new Map(nodes.map((node) => [node.id, { x: 0, y: 0 }]))
  const byId = new Map(nodes.map((node) => [node.id, node]))
  const degree = new Map(nodes.map((node) => [node.id, 0]))
  const outgoing = new Map(nodes.map((node) => [node.id, [] as string[]]))
  const remainingIncoming = new Map(nodes.map((node) => [node.id, 0]))
  for (const edge of edges) {
    degree.set(edge.source, (degree.get(edge.source) ?? 0) + 1)
    degree.set(edge.target, (degree.get(edge.target) ?? 0) + 1)
    outgoing.get(edge.source)?.push(edge.target)
    remainingIncoming.set(edge.target, (remainingIncoming.get(edge.target) ?? 0) + 1)
  }
  for (const node of nodes) {
    // ForceAtlas2 weighs the charge of a node by its degree plus one. This
    // makes busy family hubs claim enough space without changing the strength
    // of the genealogical links that hold a sibling group together.
    node.mass = (degree.get(node.id) ?? 0) + 1
  }
  const rankedNodeIds = nodes
    .filter((node) => (remainingIncoming.get(node.id) ?? 0) === 0)
    .map((node) => node.id)
  for (let index = 0; index < rankedNodeIds.length; index += 1) {
    const nodeId = rankedNodeIds[index]
    if (!nodeId) {
      continue
    }
    const source = byId.get(nodeId)
    if (!source) {
      continue
    }
    for (const targetId of outgoing.get(nodeId) ?? []) {
      const target = byId.get(targetId)
      if (!target) {
        continue
      }
      target.rank = Math.max(target.rank, source.rank + 1)
      const pendingIncoming = (remainingIncoming.get(targetId) ?? 1) - 1
      remainingIncoming.set(targetId, pendingIncoming)
      if (pendingIncoming === 0) {
        rankedNodeIds.push(targetId)
      }
    }
  }
  const averageRank = nodes.reduce((sum, node) => sum + node.rank, 0) / nodes.length
  for (const node of nodes) {
    const jitter = (hashId(node.id) % 101 - 50) * 0.35
    node.y = (node.rank - averageRank) * FORCE_LAYER_GAP + jitter
  }
  let alpha = FORCE_INITIAL_ALPHA
  let tickCount = 0

  const tick = () => {
    if (alpha < FORCE_MINIMUM_ALPHA || tickCount >= FORCE_MAX_TICKS) {
      return false
    }
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
          ? Math.min(
            FORCE_MAX_COLLISION_MAGNITUDE,
            (minimumDistance - direction.distance) * FORCE_COLLISION_STRENGTH,
          )
          : 0
        const chargeForce = Math.min(
          FORCE_MAX_REPULSION_MAGNITUDE,
          FORCE_REPULSION * first.mass * second.mass / direction.distance,
        )
        const magnitude = (chargeForce + collisionForce) * alpha
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
      const idealLength = edge.relation === 'parentToFamily'
        ? FORCE_SPRING_LENGTH * 1.1
        : FORCE_SPRING_LENGTH * 0.8
      const magnitude = Math.max(
        -FORCE_MAX_SPRING_MAGNITUDE,
        Math.min(
          FORCE_MAX_SPRING_MAGNITUDE,
          (direction.distance - idealLength)
            * FORCE_SPRING_STRENGTH,
        ),
      ) * alpha
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
      nodeForce.x -= node.x / (Math.abs(node.x) + FORCE_LAYER_GAP)
        * node.mass * FORCE_HORIZONTAL_GRAVITY * alpha
      const desiredY = (node.rank - averageRank) * FORCE_LAYER_GAP
      nodeForce.y += (desiredY - node.y) * FORCE_LAYER_STRENGTH * alpha
      nodeVelocity.x = (nodeVelocity.x + nodeForce.x) * FORCE_DAMPING
      nodeVelocity.y = (nodeVelocity.y + nodeForce.y) * FORCE_DAMPING
      const speed = Math.hypot(nodeVelocity.x, nodeVelocity.y)
      if (!Number.isFinite(speed)) {
        nodeVelocity.x = 0
        nodeVelocity.y = 0
        continue
      }
      if (speed > FORCE_MAX_STEP) {
        nodeVelocity.x = nodeVelocity.x / speed * FORCE_MAX_STEP
        nodeVelocity.y = nodeVelocity.y / speed * FORCE_MAX_STEP
      }
      node.x += nodeVelocity.x
      node.y += nodeVelocity.y
    }
    alpha *= FORCE_ALPHA_DECAY
    tickCount += 1
    return alpha >= FORCE_MINIMUM_ALPHA && tickCount < FORCE_MAX_TICKS
  }

  const getNodes = () => network.nodes.map((node) => {
    const size = node.type === 'bloodPerson'
      ? BLOOD_NETWORK_PERSON_SIZE
      : BLOOD_NETWORK_FAMILY_SIZE
    const position = byId.get(node.id) ?? { x: 0, y: 0 }
    return {
      ...node,
      position: { x: position.x - size / 2, y: position.y - size / 2 },
    }
  })

  return { getNodes, tick }
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
    // Keep the whole graph visible when the force view opens. The simulation
    // then has room to make a visible, controlled adjustment instead of
    // spending many seconds pulling nodes back from a very wide initial spiral.
    const distance = 56 * Math.sqrt(index + 1)
    forceNodes.push({
      id,
      mass: 1,
      radius,
      rank: 0,
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
    ...family.parentIds.map((parentId) => ({
      relation: 'parentToFamily' as const,
      source: parentId,
      target: family.id,
    })),
    ...family.childIds.map((childId) => ({
      relation: 'familyToChild' as const,
      source: family.id,
      target: childId,
    })),
  ])
  const positionFor = (id: string, size: number) => {
    const position = forceNodes.find((node) => node.id === id) ?? { x: 0, y: 0 }
    return { x: position.x - size / 2, y: position.y - size / 2 }
  }

  return {
    nodes: [
      ...people.map((person) => ({
        data: { person },
        height: BLOOD_NETWORK_PERSON_SIZE,
        handles: networkHandles(BLOOD_NETWORK_PERSON_SIZE),
        id: person.id,
        position: positionFor(person.id, BLOOD_NETWORK_PERSON_SIZE),
        type: 'bloodPerson' as const,
        width: BLOOD_NETWORK_PERSON_SIZE,
      })),
      ...families.map((family) => ({
        data: {
          childIds: family.childIds,
          parentIds: family.parentIds,
        },
        height: BLOOD_NETWORK_FAMILY_SIZE,
        handles: networkHandles(BLOOD_NETWORK_FAMILY_SIZE),
        id: family.id,
        position: positionFor(family.id, BLOOD_NETWORK_FAMILY_SIZE),
        type: 'bloodFamily' as const,
        width: BLOOD_NETWORK_FAMILY_SIZE,
      })),
    ],
    edges: forceEdges.map((edge) => ({
      className: `blood-network-${edge.relation}-edge`,
      data: { relation: edge.relation },
      id: `blood:${edge.source}:${edge.target}`,
      markerEnd: {
        color: edge.relation === 'parentToFamily'
          ? 'var(--color-state-warning-strong)'
          : 'var(--color-graph-line)',
        type: MarkerType.ArrowClosed,
      },
      source: edge.source,
      sourceHandle: 'network-source',
      target: edge.target,
      targetHandle: 'network-target',
      type: 'straight' as const,
    })),
  }
}
