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

  it('compacts a parent pair around the centre of its shared child block', () => {
    const tree = snapshot(
      [
        person('mother', 0, 0),
        person('father', 640, 0),
        person('first-child', 0, 240),
        person('second-child', 320, 240),
        person('third-child', 640, 240),
      ],
      [
        { id: 'mother-first', parentId: 'mother', childId: 'first-child', role: 'mother' },
        { id: 'father-first', parentId: 'father', childId: 'first-child', role: 'father' },
        { id: 'mother-second', parentId: 'mother', childId: 'second-child', role: 'mother' },
        { id: 'father-second', parentId: 'father', childId: 'second-child', role: 'father' },
        { id: 'mother-third', parentId: 'mother', childId: 'third-child', role: 'mother' },
        { id: 'father-third', parentId: 'father', childId: 'third-child', role: 'father' },
      ],
    )

    expect(reflowTree(tree)).toEqual([
      { personId: 'father', coordinates: { x: 320, y: 0 } },
      { personId: 'first-child', coordinates: { x: -160, y: 240 } },
      { personId: 'second-child', coordinates: { x: 160, y: 240 } },
      { personId: 'third-child', coordinates: { x: 480, y: 240 } },
    ])
  })

  it('balances a shared parent between adjacent spouses without reducing half-sibling gaps', () => {
    const tree = snapshot(
      [
        person('first-spouse', -320, -240),
        person('shared-parent', 320, -240),
        person('second-spouse', 640, -240),
        person('third-spouse', 1680, -240),
        person('first-child', 0, 0),
        person('second-child', 480, 0),
        person('third-child', 1000, 0),
      ],
      [
        { id: 'first-parent', parentId: 'first-spouse', childId: 'first-child', role: 'father' },
        { id: 'shared-first', parentId: 'shared-parent', childId: 'first-child', role: 'mother' },
        { id: 'shared-second', parentId: 'shared-parent', childId: 'second-child', role: 'mother' },
        { id: 'second-parent', parentId: 'second-spouse', childId: 'second-child', role: 'father' },
        { id: 'shared-third', parentId: 'shared-parent', childId: 'third-child', role: 'mother' },
        { id: 'third-parent', parentId: 'third-spouse', childId: 'third-child', role: 'father' },
      ],
    )

    expect(reflowTree(tree)).toEqual([
      { personId: 'shared-parent', coordinates: { x: 60, y: -240 } },
      { personId: 'second-spouse', coordinates: { x: 440, y: -240 } },
      { personId: 'third-spouse', coordinates: { x: 1200, y: -240 } },
      { personId: 'first-child', coordinates: { x: -130, y: 0 } },
      { personId: 'second-child', coordinates: { x: 250, y: 0 } },
      { personId: 'third-child', coordinates: { x: 630, y: 0 } },
    ])
  })

  it('keeps an unrelated branch out of the horizontal span of another family', () => {
    const tree = snapshot(
      [
        person('first-spouse', -320, -240),
        person('shared-parent', 320, -240),
        person('second-spouse', 640, -240),
        person('third-spouse', 1680, -240),
        person('other-parent-1', 2520, -240),
        person('other-parent-2', 2840, -240),
        person('first-child', 0, 0),
        person('second-child', 480, 0),
        person('third-child', 1000, 0),
        person('other-child-1', 2360, 0),
        person('other-child-2', 2680, 0),
        person('other-child-3', 3000, 0),
      ],
      [
        { id: 'first-parent', parentId: 'first-spouse', childId: 'first-child', role: 'father' },
        { id: 'shared-first', parentId: 'shared-parent', childId: 'first-child', role: 'mother' },
        { id: 'shared-second', parentId: 'shared-parent', childId: 'second-child', role: 'mother' },
        { id: 'second-parent', parentId: 'second-spouse', childId: 'second-child', role: 'father' },
        { id: 'shared-third', parentId: 'shared-parent', childId: 'third-child', role: 'mother' },
        { id: 'third-parent', parentId: 'third-spouse', childId: 'third-child', role: 'father' },
        { id: 'other-parent-1-link', parentId: 'other-parent-1', childId: 'other-child-1', role: 'mother' },
        { id: 'other-parent-2-link', parentId: 'other-parent-2', childId: 'other-child-1', role: 'father' },
        { id: 'other-parent-1-link-2', parentId: 'other-parent-1', childId: 'other-child-2', role: 'mother' },
        { id: 'other-parent-2-link-2', parentId: 'other-parent-2', childId: 'other-child-2', role: 'father' },
        { id: 'other-parent-1-link-3', parentId: 'other-parent-1', childId: 'other-child-3', role: 'mother' },
        { id: 'other-parent-2-link-3', parentId: 'other-parent-2', childId: 'other-child-3', role: 'father' },
      ],
    )

    const positions = new Map(reflowTree(tree).map((update) => [
      update.personId,
      update.coordinates,
    ]))
    expect(positions.get('other-parent-1')).toEqual({ x: 1840, y: -240 })
    expect(positions.get('other-parent-2')).toEqual({ x: 2160, y: -240 })
    expect(positions.get('other-child-1')).toEqual({ x: 1680, y: 0 })
  })

  it('reflows the compacted werwer branch without crossing unrelated generations', () => {
    const tree = snapshot(
      [
        person('a', -320, -240), person('b', 320, -240), person('c', 640, -240),
        person('d', 1680, -240), person('e', 2520, -240), person('f', 2840, -240),
        person('ab', 0, 0), person('bc', 480, 0), person('bd', 1000, 0),
        person('ef-1', 2360, 0), person('ef-2', 2680, 0), person('ef-3', 3000, 0),
        person('grandchild-1', 1260, 240), person('grandchild-2', 1580, 240),
      ],
      [
        { id: 'a-ab', parentId: 'a', childId: 'ab', role: 'father' }, { id: 'b-ab', parentId: 'b', childId: 'ab', role: 'mother' },
        { id: 'b-bc', parentId: 'b', childId: 'bc', role: 'mother' }, { id: 'c-bc', parentId: 'c', childId: 'bc', role: 'father' },
        { id: 'b-bd', parentId: 'b', childId: 'bd', role: 'mother' }, { id: 'd-bd', parentId: 'd', childId: 'bd', role: 'father' },
        { id: 'e-ef-1', parentId: 'e', childId: 'ef-1', role: 'mother' }, { id: 'f-ef-1', parentId: 'f', childId: 'ef-1', role: 'father' },
        { id: 'e-ef-2', parentId: 'e', childId: 'ef-2', role: 'mother' }, { id: 'f-ef-2', parentId: 'f', childId: 'ef-2', role: 'father' },
        { id: 'e-ef-3', parentId: 'e', childId: 'ef-3', role: 'mother' }, { id: 'f-ef-3', parentId: 'f', childId: 'ef-3', role: 'father' },
        { id: 'bc-grandchild-1', parentId: 'bc', childId: 'grandchild-1', role: 'father' }, { id: 'ef-1-grandchild-1', parentId: 'ef-1', childId: 'grandchild-1', role: 'mother' },
        { id: 'bc-grandchild-2', parentId: 'bc', childId: 'grandchild-2', role: 'father' }, { id: 'ef-1-grandchild-2', parentId: 'ef-1', childId: 'grandchild-2', role: 'mother' },
      ],
      [
        ['a', 'b'], ['b', 'c'], ['b', 'd'], ['bc', 'ef-1'], ['e', 'f'],
      ].map(([malePersonId, femalePersonId], index) => ({
        id: `marriage-${index}`, malePersonId: malePersonId!, femalePersonId: femalePersonId!,
        marriageDate: { state: 'unknown' as const }, dissolutionDate: null,
        createdAt: '2026-07-31T00:00:00+00:00', updatedAt: '2026-07-31T00:00:00+00:00',
      })),
    )

    expect(reflowTree(tree)).toEqual([
      { personId: 'b', coordinates: { x: 60, y: -240 } },
      { personId: 'c', coordinates: { x: 440, y: -240 } },
      { personId: 'd', coordinates: { x: 1200, y: -240 } },
      { personId: 'e', coordinates: { x: 1840, y: -240 } },
      { personId: 'f', coordinates: { x: 2160, y: -240 } },
      { personId: 'ab', coordinates: { x: -130, y: 0 } },
      { personId: 'bc', coordinates: { x: 250, y: 0 } },
      { personId: 'bd', coordinates: { x: 630, y: 0 } },
      { personId: 'ef-1', coordinates: { x: 1680, y: 0 } },
      { personId: 'ef-2', coordinates: { x: 2000, y: 0 } },
      { personId: 'ef-3', coordinates: { x: 2320, y: 0 } },
      { personId: 'grandchild-1', coordinates: { x: 805, y: 240 } },
      { personId: 'grandchild-2', coordinates: { x: 1125, y: 240 } },
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

  it('moves an entire root family block when resolving a collision', () => {
    const tree = snapshot(
      [
        person('independent-parent', 320, 0),
        person('mother', 0, 0),
        person('father', 320, 0),
        person('independent-child', 320, 240),
        person('family-child', 160, 240),
      ],
      [
        { id: 'independent-link', parentId: 'independent-parent', childId: 'independent-child', role: 'father' },
        { id: 'mother-link', parentId: 'mother', childId: 'family-child', role: 'mother' },
        { id: 'father-link', parentId: 'father', childId: 'family-child', role: 'father' },
      ],
    )

    expect(reflowTree(tree)).toEqual([
      { personId: 'independent-parent', coordinates: { x: 640, y: 0 } },
      { personId: 'independent-child', coordinates: { x: 640, y: 240 } },
    ])
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
      { personId: 'husband-2', coordinates: { x: 960, y: 0 } },
      { personId: 'child-1', coordinates: { x: 160, y: 240 } },
      { personId: 'child-2', coordinates: { x: 640, y: 240 } },
      { personId: 'child-3', coordinates: { x: 960, y: 240 } },
    ])
  })

  it('keeps partner brackets ordered without crossings', () => {
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
        data: expect.objectContaining({ bracketDepth: 24, sourceOffset: 16 }),
      }),
    )
    expect(edges.get('family:centre:far-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 42, sourceOffset: -16 }),
      }),
    )
  })

  it('keeps brackets to opposite sides of a shared person at the base height', () => {
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
        data: expect.objectContaining({ bracketDepth: 24, targetOffset: -16 }),
      }),
    )
    expect(edges.get('family:centre:right-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 24, sourceOffset: 16 }),
      }),
    )
  })

  it('distributes odd partner outlets symmetrically around the shared person', () => {
    const tree = snapshot(
      [
        person('left-partner', -320, 0),
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
    expect(edges.get('family:centre:far-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 42, sourceOffset: 0 }),
      }),
    )
    expect(edges.get('family:centre:left-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 24, targetOffset: -32 }),
      }),
    )
    expect(edges.get('family:centre:near-partner')).toEqual(
      expect.objectContaining({
        data: expect.objectContaining({ bracketDepth: 24, sourceOffset: 32 }),
      }),
    )
  })
})
