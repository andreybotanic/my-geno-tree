import { describe, expect, it } from 'vitest'
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
      type: 'bloodFamily',
    })
    expect(halfSiblingFamily).toMatchObject({
      data: { childIds: ['half-sibling'] },
      type: 'bloodFamily',
    })
    expect(network.edges).toEqual(expect.arrayContaining([
      expect.objectContaining({ source: 'blood-family:father:mother', target: 'first-child' }),
      expect.objectContaining({ source: 'blood-family:father:mother', target: 'second-child' }),
      expect.objectContaining({ source: 'blood-family:father:other-mother', target: 'half-sibling' }),
    ]))
  })

  it('uses only parent links and produces the same temporary layout every time', () => {
    const parent = person('parent')
    const child = person('child')
    const spouse = person('spouse')
    const snapshot = {
      people: [parent, child, spouse],
      parentLinks: [parentLink(parent.id, child.id, 'parent-child')],
    }

    const first = buildBloodNetwork(snapshot)
    const second = buildBloodNetwork(snapshot)

    expect(first).toEqual(second)
    expect(first.edges).not.toEqual(expect.arrayContaining([
      expect.objectContaining({ source: spouse.id }),
      expect.objectContaining({ target: spouse.id }),
    ]))
    expect(first.nodes.every((node) => (
      Number.isFinite(node.position.x) && Number.isFinite(node.position.y)
    ))).toBe(true)
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
    for (let tick = 0; tick < 700 && running; tick += 1) {
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
})
