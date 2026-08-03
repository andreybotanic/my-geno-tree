import { describe, expect, it, vi } from 'vitest'
import type { ParentLink, Person } from './api/generated'
import {
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

  it('moves the temporary nodes on every force tick and eventually settles', () => {
    const parent = person('parent')
    const child = person('child')
    const network = buildBloodNetwork({
      people: [parent, child],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    })
    const simulation = createBloodNetworkSimulation(network)
    const initialPositions = simulation.getNodes().map((node) => node.position)
    expect(simulation.tick()).toBe(true)
    expect(simulation.getNodes().map((node) => node.position)).not.toEqual(
      initialPositions,
    )

    let running = true
    for (let tick = 0; tick < 1_100 && running; tick += 1) {
      running = simulation.tick()
    }

    expect(running).toBe(false)
    expect(simulation.getNodes().every((node) => (
      Number.isFinite(node.position.x) && Number.isFinite(node.position.y)
    ))).toBe(true)
    expect(simulation.getNodes().every((node) => (
      Math.abs(node.position.x) < 1_000 && Math.abs(node.position.y) < 1_000
    ))).toBe(true)
  })

  it('settles a 101-person graph without letting the layout escape', () => {
    const people = Array.from({ length: 101 }, (_, index) => person(`person-${index}`))
    const parentLinks = people.slice(24).flatMap((child, index) => [
      parentLink(people[index % 12]!.id, child.id, `first-parent-${index}`),
      parentLink(people[12 + (index % 12)]!.id, child.id, `second-parent-${index}`),
    ])
    const simulation = createBloodNetworkSimulation(buildBloodNetwork({
      people,
      parentLinks,
    }))

    let running = true
    for (let tick = 0; tick < 1_100 && running; tick += 1) {
      running = simulation.tick()
    }

    expect(running).toBe(false)
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
