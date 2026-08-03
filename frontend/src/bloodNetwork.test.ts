import { describe, expect, it, vi } from 'vitest'
import type { ParentLink, Person } from './api/generated'
import {
  bloodNetworkAverageNodeMovement,
  bloodNetworkNodeMass,
  bloodNetworkPointInTriangle,
  bloodNetworkRepulsionMultiplier,
  buildBloodNetwork,
  createBloodNetworkSimulation,
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

  it('keeps family routing nodes lighter than connected people', () => {
    expect(bloodNetworkNodeMass('bloodFamily', 20)).toBe(1)
    expect(bloodNetworkNodeMass('bloodPerson', 1)).toBe(2)
    expect(bloodNetworkNodeMass('bloodPerson', 4)).toBe(5)
  })

  it('recognizes only points inside a parent triangle', () => {
    const firstParent = { x: 0, y: 0 }
    const secondParent = { x: 10, y: 0 }
    const sharedParentNode = { x: 5, y: 10 }

    expect(bloodNetworkPointInTriangle(
      { x: 5, y: 4 },
      firstParent,
      secondParent,
      sharedParentNode,
    )).toBe(true)
    expect(bloodNetworkPointInTriangle(
      { x: 11, y: 4 },
      firstParent,
      secondParent,
      sharedParentNode,
    )).toBe(false)
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
          handles: expect.arrayContaining([
            expect.objectContaining({ id: 'network-source', type: 'source', x: 36, y: 36 }),
            expect.objectContaining({ id: 'network-target', type: 'target', x: 36, y: 36 }),
          ]),
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
        sourceHandle: 'network-source',
        target: 'first-child',
        targetHandle: 'network-target',
      }),
      expect.objectContaining({
        data: {
          relation: 'familyToChild',
          sourceRadius: 9,
          targetRadius: 36,
        },
        source: 'blood-family:father:mother',
        sourceHandle: 'network-source',
        target: 'second-child',
        targetHandle: 'network-target',
      }),
      expect.objectContaining({
        data: {
          relation: 'familyToChild',
          sourceRadius: 9,
          targetRadius: 36,
        },
        source: 'blood-family:father:other-mother',
        sourceHandle: 'network-source',
        target: 'half-sibling',
        targetHandle: 'network-target',
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
      handles: node.handles,
      height: node.height,
      id: node.id,
      type: node.type,
      width: node.width,
    }))).toEqual(
      second.nodes.map((node) => ({
        data: node.data,
        handles: node.handles,
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

  it('expels a non-family node from inside a parent triangle', () => {
    const firstParent = person('first-parent')
    const secondParent = person('second-parent')
    const child = person('child')
    const intruder = person('intruder')
    const network = buildBloodNetwork({
      people: [firstParent, secondParent, child, intruder],
      parentLinks: [
        parentLink(firstParent.id, child.id, 'first-child'),
        parentLink(secondParent.id, child.id, 'second-child'),
      ],
    })
    const sharedParentNode = network.nodes.find((node) => (
      node.type === 'bloodFamily'
    ))!
    const positions = new Map([
      [firstParent.id, { x: -36, y: -36 }],
      [secondParent.id, { x: 164, y: -36 }],
      [sharedParentNode.id, { x: 91, y: 191 }],
      [intruder.id, { x: 64, y: 31 }],
    ])
    const positionedNetwork = {
      ...network,
      nodes: network.nodes.map((node) => {
        const position = positions.get(node.id)
        return position ? { ...node, position } : node
      }),
    }
    expect(bloodNetworkPointInTriangle(
      { x: 100, y: 67 },
      { x: 0, y: 0 },
      { x: 200, y: 0 },
      { x: 100, y: 200 },
    )).toBe(true)
    const simulation = createBloodNetworkSimulation(positionedNetwork, {
      baseRepulsion: 0,
      kinshipRepulsionStep: 0,
      parentPairSpringStrength: 0,
      sharedParentSpringStrength: 0,
      averageMovementPerSecondThreshold: 1,
    })

    for (let tick = 0; tick < 20; tick += 1) {
      simulation.tick()
    }

    const expelledIntruder = simulation.getNodes().find((node) => node.id === intruder.id)!
    const intruderCenter = {
      x: expelledIntruder.position.x + 36,
      y: expelledIntruder.position.y + 36,
    }
    expect(bloodNetworkPointInTriangle(
      intruderCenter,
      { x: 0, y: 0 },
      { x: 200, y: 0 },
      { x: 100, y: 200 },
    )).toBe(false)
  })

  it('keeps a 101-person graph bounded during force simulation', () => {
    const people = Array.from({ length: 101 }, (_, index) => person(`person-${index}`))
    const parentLinks = people.slice(24).flatMap((child, index) => [
      parentLink(people[index % 12]!.id, child.id, `first-parent-${index}`),
      parentLink(people[12 + (index % 12)]!.id, child.id, `second-parent-${index}`),
    ])
    const simulation = createBloodNetworkSimulation(buildBloodNetwork({
      people,
      parentLinks,
    }))

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
})
