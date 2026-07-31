import { describe, expect, it } from 'vitest'
import type { Person, TreeSnapshot } from './api/generated'
import { buildTreeEdges, newRelativeCoordinates } from './treeGraph'

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
      type: 'family',
      data: {
        married: true,
        childPoints: [
          { id: 'child-1', topX: 110, topY: 240 },
          { id: 'child-2', topX: 390, topY: 240 },
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
      x: -140,
      y: -240,
    })
    expect(newRelativeCoordinates(tree, 'anchor', 'spouse')).toEqual({
      x: 560,
      y: 0,
    })
    expect(newRelativeCoordinates(tree, 'missing', 'child')).toEqual({
      x: 0,
      y: 0,
    })
  })
})
