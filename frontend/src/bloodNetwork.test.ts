import { describe, expect, it, vi } from 'vitest'
import type { ParentLink, Person } from './api/generated'
import {
  bloodNetworkAverageNodeMovement,
  bloodNetworkCrossBranchRepulsionMultiplier,
  bloodNetworkDescendantSideForces,
  bloodNetworkDescendantSideMagnitude,
  bloodNetworkNodeMass,
  bloodNetworkParentHingeMagnitude,
  bloodNetworkPersonFlowHingeForces,
  bloodNetworkPersonFlowHingeMagnitude,
  bloodNetworkPathProfiles,
  bloodNetworkRelationshipSpringLengths,
  bloodNetworkUnrelatedParentNodeRepulsion,
  bloodNetworkRepulsionMultiplier,
  buildBloodNetwork,
  createBloodNetworkSimulation,
  restoreBloodNetworkNodePositions,
  rotateBloodNetworkNodes,
} from './bloodNetwork'

function person(id: string): Person {
  return {
    id,
    birthSurname: { state: 'known', value: id },
    givenName: { state: 'known', value: 'Person' },
    patronymic: { state: 'unknown' },
    gender: 'unknown',
    birthDate: { state: 'unknown' },
    deathDate: null,
    photoThumbnailUrl: null,
    coordinates: { x: 0, y: 0 },
    createdAt: '',
    updatedAt: '',
  }
}

function parentLink(parentId: string, childId: string, id: string): ParentLink {
  return { id, parentId, childId, role: 'father' }
}

describe('buildBloodNetwork', () => {
  it('gives distant and unrelated branches more space than close relatives', () => {
    expect(bloodNetworkRepulsionMultiplier(1)).toBe(0.45)
    expect(bloodNetworkRepulsionMultiplier(2)).toBe(0.75)
    expect(bloodNetworkRepulsionMultiplier(6)).toBe(1.95)
    expect(bloodNetworkRepulsionMultiplier(20)).toBe(2.25)
    expect(bloodNetworkRepulsionMultiplier(undefined)).toBe(2.5)
  })

  it('adds branch repulsion only when a shortest path follows and opposes arrows', () => {
    const profiles = bloodNetworkPathProfiles(
      [
        'great-grandparent',
        'grandparent-a',
        'grandparent-b',
        'parent-a',
        'parent-b',
        'person',
        'shared-parent-a',
        'shared-parent-b',
      ],
      [
        { source: 'great-grandparent', target: 'parent-a' },
        { source: 'parent-a', target: 'person' },
        { source: 'grandparent-a', target: 'shared-parent-a' },
        { source: 'shared-parent-a', target: 'parent-a' },
        { source: 'grandparent-b', target: 'shared-parent-b' },
        { source: 'shared-parent-b', target: 'parent-b' },
        { source: 'parent-b', target: 'person' },
      ],
    )

    expect(profiles.get('great-grandparent')?.get('person')).toEqual({
      crossness: 0,
      distance: 2,
    })
    expect(profiles.get('grandparent-a')?.get('grandparent-b')).toEqual({
      crossness: 1,
      distance: 6,
    })
    expect(bloodNetworkCrossBranchRepulsionMultiplier(0, 2)).toBe(1)
    expect(bloodNetworkCrossBranchRepulsionMultiplier(1, 0.5)).toBe(1.5)
  })

  it('keeps family routing nodes lighter than connected people', () => {
    expect(bloodNetworkNodeMass('bloodFamily', 20)).toBe(1)
    expect(bloodNetworkNodeMass('bloodPerson', 1)).toBe(2)
    expect(bloodNetworkNodeMass('bloodPerson', 4)).toBe(5)
  })

  it('restores saved positions only for an identical temporary graph', () => {
    const network = buildBloodNetwork({
      people: [person('parent'), person('child')],
      parentLinks: [parentLink('parent', 'child', 'parent-child')],
    })
    const state = {
      forces: {},
      nodes: network.nodes.map((node, index) => ({
        id: node.id,
        coordinates: { x: index * 100, y: -index * 100 },
      })),
      viewport: { x: 0, y: 0, zoom: 1 },
    } as unknown as import('./api/generated').BloodNetworkMapState

    const restored = restoreBloodNetworkNodePositions(network, state)
    expect(restored.nodes.map((node) => node.position)).toEqual(
      state.nodes.map((node) => node.coordinates),
    )
    expect(restoreBloodNetworkNodePositions(network, {
      ...state,
      nodes: state.nodes.slice(1),
    })).toBe(network)
  })

  it('splits the parent-to-child path length by the configured ratio', () => {
    expect(bloodNetworkRelationshipSpringLengths(300, 0.5)).toEqual({
      familyToChild: 150,
      parentToFamily: 150,
    })
    const unequalLengths = bloodNetworkRelationshipSpringLengths(300, 0.58)
    expect(unequalLengths.familyToChild).toBeCloseTo(126)
    expect(unequalLengths.parentToFamily).toBeCloseTo(174)
  })

  it('limits unrelated parent-node repulsion to a local range', () => {
    expect(bloodNetworkUnrelatedParentNodeRepulsion(0, 6)).toBe(6)
    expect(bloodNetworkUnrelatedParentNodeRepulsion(90, 6)).toBeLessThan(0.4)
    expect(bloodNetworkUnrelatedParentNodeRepulsion(180, 6)).toBe(0)
    expect(bloodNetworkUnrelatedParentNodeRepulsion(500, 6)).toBe(0)
  })

  it('uses the target angle to correct a parent hinge in either direction', () => {
    expect(bloodNetworkParentHingeMagnitude(Math.PI / 18, 60, 4)).toBeGreaterThan(0)
    expect(bloodNetworkParentHingeMagnitude(Math.PI / 3, 60, 4)).toBeCloseTo(0)
    expect(bloodNetworkParentHingeMagnitude(Math.PI * 2 / 3, 60, 4)).toBeLessThan(0)
  })

  it('turns children toward the descendant side without a sharp boundary', () => {
    expect(bloodNetworkDescendantSideMagnitude(1, 12)).toBe(0)
    expect(bloodNetworkDescendantSideMagnitude(0.1, 12)).toBeGreaterThan(0)
    expect(bloodNetworkDescendantSideMagnitude(-1, 12)).toBe(12)
  })

  it('balances descendant-side force and torque around the family', () => {
    const child = { x: 80, y: 70 }
    const sharedParent = { x: 200, y: 100 }
    const parentMidpoint = { x: 0, y: 100 }
    const forces = bloodNetworkDescendantSideForces(
      child,
      sharedParent,
      parentMidpoint,
      12,
    )
    const torque = (point: { x: number; y: number }, force: { x: number; y: number }) => (
      point.x * force.y - point.y * force.x
    )

    expect(forces.child.x + forces.sharedParent.x + forces.parentMidpoint.x).toBeCloseTo(0)
    expect(forces.child.y + forces.sharedParent.y + forces.parentMidpoint.y).toBeCloseTo(0)
    expect(
      torque(child, forces.child)
      + torque(sharedParent, forces.sharedParent)
      + torque(parentMidpoint, forces.parentMidpoint),
    ).toBeCloseTo(0)
  })

  it('keeps a person flow hinge torque-balanced', () => {
    const incomingParent = { x: 0, y: 0 }
    const personPosition = { x: 100, y: 100 }
    const outgoingParents = [{ x: 260, y: 120 }, { x: 230, y: 260 }]
    const forces = bloodNetworkPersonFlowHingeForces(
      incomingParent,
      personPosition,
      outgoingParents,
      8,
    )
    const torque = (point: { x: number; y: number }, force: { x: number; y: number }) => (
      point.x * force.y - point.y * force.x
    )

    expect(bloodNetworkPersonFlowHingeMagnitude(1, 8)).toBe(0)
    expect(bloodNetworkPersonFlowHingeMagnitude(0, 8)).toBe(4)
    expect(bloodNetworkPersonFlowHingeMagnitude(-1, 8)).toBe(8)
    expect(
      forces.incomingParent.x + forces.person.x
      + forces.outgoingParents.reduce((sum, force) => sum + force.x, 0),
    ).toBeCloseTo(0)
    expect(
      forces.incomingParent.y + forces.person.y
      + forces.outgoingParents.reduce((sum, force) => sum + force.y, 0),
    ).toBeCloseTo(0)
    expect(
      torque(incomingParent, forces.incomingParent)
      + torque(personPosition, forces.person)
      + forces.outgoingParents.reduce((sum, force, index) => (
        sum + torque(outgoingParents[index]!, force)
      ), 0),
    ).toBeCloseTo(0)
  })

  it('groups full siblings at a neutral shared-parent node and separates half siblings', () => {
    const father = person('father')
    const mother = person('mother')
    const otherMother = person('other-mother')
    const firstChild = person('first-child')
    const secondChild = person('second-child')
    const halfSibling = person('half-sibling')
    const network = buildBloodNetwork({
      people: [father, mother, otherMother, firstChild, secondChild, halfSibling],
      parentLinks: [
        parentLink(father.id, firstChild.id, 'father-first'),
        parentLink(mother.id, firstChild.id, 'mother-first'),
        parentLink(father.id, secondChild.id, 'father-second'),
        parentLink(mother.id, secondChild.id, 'mother-second'),
        parentLink(father.id, halfSibling.id, 'father-half'),
        parentLink(otherMother.id, halfSibling.id, 'other-mother-half'),
      ],
    })

    const fullSiblingFamily = network.nodes.find((node) => (
      node.id === 'blood-family:father:mother'
    ))
    const halfSiblingFamily = network.nodes.find((node) => (
      node.id === 'blood-family:father:other-mother'
    ))

    expect(fullSiblingFamily).toMatchObject({
      data: { childIds: ['first-child', 'second-child'] },
      height: 18,
      type: 'bloodFamily',
      width: 18,
    })
    expect(halfSiblingFamily).toMatchObject({
      data: { childIds: ['half-sibling'] },
      height: 18,
      type: 'bloodFamily',
      width: 18,
    })
    expect(network.nodes.filter((node) => node.type === 'bloodPerson')).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          height: 72,
          width: 72,
        }),
      ]),
    )
    expect(network.edges).toEqual(expect.arrayContaining([
      expect.objectContaining({
        data: {
          relation: 'familyToChild',
          sourceRadius: 9,
          targetRadius: 36,
        },
        source: 'blood-family:father:mother',
        target: 'first-child',
      }),
      expect.objectContaining({
        data: {
          relation: 'familyToChild',
          sourceRadius: 9,
          targetRadius: 36,
        },
        source: 'blood-family:father:mother',
        target: 'second-child',
      }),
      expect.objectContaining({
        data: {
          relation: 'familyToChild',
          sourceRadius: 9,
          targetRadius: 36,
        },
        source: 'blood-family:father:other-mother',
        target: 'half-sibling',
      }),
    ]))
    expect(network.edges).toEqual(expect.arrayContaining([
      expect.objectContaining({
        data: {
          relation: 'parentToFamily',
          sourceRadius: 36,
          targetRadius: 9,
        },
        source: 'father',
        target: 'blood-family:father:mother',
      }),
    ]))
  })

  it('uses only parent links and starts each temporary layout from random positions', () => {
    const parent = person('parent')
    const child = person('child')
    const spouse = person('spouse')
    const snapshot = {
      people: [parent, child, spouse],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    }

    const random = vi.spyOn(Math, 'random').mockReturnValue(0.25)
    const first = buildBloodNetwork(snapshot, 0)
    const second = buildBloodNetwork(snapshot, 1)
    const larger = buildBloodNetwork({
      people: Array.from({ length: 8 }, (_, index) => person(`person-${index}`)),
      parentLinks: [],
    })
    random.mockRestore()

    expect(first.nodes.map((node) => ({
      data: node.data,
      height: node.height,
      id: node.id,
      type: node.type,
      width: node.width,
    }))).toEqual(
      second.nodes.map((node) => ({
        data: node.data,
        height: node.height,
        id: node.id,
        type: node.type,
        width: node.width,
      })),
    )
    expect(first.edges).not.toEqual(expect.arrayContaining([
      expect.objectContaining({ source: spouse.id }),
      expect.objectContaining({ target: spouse.id }),
    ]))
    expect(first.nodes.every((node) => (
      Number.isFinite(node.position.x) && Number.isFinite(node.position.y)
    ))).toBe(true)
    expect(first.nodes.map((node) => node.position)).not.toEqual(
      second.nodes.map((node) => node.position),
    )
    const spreadAreaPerNode = (network: typeof first) => {
      const node = network.nodes[0]!
      const centerX = node.position.x + (node.width ?? 0) / 2
      const centerY = node.position.y + (node.height ?? 0) / 2
      return (Math.abs(centerX) * 2) * (Math.abs(centerY) * 2) / network.nodes.length
    }
    expect(spreadAreaPerNode(larger)).toBeCloseTo(spreadAreaPerNode(first))
  })

  it('moves the temporary nodes on every force tick', () => {
    const parent = person('parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [parent, child],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    })
    const simulation = createBloodNetworkSimulation(network)
    const initialPositions = simulation.getNodes().map((node) => node.position)
    simulation.tick()
    expect(simulation.getNodes().map((node) => node.position)).not.toEqual(
      initialPositions,
    )

    for (let tick = 0; tick < 1_100; tick += 1) {
      simulation.tick()
    }

    expect(simulation.getNodes().every((node) => (
      Number.isFinite(node.position.x) && Number.isFinite(node.position.y)
    ))).toBe(true)
    expect(simulation.getNodes().every((node) => (
      Math.abs(node.position.x) < 1_000 && Math.abs(node.position.y) < 1_000
    ))).toBe(true)
  })

  it('moves a dragged node immediately without persisting map coordinates', () => {
    const parent = person('parent')
    const child = person('child')
    const simulation = createBloodNetworkSimulation(buildBloodNetwork({
      people: [parent, child],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    }))

    simulation.moveNode(parent.id, { x: 720, y: -340 })

    expect(simulation.getNodes().find((node) => node.id === parent.id)?.position)
      .toEqual({ x: 720, y: -340 })
  })

  it('rotates every node around the selected node center', () => {
    const pivot = person('pivot')
    const other = person('other')
    const network = buildBloodNetwork({ people: [pivot, other], parentLinks: [] })
    const nodes = network.nodes.map((node) => ({
      ...node,
      position: node.id === pivot.id ? { x: 0, y: 0 } : { x: 100, y: 0 },
    }))

    const rotated = rotateBloodNetworkNodes(nodes, pivot.id, Math.PI / 2)

    expect(rotated.find((node) => node.id === pivot.id)?.position).toEqual({ x: 0, y: 0 })
    expect(rotated.find((node) => node.id === other.id)?.position.x).toBeCloseTo(0)
    expect(rotated.find((node) => node.id === other.id)?.position.y).toBeCloseTo(100)
  })

  it('keeps a missing pivot unchanged and respects family-node centers', () => {
    const firstParent = person('first-parent')
    const secondParent = person('second-parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [firstParent, secondParent, child],
      parentLinks: [
        parentLink(firstParent.id, child.id, 'first-parent-child'),
        parentLink(secondParent.id, child.id, 'second-parent-child'),
      ],
    })
    const family = network.nodes.find((node) => node.type === 'bloodFamily')!
    const rotated = rotateBloodNetworkNodes(network.nodes, family.id, Math.PI / 2)

    expect(rotateBloodNetworkNodes(network.nodes, 'missing', 1)).toBe(network.nodes)
    expect(rotated.find((node) => node.id === family.id)?.position)
      .toEqual(family.position)
    expect(rotated).not.toEqual(network.nodes)
  })

  it('keeps a dragged node under the pointer while forces move the graph', () => {
    const parent = person('parent')
    const child = person('child')
    const simulation = createBloodNetworkSimulation(buildBloodNetwork({
      people: [parent, child],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    }))
    const pointerPosition = { x: 720, y: -340 }

    simulation.setNodeDragging(parent.id, true)
    simulation.moveNode(parent.id, pointerPosition)
    const beforeTick = simulation.getNodes()
    simulation.tick()
    const afterTick = simulation.getNodes()

    expect(afterTick.find((node) => node.id === parent.id)?.position).toEqual(pointerPosition)
    expect(afterTick.find((node) => node.id === child.id)?.position)
      .not.toEqual(beforeTick.find((node) => node.id === child.id)?.position)
  })

  it('measures the movement of every temporary node between rendered frames', () => {
    const network = buildBloodNetwork({
      people: [person('parent'), person('child')],
      parentLinks: [parentLink('parent', 'child', 'parent-child')],
    })
    const shiftedNodes = network.nodes.map((node) => ({
      ...node,
      position: { x: node.position.x + 3, y: node.position.y + 4 },
    }))

    expect(bloodNetworkAverageNodeMovement(network.nodes, shiftedNodes)).toBe(5)
  })

  it('moves a child from the parent side toward the descendant side', () => {
    const firstParent = person('first-parent')
    const secondParent = person('second-parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [firstParent, secondParent, child],
      parentLinks: [
        parentLink(firstParent.id, child.id, 'first-child'),
        parentLink(secondParent.id, child.id, 'second-child'),
      ],
    })
    const sharedParentNode = network.nodes.find((node) => node.type === 'bloodFamily')!
    const positions = new Map([
      [firstParent.id, { x: -36, y: -36 }],
      [secondParent.id, { x: -36, y: 164 }],
      [sharedParentNode.id, { x: 191, y: 91 }],
      [child.id, { x: 44, y: 36 }],
    ])
    const positionedNetwork = {
      ...network,
      nodes: network.nodes.map((node) => {
        const position = positions.get(node.id)
        return position ? { ...node, position } : node
      }),
    }
    const simulation = createBloodNetworkSimulation(positionedNetwork, {
      averageMovementPerSecondThreshold: 1,
      baseRepulsion: 0,
      descendantSideForce: 12,
      kinshipRepulsionStep: 0,
      parentHingeSpringStrength: 0,
      parentHingeTargetAngle: 45,
      parentPairSpringStrength: 0,
      personFlowHingeStrength: 0,
      sharedParentSpringStrength: 0,
      unrelatedParentNodeRepulsion: 0,
    })
    const descendantAlignment = (nodes: ReturnType<typeof simulation.getNodes>) => {
      const center = (id: string, size: number) => {
        const node = nodes.find((candidate) => candidate.id === id)!
        return { x: node.position.x + size / 2, y: node.position.y + size / 2 }
      }
      const first = center(firstParent.id, 72)
      const second = center(secondParent.id, 72)
      const shared = center(sharedParentNode.id, 18)
      const childCenter = center(child.id, 72)
      const descendant = { x: shared.x - (first.x + second.x) / 2, y: shared.y - (first.y + second.y) / 2 }
      const childVector = { x: childCenter.x - shared.x, y: childCenter.y - shared.y }
      return (descendant.x * childVector.x + descendant.y * childVector.y)
        / (Math.hypot(descendant.x, descendant.y) * Math.hypot(childVector.x, childVector.y))
    }
    const initialAlignment = descendantAlignment(simulation.getNodes())

    for (let tick = 0; tick < 25; tick += 1) {
      simulation.tick()
    }

    expect(descendantAlignment(simulation.getNodes())).toBeGreaterThan(initialAlignment)
  })

  it('aligns a person between the incoming and outgoing blood arrows', () => {
    const grandparent = person('grandparent')
    const parent = person('parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [grandparent, parent, child],
      parentLinks: [
        parentLink(grandparent.id, parent.id, 'grandparent-parent'),
        parentLink(parent.id, child.id, 'parent-child'),
      ],
    })
    const incomingParentNode = network.nodes.find((node) => (
      node.type === 'bloodFamily' && node.data.childIds.includes(parent.id)
    ))!
    const outgoingParentNode = network.nodes.find((node) => (
      node.type === 'bloodFamily' && node.data.parentIds.includes(parent.id)
    ))!
    const positions = new Map([
      [grandparent.id, { x: -36, y: -36 }],
      [incomingParentNode.id, { x: 91, y: -9 }],
      [parent.id, { x: 164, y: 64 }],
      [outgoingParentNode.id, { x: 341, y: 91 }],
      [child.id, { x: 414, y: 64 }],
    ])
    const positionedNetwork = {
      ...network,
      nodes: network.nodes.map((node) => {
        const position = positions.get(node.id)
        return position ? { ...node, position } : node
      }),
    }
    const simulation = createBloodNetworkSimulation(positionedNetwork, {
      averageMovementPerSecondThreshold: 1,
      baseRepulsion: 0,
      descendantSideForce: 0,
      kinshipRepulsionStep: 0,
      parentHingeSpringStrength: 0,
      parentHingeTargetAngle: 45,
      parentPairSpringStrength: 0,
      personFlowHingeStrength: 8,
      sharedParentSpringStrength: 0,
      unrelatedParentNodeRepulsion: 0,
    })
    const flowAlignment = (nodes: ReturnType<typeof simulation.getNodes>) => {
      const center = (id: string, size: number) => {
        const node = nodes.find((candidate) => candidate.id === id)!
        return { x: node.position.x + size / 2, y: node.position.y + size / 2 }
      }
      const incoming = center(incomingParentNode.id, 18)
      const currentParent = center(parent.id, 72)
      const outgoing = center(outgoingParentNode.id, 18)
      const incomingVector = { x: currentParent.x - incoming.x, y: currentParent.y - incoming.y }
      const outgoingVector = { x: outgoing.x - currentParent.x, y: outgoing.y - currentParent.y }
      return (incomingVector.x * outgoingVector.x + incomingVector.y * outgoingVector.y)
        / (Math.hypot(incomingVector.x, incomingVector.y) * Math.hypot(outgoingVector.x, outgoingVector.y))
    }
    const initialAlignment = flowAlignment(simulation.getNodes())

    for (let tick = 0; tick < 25; tick += 1) {
      simulation.tick()
    }

    expect(flowAlignment(simulation.getNodes())).toBeGreaterThan(initialAlignment)
  })

  it('repels only people not directly connected to a parent node', () => {
    const parent = person('parent')
    const child = person('child')
    const intruder = person('intruder')
    const network = buildBloodNetwork({
      people: [parent, child, intruder],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    })
    const parentNode = network.nodes.find((node) => node.type === 'bloodFamily')!
    const positions = new Map([
      [parent.id, { x: -536, y: -36 }],
      [child.id, { x: 124, y: -36 }],
      [intruder.id, { x: -36, y: 64 }],
      [parentNode.id, { x: -9, y: -9 }],
    ])
    const positionedNetwork = {
      ...network,
      nodes: network.nodes.map((node) => {
        const position = positions.get(node.id)
        return position ? { ...node, position } : node
      }),
    }
    const simulation = createBloodNetworkSimulation(positionedNetwork, {
      baseRepulsion: 0,
      descendantSideForce: 0,
      kinshipRepulsionStep: 0,
      parentHingeSpringStrength: 0,
      parentHingeTargetAngle: 45,
      parentPairSpringStrength: 0,
      personFlowHingeStrength: 0,
      sharedParentSpringStrength: 0,
      unrelatedParentNodeRepulsion: 12,
      averageMovementPerSecondThreshold: 1,
    })

    simulation.tick()

    const nodes = new Map(simulation.getNodes().map((node) => [node.id, node]))
    expect(nodes.get(child.id)!.position).toEqual({ x: 124, y: -36 })
    expect(nodes.get(intruder.id)!.position.y).toBeGreaterThan(64)
  })

  it('widens an acute angle at a shared parent node', () => {
    const firstParent = person('first-parent')
    const secondParent = person('second-parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [firstParent, secondParent, child],
      parentLinks: [
        parentLink(firstParent.id, child.id, 'first-child'),
        parentLink(secondParent.id, child.id, 'second-child'),
      ],
    })
    const sharedParentNode = network.nodes.find((node) => node.type === 'bloodFamily')!
    const positions = new Map([
      [firstParent.id, { x: 254, y: -114 }],
      [secondParent.id, { x: 254, y: 42 }],
      [child.id, { x: 964, y: -36 }],
      [sharedParentNode.id, { x: -9, y: -9 }],
    ])
    const positionedNetwork = {
      ...network,
      nodes: network.nodes.map((node) => {
        const position = positions.get(node.id)
        return position ? { ...node, position } : node
      }),
    }
    const angleAtParentNode = (nodes: ReturnType<typeof simulation.getNodes>) => {
      const center = (id: string, size: number) => {
        const node = nodes.find((candidate) => candidate.id === id)!
        return { x: node.position.x + size / 2, y: node.position.y + size / 2 }
      }
      const pivot = center(sharedParentNode.id, 18)
      const first = center(firstParent.id, 72)
      const second = center(secondParent.id, 72)
      const firstLength = Math.hypot(first.x - pivot.x, first.y - pivot.y)
      const secondLength = Math.hypot(second.x - pivot.x, second.y - pivot.y)
      const dot = ((first.x - pivot.x) * (second.x - pivot.x)
        + (first.y - pivot.y) * (second.y - pivot.y)) / (firstLength * secondLength)
      return Math.acos(Math.max(-1, Math.min(1, dot)))
    }
    const simulation = createBloodNetworkSimulation(positionedNetwork, {
      baseRepulsion: 0,
      descendantSideForce: 0,
      kinshipRepulsionStep: 0,
      parentHingeSpringStrength: 4,
      parentHingeTargetAngle: 60,
      parentPairSpringStrength: 0,
      personFlowHingeStrength: 0,
      sharedParentSpringStrength: 0,
      unrelatedParentNodeRepulsion: 0,
      averageMovementPerSecondThreshold: 1,
    })
    const initialAngle = angleAtParentNode(simulation.getNodes())

    for (let tick = 0; tick < 10; tick += 1) {
      simulation.tick()
    }

    expect(angleAtParentNode(simulation.getNodes())).toBeGreaterThan(initialAngle)
  })

  it('keeps a 101-person graph bounded during force simulation', () => {
    const people = Array.from({ length: 101 }, (_, index) => person(`person-${index}`))
    const parentLinks = people.slice(24).flatMap((child, index) => [
      parentLink(people[index % 12]!.id, child.id, `first-parent-${index}`),
      parentLink(people[12 + (index % 12)]!.id, child.id, `second-parent-${index}`),
    ])
    let seed = 0x12345678
    const random = vi.spyOn(Math, 'random').mockImplementation(() => {
      seed = (Math.imul(seed, 1_664_525) + 1_013_904_223) >>> 0
      return seed / 0x1_0000_0000
    })
    const network = buildBloodNetwork({ people, parentLinks })
    random.mockRestore()
    const simulation = createBloodNetworkSimulation(network)

    for (let tick = 0; tick < 300; tick += 1) {
      simulation.tick()
    }

    const greatestCoordinate = Math.max(...simulation.getNodes().flatMap((node) => [
      Math.abs(node.position.x),
      Math.abs(node.position.y),
    ]))
    expect(greatestCoordinate).toBeLessThan(5_000)
    expect(simulation.getNodes().every((node) => (
      Number.isFinite(node.position.x)
      && Number.isFinite(node.position.y)
      && Math.abs(node.position.x) < 5_000
      && Math.abs(node.position.y) < 5_000
    ))).toBe(true)
  })

  it('does not weaken spring forces as the simulation runs', () => {
    const parent = person('parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [parent, child],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    })
    const positionedNetwork = {
      ...network,
      nodes: network.nodes.map((node) => ({
        ...node,
        position: node.id === parent.id
          ? { x: 0, y: 0 }
          : node.type === 'bloodFamily'
            ? { x: 50_000, y: 0 }
            : { x: 100_000, y: 0 },
      })),
    }
    const simulation = createBloodNetworkSimulation(positionedNetwork, {
      averageMovementPerSecondThreshold: 1,
      baseRepulsion: 0,
      descendantSideForce: 0,
      kinshipRepulsionStep: 0,
      parentHingeSpringStrength: 0,
      parentHingeTargetAngle: 45,
      parentPairSpringStrength: 0,
      personFlowHingeStrength: 0,
      sharedParentSpringStrength: 1,
      unrelatedParentNodeRepulsion: 0,
    })

    for (let tick = 0; tick < 250; tick += 1) {
      simulation.tick()
    }
    const before = simulation.getNodes().find((node) => node.id === parent.id)!
    simulation.tick()
    const after = simulation.getNodes().find((node) => node.id === parent.id)!

    expect(after.position.x - before.position.x).toBeGreaterThan(10)
  })
})
