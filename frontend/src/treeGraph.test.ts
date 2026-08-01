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
        bracketDepth: 24,
        married: true,
        sourceOffset: 0,
        targetOffset: 0,
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

  it('compacts surviving root branches after a deletion', () => {
    const tree = snapshot([
      person('left-root', 0, 0),
      person('removed-root', 320, 0),
      person('remaining-root', 1920, 0),
    ])
    const afterDeletion = {
      people: tree.people.filter((person) => person.id !== 'removed-root'),
      parentLinks: tree.parentLinks,
    }

    expect(reflowTree(afterDeletion, { compact: true })).toEqual([
      { personId: 'remaining-root', coordinates: { x: 320, y: 0 } },
    ])
  })

  it('keeps separate family blocks from interleaving on one generation row', () => {
    const tree = snapshot(
      [
        person('husband-1', 0, 0),
        person('wife-2', 320, 0),
        person('wife-1', 640, 0),
        person('husband-2', 1600, 0),
        person('child-1', 320, 240),
        person('child-2', 960, 240),
        person('child-3', 1280, 240),
      ],
      [
        { id: 'h1-child', parentId: 'husband-1', childId: 'child-1', role: 'father' },
        { id: 'w1-child', parentId: 'wife-1', childId: 'child-1', role: 'mother' },
        { id: 'h2-child-1', parentId: 'husband-2', childId: 'child-2', role: 'father' },
        { id: 'w2-child-1', parentId: 'wife-2', childId: 'child-2', role: 'mother' },
        { id: 'h2-child-2', parentId: 'husband-2', childId: 'child-3', role: 'father' },
        { id: 'w2-child-2', parentId: 'wife-2', childId: 'child-3', role: 'mother' },
      ],
      [{
        id: 'first-marriage',
        malePersonId: 'husband-1',
        femalePersonId: 'wife-1',
        marriageDate: { state: 'unknown' },
        dissolutionDate: null,
        createdAt: '2026-07-31T00:00:00+00:00',
        updatedAt: '2026-07-31T00:00:00+00:00',
      }],
    )

    expect(reflowTree(tree)).toEqual([
      { personId: 'wife-2', coordinates: { x: 640, y: 0 } },
      { personId: 'wife-1', coordinates: { x: 320, y: 0 } },
      { personId: 'child-1', coordinates: { x: 160, y: 240 } },
    ])
  })

  it('separates multiple partner brackets at their shared person', () => {
    const tree = snapshot(
      [
        person('centre', 0, 0),
        person('near-partner', 320, 0),
        person('far-partner', 640, 0),
      ],
      [],
      [
        {
          id: 'near-marriage',
          malePersonId: 'centre',
          femalePersonId: 'near-partner',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
        {
          id: 'far-marriage',
          malePersonId: 'centre',
          femalePersonId: 'far-partner',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
      ],
    )

    const edges = new Map(buildTreeEdges(tree).map((edge) => [edge.id, edge]))
    expect(edges.get('family:centre:near-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 42, sourceOffset: 16 }),
      }),
    )
    expect(edges.get('family:centre:far-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 24, sourceOffset: 34 }),
      }),
    )
  })

  it('offsets partner brackets to opposite sides of their shared person', () => {
    const tree = snapshot(
      [
        person('left-partner', -320, 0),
        person('centre', 0, 0),
        person('right-partner', 320, 0),
      ],
      [],
      [
        {
          id: 'left-marriage',
          malePersonId: 'left-partner',
          femalePersonId: 'centre',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
        {
          id: 'right-marriage',
          malePersonId: 'centre',
          femalePersonId: 'right-partner',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
      ],
    )

    const edges = new Map(buildTreeEdges(tree).map((edge) => [edge.id, edge]))
    expect(edges.get('family:centre:left-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ targetOffset: -16 }),
      }),
    )
    expect(edges.get('family:centre:right-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ sourceOffset: 16 }),
      }),
    )
  })

  it('keeps the nearest bracket centred for an odd number of partners', () => {
    const tree = snapshot(
      [
        person('left-partner', -640, 0),
        person('centre', 0, 0),
        person('near-partner', 320, 0),
        person('far-partner', 640, 0),
      ],
      [],
      [
        {
          id: 'left-marriage',
          malePersonId: 'left-partner',
          femalePersonId: 'centre',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
        {
          id: 'near-marriage',
          malePersonId: 'centre',
          femalePersonId: 'near-partner',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
        {
          id: 'far-marriage',
          malePersonId: 'centre',
          femalePersonId: 'far-partner',
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
          createdAt: '2026-07-31T00:00:00+00:00',
          updatedAt: '2026-07-31T00:00:00+00:00',
        },
      ],
    )

    const edges = new Map(buildTreeEdges(tree).map((edge) => [edge.id, edge]))
    expect(edges.get('family:centre:near-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ sourceOffset: 0 }),
      }),
    )
    expect(edges.get('family:centre:left-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ targetOffset: -16 }),
      }),
    )
    expect(edges.get('family:centre:far-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ sourceOffset: 16 }),
      }),
    )
  })
})
