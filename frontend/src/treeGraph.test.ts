import { describe, expect, it } from 'vitest'
import type { Person, TreeSnapshot } from './api/generated'
import {
  buildTreeEdges,
  newChildPlacement,
  newRelativeCoordinates,
  reflowTree,
} from './treeGraph'

const person = (id: string, x: number, y: number): Person => ({
  id,
  birthSurname: { state: 'unknown' },
  givenName: { state: 'unknown' },
  patronymic: { state: 'unknown' },
  gender: 'unknown',
  birthDate: { state: 'unknown' },
  deathDate: null,
  photoThumbnailUrl: null,
  coordinates: { x, y },
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
})

const snapshot = (
  people: Person[],
  parentLinks: TreeSnapshot['parentLinks'] = [],
  marriages: TreeSnapshot['marriages'] = [],
): TreeSnapshot => ({
  tree: {
    id: 'tree',
    name: 'Family',
    createdAt: '2026-07-31T00:00:00+00:00',
    updatedAt: '2026-07-31T00:00:00+00:00',
  },
  etag: '"tree"',
  people,
  parentLinks,
  marriages,
})

describe('tree graph presentation', () => {
  it('consolidates two parents, a marriage, and children into one family edge', () => {
    const tree = snapshot(
      [
        person('father', 0, 0),
        person('mother', 280, 0),
        person('child-1', 0, 240),
        person('child-2', 280, 240),
      ],
      [
        { id: 'f1', parentId: 'father', childId: 'child-1', role: 'father' },
        { id: 'm1', parentId: 'mother', childId: 'child-1', role: 'mother' },
        { id: 'f2', parentId: 'father', childId: 'child-2', role: 'father' },
        { id: 'm2', parentId: 'mother', childId: 'child-2', role: 'mother' },
      ],
      [{
        id: 'marriage',
        malePersonId: 'father',
        femalePersonId: 'mother',
        marriageDate: { state: 'unknown' },
        dissolutionDate: null,
        createdAt: '2026-07-31T00:00:00+00:00',
        updatedAt: '2026-07-31T00:00:00+00:00',
      }],
    )

    expect(buildTreeEdges(tree)).toEqual([expect.objectContaining({
      id: 'family:father:mother',
      sourceHandle: 'bottom-source',
      targetHandle: 'bottom-target',
      type: 'family',
      data: {
        married: true,
        childPoints: [
          { id: 'child-1', topX: 130, topY: 240 },
          { id: 'child-2', topX: 410, topY: 240 },
        ],
      },
    })])
  })

  it('distinguishes unmarried co-parents and a single parent', () => {
    const tree = snapshot(
      [
        person('father', 0, 0),
        person('mother', 280, 0),
        person('shared', 140, 240),
        person('single', 420, 240),
      ],
      [
        { id: 'f1', parentId: 'father', childId: 'shared', role: 'father' },
        { id: 'm1', parentId: 'mother', childId: 'shared', role: 'mother' },
        { id: 'f2', parentId: 'father', childId: 'single', role: 'father' },
      ],
    )

    expect(buildTreeEdges(tree)).toEqual([
      expect.objectContaining({
        id: 'family:father:mother',
        data: expect.objectContaining({ married: false }),
      }),
      expect.objectContaining({
        id: 'parent:f2',
        type: 'singleParent',
      }),
    ])
  })

  it('places relatives by generation without overlapping existing cards', () => {
    const tree = snapshot([
      person('anchor', 0, 0),
      person('occupied', 280, 0),
      person('child', 0, 240),
    ])

    expect(newRelativeCoordinates(tree, 'anchor', 'father')).toEqual({
      x: -160,
      y: -240,
    })
    expect(newRelativeCoordinates(tree, 'anchor', 'spouse')).toEqual({
      x: 640,
      y: 0,
    })
    expect(newRelativeCoordinates(tree, 'missing', 'child')).toEqual({
      x: 0,
      y: 0,
    })
  })

  it('re-centres a couple’s children when a sibling is added', () => {
    const tree = snapshot(
      [
        person('father', 0, 0),
        person('mother', 320, 0),
        person('first-child', 160, 240),
      ],
      [
        { id: 'father-link', parentId: 'father', childId: 'first-child', role: 'father' },
        { id: 'mother-link', parentId: 'mother', childId: 'first-child', role: 'mother' },
      ],
    )

    expect(newChildPlacement(tree, ['father', 'mother'])).toEqual({
      coordinates: { x: 320, y: 240 },
      coordinateUpdates: [{
        personId: 'first-child',
        coordinates: { x: 0, y: 240 },
      }],
    })
  })

  it('always centres a child block on its parents', () => {
    const tree = snapshot([
      person('father', 0, 0),
      person('mother', 320, 0),
      person('other-family-child', 160, 240),
    ])

    expect(newChildPlacement(tree, ['father', 'mother'])).toEqual({
      coordinates: { x: 160, y: 240 },
      coordinateUpdates: [],
    })
  })

  it('cascades a parent movement through every descendant family', () => {
    const tree = snapshot(
      [
        person('grandfather', 0, 0),
        person('grandmother', 320, 0),
        person('father', 160, 240),
        person('mother', 480, 240),
        person('first-child', 0, 480),
        person('second-child', 320, 480),
      ],
      [
        { id: 'grandfather-father', parentId: 'grandfather', childId: 'father', role: 'father' },
        { id: 'grandmother-father', parentId: 'grandmother', childId: 'father', role: 'mother' },
        { id: 'father-first', parentId: 'father', childId: 'first-child', role: 'father' },
        { id: 'mother-first', parentId: 'mother', childId: 'first-child', role: 'mother' },
        { id: 'father-second', parentId: 'father', childId: 'second-child', role: 'father' },
        { id: 'mother-second', parentId: 'mother', childId: 'second-child', role: 'mother' },
      ],
    )

    expect(reflowTree(tree)).toEqual([
      { personId: 'first-child', coordinates: { x: 160, y: 480 } },
      { personId: 'second-child', coordinates: { x: 480, y: 480 } },
    ])
  })

  it('separates colliding branches without losing family centring', () => {
    const tree = snapshot(
      [
        person('left-parent', 0, 0),
        person('right-parent', 320, 0),
        person('left-child', 160, 240),
        person('independent-parent', 320, 0),
        person('independent-child', 320, 240),
      ],
      [
        { id: 'left-link', parentId: 'left-parent', childId: 'left-child', role: 'father' },
        { id: 'right-link', parentId: 'right-parent', childId: 'left-child', role: 'mother' },
        { id: 'independent-link', parentId: 'independent-parent', childId: 'independent-child', role: 'father' },
      ],
    )

    const positions = new Map(reflowTree(tree).map((update) => [
      update.personId,
      update.coordinates,
    ]))
    expect(positions.get('independent-parent')).toEqual({ x: 640, y: 0 })
    expect(positions.get('independent-child')).toEqual({ x: 640, y: 240 })
    expect(positions.get('left-child')).toBeUndefined()
  })
})
