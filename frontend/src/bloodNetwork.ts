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
  sourceRadius: number
  targetRadius: number
}

export type BloodNetworkFlowEdge = Edge<BloodNetworkEdgeData, 'bloodNetwork'>

type ForceNode = {
  id: string
  mass: number
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
  length: number
  source: string
  strength: number
  target: string
}

type ParentHinge = {
  firstParentId: string
  secondParentId: string
  sharedParentNodeId: string
}

type DescendantSideFamily = {
  childIds: string[]
  parentIds: string[]
  sharedParentNodeId: string
}

export type BloodNetworkForceSettings = {
  averageMovementPerSecondThreshold: number
  baseRepulsion: number
  descendantSideForce: number
  unrelatedParentNodeRepulsion: number
  kinshipRepulsionStep: number
  parentHingeSpringStrength: number
  parentHingeTargetAngle: number
  parentPairSpringStrength: number
  sharedParentSpringStrength: number
}

const FORCE_SPRING_LENGTH = 155
const FORCE_PARENT_PAIR_SPRING_LENGTH = 135
const FORCE_DAMPING = 0.84
const FORCE_MAX_STEP = 3.2
const FORCE_COLLISION_PADDING = 28
const FORCE_COLLISION_STRENGTH = 0.85
const FORCE_INITIAL_ALPHA = 0.9
const FORCE_ALPHA_DECAY = 0.994
const FORCE_STEPS_PER_TICK = 4
const FORCE_MAX_REPULSION_MAGNITUDE = 1.25
const FORCE_MAX_COLLISION_MAGNITUDE = 10
const FORCE_MAX_SPRING_MAGNITUDE = 10
const FORCE_MAX_PARENT_HINGE_MAGNITUDE = 6
const FORCE_MAX_DESCENDANT_SIDE_MAGNITUDE = 24
const FORCE_DESCENDANT_SIDE_SAFE_ALIGNMENT = Math.cos(Math.PI * 75 / 180)
const FORCE_DESCENDANT_SIDE_FULL_ALIGNMENT = Math.cos(Math.PI * 105 / 180)
const FORCE_UNRELATED_PARENT_NODE_REPULSION_RANGE = 180
const FORCE_CLOSE_KINSHIP_REPULSION_MULTIPLIER = 0.45
const FORCE_KINSHIP_REPULSION_STEP = 0.3
const FORCE_MAX_KINSHIP_REPULSION_MULTIPLIER = 2.25
const FORCE_UNRELATED_REPULSION_MULTIPLIER = 2.5
const INITIAL_AREA_PER_NODE = 200_000
const INITIAL_ASPECT_RATIO = 5 / 4

export const DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS: BloodNetworkForceSettings = {
  averageMovementPerSecondThreshold: 1,
  baseRepulsion: 3,
  descendantSideForce: 12,
  unrelatedParentNodeRepulsion: 6,
  kinshipRepulsionStep: FORCE_KINSHIP_REPULSION_STEP,
  parentHingeSpringStrength: 4,
  parentHingeTargetAngle: 45,
  parentPairSpringStrength: 0.2,
  sharedParentSpringStrength: 0.12,
}

function networkHandles(size: number) {
  return [
    {
      height: 0,
      id: 'network-target',
      position: Position.Left,
      type: 'target' as const,
      width: 0,
      x: size / 2,
      y: size / 2,
    },
    {
      height: 0,
      id: 'network-source',
      position: Position.Left,
      type: 'source' as const,
      width: 0,
      x: size / 2,
      y: size / 2,
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

function bloodGraphDistances(
  nodeIds: string[],
  edges: ForceEdge[],
): Map<string, Map<string, number>> {
  const neighbors = new Map(nodeIds.map((id) => [id, new Set<string>()]))
  for (const edge of edges) {
    neighbors.get(edge.source)?.add(edge.target)
    neighbors.get(edge.target)?.add(edge.source)
  }

  return new Map(nodeIds.map((source) => {
    const distances = new Map<string, number>([[source, 0]])
    const queue = [source]
    for (let index = 0; index < queue.length; index += 1) {
      const current = queue[index]
      if (!current) {
        continue
      }
      const distance = distances.get(current) ?? 0
      for (const neighbor of neighbors.get(current) ?? []) {
        if (!distances.has(neighbor)) {
          distances.set(neighbor, distance + 1)
          queue.push(neighbor)
        }
      }
    }
    return [source, distances]
  }))
}

export function bloodNetworkRepulsionMultiplier(
  pathLength: number | undefined,
  kinshipRepulsionStep = DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS.kinshipRepulsionStep,
): number {
  if (pathLength === undefined) {
    return FORCE_UNRELATED_REPULSION_MULTIPLIER
  }

  return Math.min(
    FORCE_MAX_KINSHIP_REPULSION_MULTIPLIER,
    FORCE_CLOSE_KINSHIP_REPULSION_MULTIPLIER
      + Math.max(pathLength - 1, 0) * kinshipRepulsionStep,
  )
}

export function bloodNetworkNodeMass(
  nodeType: BloodNetworkFlowNode['type'],
  relationshipDegree: number,
): number {
  return nodeType === 'bloodFamily' ? 1 : relationshipDegree + 1
}

export function bloodNetworkUnrelatedParentNodeRepulsion(
  distance: number,
  strength = DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS.unrelatedParentNodeRepulsion,
): number {
  const proximity = Math.max(0, 1 - distance / FORCE_UNRELATED_PARENT_NODE_REPULSION_RANGE)
  return strength * proximity ** 4
}

export function bloodNetworkDescendantSideMagnitude(
  alignment: number,
  strength = DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS.descendantSideForce,
): number {
  const progress = Math.max(0, Math.min(1, (
    FORCE_DESCENDANT_SIDE_SAFE_ALIGNMENT - alignment
  ) / (
    FORCE_DESCENDANT_SIDE_SAFE_ALIGNMENT - FORCE_DESCENDANT_SIDE_FULL_ALIGNMENT
  )))
  const smoothProgress = progress * progress * (3 - 2 * progress)
  return Math.min(FORCE_MAX_DESCENDANT_SIDE_MAGNITUDE, strength) * smoothProgress
}

export function bloodNetworkDescendantSideForces(
  child: { x: number; y: number },
  sharedParent: { x: number; y: number },
  parentMidpoint: { x: number; y: number },
  strength = DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS.descendantSideForce,
): {
  child: { x: number; y: number }
  parentMidpoint: { x: number; y: number }
  sharedParent: { x: number; y: number }
} {
  const childVector = {
    x: child.x - sharedParent.x,
    y: child.y - sharedParent.y,
  }
  const descendantVector = {
    x: sharedParent.x - parentMidpoint.x,
    y: sharedParent.y - parentMidpoint.y,
  }
  const childDistance = Math.hypot(childVector.x, childVector.y)
  const descendantDistance = Math.hypot(descendantVector.x, descendantVector.y)
  if (childDistance < 0.001 || descendantDistance < 0.001) {
    return {
      child: { x: 0, y: 0 },
      parentMidpoint: { x: 0, y: 0 },
      sharedParent: { x: 0, y: 0 },
    }
  }
  const childDirection = {
    x: childVector.x / childDistance,
    y: childVector.y / childDistance,
  }
  const descendantDirection = {
    x: descendantVector.x / descendantDistance,
    y: descendantVector.y / descendantDistance,
  }
  const alignment = childDirection.x * descendantDirection.x
    + childDirection.y * descendantDirection.y
  const magnitude = bloodNetworkDescendantSideMagnitude(alignment, strength)
    * FORCE_SPRING_LENGTH
  const childGradient = {
    x: (descendantDirection.x - childDirection.x * alignment) / childDistance,
    y: (descendantDirection.y - childDirection.y * alignment) / childDistance,
  }
  const parentGradient = {
    x: (childDirection.x - descendantDirection.x * alignment) / descendantDistance,
    y: (childDirection.y - descendantDirection.y * alignment) / descendantDistance,
  }
  const childForce = {
    x: childGradient.x * magnitude,
    y: childGradient.y * magnitude,
  }
  const parentForce = {
    x: -parentGradient.x * magnitude,
    y: -parentGradient.y * magnitude,
  }
  return {
    child: childForce,
    parentMidpoint: parentForce,
    sharedParent: {
      x: -childForce.x - parentForce.x,
      y: -childForce.y - parentForce.y,
    },
  }
}

export function bloodNetworkParentHingeMagnitude(
  currentAngle: number,
  targetAngleDegrees: number,
  strength: number,
): number {
  const targetAngle = targetAngleDegrees * Math.PI / 180
  return Math.max(
    -FORCE_MAX_PARENT_HINGE_MAGNITUDE,
    Math.min(
      FORCE_MAX_PARENT_HINGE_MAGNITUDE,
      (targetAngle - currentAngle) * strength,
    ),
  )
}

export type BloodNetworkSimulation = {
  getNodes: () => BloodNetworkFlowNode[]
  tick: () => void
}

export function bloodNetworkAverageNodeMovement(
  previousNodes: BloodNetworkFlowNode[],
  nextNodes: BloodNetworkFlowNode[],
): number {
  const previousPositions = new Map(previousNodes.map((node) => [
    node.id,
    node.position,
  ]))
  const totalMovement = nextNodes.reduce((total, node) => {
    const previousPosition = previousPositions.get(node.id)
    if (!previousPosition) {
      return total
    }

    return total + Math.hypot(
      node.position.x - previousPosition.x,
      node.position.y - previousPosition.y,
    )
  }, 0)

  return totalMovement / Math.max(nextNodes.length, 1)
}

export function createBloodNetworkSimulation(
  network: {
    edges: BloodNetworkFlowEdge[]
    nodes: BloodNetworkFlowNode[]
  },
  settings: BloodNetworkForceSettings = DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS,
): BloodNetworkSimulation {
  const nodes = network.nodes.map((node) => {
    const size = node.type === 'bloodPerson'
      ? BLOOD_NETWORK_PERSON_SIZE
      : BLOOD_NETWORK_FAMILY_SIZE
    return {
      id: node.id,
      mass: 1,
      radius: size / 2,
      x: node.position.x + size / 2,
      y: node.position.y + size / 2,
    }
  })
  const relationshipEdges: ForceEdge[] = network.edges.map((edge) => ({
    length: (edge.data?.relation ?? 'familyToChild') === 'parentToFamily'
      ? FORCE_SPRING_LENGTH * 1.1
      : FORCE_SPRING_LENGTH * 0.8,
    source: edge.source,
    strength: settings.sharedParentSpringStrength,
    target: edge.target,
  }))
  const distances = bloodGraphDistances(
    nodes.map((node) => node.id),
    relationshipEdges,
  )
  const velocity = new Map(nodes.map((node) => [node.id, { x: 0, y: 0 }]))
  const byId = new Map(nodes.map((node) => [node.id, node]))
  const nodeTypes = new Map(network.nodes.map((node) => [node.id, node.type]))
  const directlyLinkedParentNodesByPerson = new Map<string, Set<string>>()
  for (const edge of relationshipEdges) {
    const sourceType = nodeTypes.get(edge.source)
    const targetType = nodeTypes.get(edge.target)
    const personId = sourceType === 'bloodPerson'
      ? edge.source
      : targetType === 'bloodPerson'
        ? edge.target
        : null
    const parentNodeId = sourceType === 'bloodFamily'
      ? edge.source
      : targetType === 'bloodFamily'
        ? edge.target
        : null
    if (!personId || !parentNodeId) {
      continue
    }
    const linkedNodes = directlyLinkedParentNodesByPerson.get(personId) ?? new Set<string>()
    linkedNodes.add(parentNodeId)
    directlyLinkedParentNodesByPerson.set(personId, linkedNodes)
  }
  const degree = new Map(nodes.map((node) => [node.id, 0]))
  for (const edge of relationshipEdges) {
    degree.set(edge.source, (degree.get(edge.source) ?? 0) + 1)
    degree.set(edge.target, (degree.get(edge.target) ?? 0) + 1)
  }
  for (const node of nodes) {
    // Persons need more room as their blood-link degree grows. A family node
    // is only a routing point, so even a large sibling group must not turn it
    // into a heavyweight repelling centre.
    node.mass = bloodNetworkNodeMass(
      nodeTypes.get(node.id) ?? 'bloodPerson',
      degree.get(node.id) ?? 0,
    )
  }
  const parentHinges: ParentHinge[] = network.nodes.flatMap((node) => {
    if (node.type !== 'bloodFamily') {
      return []
    }
    return node.data.parentIds.flatMap((parentId, index) => (
      node.data.parentIds.slice(index + 1).map((partnerId) => ({
        firstParentId: parentId,
        secondParentId: partnerId,
        sharedParentNodeId: node.id,
      }))
    ))
  })
  const descendantSideFamilies: DescendantSideFamily[] = network.nodes.flatMap((node) => {
    if (node.type !== 'bloodFamily' || node.data.parentIds.length === 0) {
      return []
    }
    return [{
      childIds: node.data.childIds,
      parentIds: node.data.parentIds,
      sharedParentNodeId: node.id,
    }]
  })
  const parentPairSprings: ForceEdge[] = parentHinges.map((hinge) => ({
    length: FORCE_PARENT_PAIR_SPRING_LENGTH,
    source: hinge.firstParentId,
    strength: settings.parentPairSpringStrength,
    target: hinge.secondParentId,
  }))
  const springs = [...relationshipEdges, ...parentPairSprings]
  let alpha = FORCE_INITIAL_ALPHA

  const advance = () => {
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
          settings.baseRepulsion * first.mass * second.mass / direction.distance,
        ) * bloodNetworkRepulsionMultiplier(
          distances.get(first.id)?.get(second.id),
          settings.kinshipRepulsionStep,
        )
        const person = nodeTypes.get(first.id) === 'bloodPerson' ? first : (
          nodeTypes.get(second.id) === 'bloodPerson' ? second : null
        )
        const parentNode = nodeTypes.get(first.id) === 'bloodFamily' ? first : (
          nodeTypes.get(second.id) === 'bloodFamily' ? second : null
        )
        const unrelatedParentNodeForce = person && parentNode
          && !directlyLinkedParentNodesByPerson.get(person.id)?.has(parentNode.id)
          ? bloodNetworkUnrelatedParentNodeRepulsion(
            direction.distance,
            settings.unrelatedParentNodeRepulsion,
          )
          : 0
        const magnitude = (chargeForce + collisionForce + unrelatedParentNodeForce) * alpha
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

    for (const family of descendantSideFamilies) {
      const sharedParentNode = byId.get(family.sharedParentNodeId)
      const parents = family.parentIds.flatMap((parentId) => {
        const parent = byId.get(parentId)
        return parent ? [parent] : []
      })
      if (!sharedParentNode || parents.length === 0) {
        continue
      }
      const parentMidpoint = parents.reduce((midpoint, parent) => ({
        x: midpoint.x + parent.x / parents.length,
        y: midpoint.y + parent.y / parents.length,
      }), { x: 0, y: 0 })
      for (const childId of family.childIds) {
        const child = byId.get(childId)
        const childForce = force.get(childId)
        const sharedParentForce = force.get(sharedParentNode.id)
        if (!child || !childForce || !sharedParentForce) {
          continue
        }
        const descendantForces = bloodNetworkDescendantSideForces(
          child,
          sharedParentNode,
          parentMidpoint,
          settings.descendantSideForce,
        )
        childForce.x += descendantForces.child.x * alpha
        childForce.y += descendantForces.child.y * alpha
        sharedParentForce.x += descendantForces.sharedParent.x * alpha
        sharedParentForce.y += descendantForces.sharedParent.y * alpha
        for (const parent of parents) {
          const parentForce = force.get(parent.id)
          if (!parentForce) {
            continue
          }
          parentForce.x += descendantForces.parentMidpoint.x / parents.length * alpha
          parentForce.y += descendantForces.parentMidpoint.y / parents.length * alpha
        }
      }
    }

    for (const hinge of parentHinges) {
      const firstParent = byId.get(hinge.firstParentId)
      const secondParent = byId.get(hinge.secondParentId)
      const sharedParentNode = byId.get(hinge.sharedParentNodeId)
      if (!firstParent || !secondParent || !sharedParentNode) {
        continue
      }
      const firstDirection = directionBetween(sharedParentNode, firstParent)
      const secondDirection = directionBetween(sharedParentNode, secondParent)
      const dotProduct = Math.max(-1, Math.min(1,
        firstDirection.x * secondDirection.x + firstDirection.y * secondDirection.y,
      ))
      const currentAngle = Math.acos(dotProduct)
      const magnitude = bloodNetworkParentHingeMagnitude(
        currentAngle,
        settings.parentHingeTargetAngle,
        settings.parentHingeSpringStrength,
      ) * alpha
      if (magnitude === 0) {
        continue
      }
      const crossProduct = firstDirection.x * secondDirection.y
        - firstDirection.y * secondDirection.x
      const orientation = crossProduct === 0
        ? (hashId(hinge.sharedParentNodeId) % 2 === 0 ? 1 : -1)
        : Math.sign(crossProduct)
      const firstTangent = orientation > 0
        ? { x: firstDirection.y, y: -firstDirection.x }
        : { x: -firstDirection.y, y: firstDirection.x }
      const secondTangent = orientation > 0
        ? { x: -secondDirection.y, y: secondDirection.x }
        : { x: secondDirection.y, y: -secondDirection.x }
      const firstParentForce = force.get(firstParent.id)
      const secondParentForce = force.get(secondParent.id)
      const sharedParentForce = force.get(sharedParentNode.id)
      if (!firstParentForce || !secondParentForce || !sharedParentForce) {
        continue
      }
      const firstForceX = firstTangent.x * magnitude
      const firstForceY = firstTangent.y * magnitude
      const secondForceX = secondTangent.x * magnitude
      const secondForceY = secondTangent.y * magnitude
      // The two tangential parent forces get an equal reaction at the pivot.
      firstParentForce.x += firstForceX
      firstParentForce.y += firstForceY
      secondParentForce.x += secondForceX
      secondParentForce.y += secondForceY
      sharedParentForce.x -= firstForceX + secondForceX
      sharedParentForce.y -= firstForceY + secondForceY
    }

    for (const edge of springs) {
      const source = byId.get(edge.source)
      const target = byId.get(edge.target)
      if (!source || !target) {
        continue
      }
      const direction = directionBetween(source, target)
      const magnitude = Math.max(
        -FORCE_MAX_SPRING_MAGNITUDE,
        Math.min(
          FORCE_MAX_SPRING_MAGNITUDE,
          (direction.distance - edge.length) * edge.strength,
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
  }

  const tick = () => {
    for (let step = 0; step < FORCE_STEPS_PER_TICK; step += 1) {
      advance()
    }
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
  generation = 0,
): {
  edges: BloodNetworkFlowEdge[]
  nodes: BloodNetworkFlowNode[]
} {
  const people = [...snapshot.people].sort((first, second) => (
    first.id.localeCompare(second.id)
  ))
  const families = buildBloodFamilies({ people, parentLinks: snapshot.parentLinks })
  const forceNodes: ForceNode[] = []
  const nodeCount = people.length + families.length
  const initialArea = Math.max(nodeCount, 1) * INITIAL_AREA_PER_NODE
  const horizontalSpread = Math.sqrt(initialArea * INITIAL_ASPECT_RATIO)
  const verticalSpread = initialArea / horizontalSpread
  const randomPosition = () => (
    (Math.random() + generation * 0.618_033_988_749_895) % 1
  )
  const initializeNode = (id: string, radius: number) => {
    forceNodes.push({
      id,
      mass: 1,
      radius,
      x: (randomPosition() - 0.5) * horizontalSpread,
      y: (randomPosition() - 0.5) * verticalSpread,
    })
  }
  people.forEach((person) => initializeNode(
    person.id,
    BLOOD_NETWORK_PERSON_SIZE / 2,
  ))
  families.forEach((family) => initializeNode(
    family.id,
    BLOOD_NETWORK_FAMILY_SIZE / 2,
  ))

  const flowEdges = families.flatMap((family) => [
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
  const radiusById = new Map(forceNodes.map((node) => [node.id, node.radius]))
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
    edges: flowEdges.map((edge) => ({
      className: `blood-network-${edge.relation}-edge`,
      data: {
        relation: edge.relation,
        sourceRadius: radiusById.get(edge.source) ?? 0,
        targetRadius: radiusById.get(edge.target) ?? 0,
      },
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
      type: 'bloodNetwork' as const,
    })),
  }
}
