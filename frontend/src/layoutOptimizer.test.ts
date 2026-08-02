import { describe, expect, it } from 'vitest'
import type { Person, TreeSnapshot } from './api/generated'
import {
  LIMITED_LAYOUT_SEARCH,
  estimateLayoutCandidates,
  measureTreeWidth,
  optimizeTreeLayout,
} from './layoutOptimizer'

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
  createdAt: '2026-08-02T00:00:00+00:00',
  updatedAt: '2026-08-02T00:00:00+00:00',
})

const snapshot = (
  people: Person[],
  parentLinks: TreeSnapshot['parentLinks'] = [],
  marriages: TreeSnapshot['marriages'] = [],
): TreeSnapshot => ({
  tree: {
    id: 'tree',
    name: 'Family',
    createdAt: '2026-08-02T00:00:00+00:00',
    updatedAt: '2026-08-02T00:00:00+00:00',
  },
  etag: '"tree"',
  people,
  parentLinks,
  marriages,
})

describe('layout optimizer', () => {
  it('is deterministic and only returns a strictly narrower layout', async () => {
    const tree = snapshot([
      person('left', -900, 0),
      person('middle', 0, 0),
      person('right', 900, 0),
    ], [], [{
      id: 'marriage-left-middle',
      malePersonId: 'left',
      femalePersonId: 'middle',
      marriageDate: { state: 'unknown' },
      dissolutionDate: null,
      createdAt: '2026-08-02T00:00:00+00:00',
      updatedAt: '2026-08-02T00:00:00+00:00',
    }, {
      id: 'marriage-middle-right',
      malePersonId: 'right',
      femalePersonId: 'middle',
      marriageDate: { state: 'unknown' },
      dissolutionDate: null,
      createdAt: '2026-08-02T00:00:00+00:00',
      updatedAt: '2026-08-02T00:00:00+00:00',
    }])

    const first = await optimizeTreeLayout(tree)
    const second = await optimizeTreeLayout(tree)

    expect(first).toEqual(second)
    expect(first?.optimizedWidth).toBeLessThan(measureTreeWidth(tree))
    expect(first?.coordinateUpdates).not.toHaveLength(0)
  })

  it('is deterministic when full siblings have a half sibling and an external spouse', async () => {
    const tree = snapshot(
      [
        person('father', 0, 0),
        person('mother', 320, 0),
        person('other-mother', 960, 0),
        person('full-first', -600, 240),
        person('half', 320, 240),
        person('full-second', 960, 240),
        person('full-spouse', 1280, 240),
      ],
      [
        { id: 'f1', parentId: 'father', childId: 'full-first', role: 'father' },
        { id: 'm1', parentId: 'mother', childId: 'full-first', role: 'mother' },
        { id: 'f2', parentId: 'father', childId: 'half', role: 'father' },
        { id: 'm2', parentId: 'other-mother', childId: 'half', role: 'mother' },
        { id: 'f3', parentId: 'father', childId: 'full-second', role: 'father' },
        { id: 'm3', parentId: 'mother', childId: 'full-second', role: 'mother' },
      ],
      [{
        id: 'full-child-family',
        malePersonId: 'full-second',
        femalePersonId: 'full-spouse',
        marriageDate: { state: 'unknown' },
        dissolutionDate: null,
        createdAt: '2026-08-02T00:00:00+00:00',
        updatedAt: '2026-08-02T00:00:00+00:00',
      }],
    )

    await expect(optimizeTreeLayout(tree)).resolves.toEqual(await optimizeTreeLayout(tree))
  })

  it('counts stable spouse permutations after pruning isolated couples', () => {
    const tree = snapshot([
      person('partner-one', 0, 0),
      person('center', 320, 0),
      person('partner-two', 640, 0),
      person('isolated-one', 960, 0),
      person('isolated-two', 1280, 0),
    ], [], [
      {
        id: 'one', malePersonId: 'partner-one', femalePersonId: 'center',
        marriageDate: { state: 'unknown' }, dissolutionDate: null,
        createdAt: '', updatedAt: '',
      },
      {
        id: 'two', malePersonId: 'partner-two', femalePersonId: 'center',
        marriageDate: { state: 'unknown' }, dissolutionDate: null,
        createdAt: '', updatedAt: '',
      },
      {
        id: 'isolated', malePersonId: 'isolated-one', femalePersonId: 'isolated-two',
        marriageDate: { state: 'unknown' }, dissolutionDate: null,
        createdAt: '', updatedAt: '',
      },
    ])

    expect(estimateLayoutCandidates(tree)).toBe(6n)
  })

  it('prunes equivalent full-sibling subtrees by their structural fingerprint', () => {
    const tree = snapshot(
      [
        person('father', 0, 0), person('mother', 320, 0),
        person('first', 0, 240), person('second', 320, 240),
        person('first-spouse', 640, 240), person('second-spouse', 960, 240),
      ],
      [
        { id: 'f1', parentId: 'father', childId: 'first', role: 'father' },
        { id: 'm1', parentId: 'mother', childId: 'first', role: 'mother' },
        { id: 'f2', parentId: 'father', childId: 'second', role: 'father' },
        { id: 'm2', parentId: 'mother', childId: 'second', role: 'mother' },
      ],
      ['first', 'second'].map((childId) => ({
        id: `family-${childId}`,
        malePersonId: childId,
        femalePersonId: `${childId}-spouse`,
        marriageDate: { state: 'unknown' as const },
        dissolutionDate: null,
        createdAt: '',
        updatedAt: '',
      })),
    )

    expect(estimateLayoutCandidates(tree)).toBe(1n)
  })

  it('marks a limited enumeration as incomplete', async () => {
    const tree = snapshot([
      person('first', 0, 0),
      person('center', 320, 0),
      person('second', 640, 0),
      person('third', 960, 0),
      person('fourth', 1280, 0),
    ], [], ['first', 'second', 'third', 'fourth'].map((partnerId) => ({
      id: `marriage-${partnerId}`,
      malePersonId: partnerId,
      femalePersonId: 'center',
      marriageDate: { state: 'unknown' as const },
      dissolutionDate: null,
      createdAt: '',
      updatedAt: '',
    })))

    const result = await optimizeTreeLayout(tree, { limit: 10n })

    expect(estimateLayoutCandidates(tree)).toBeGreaterThan(10n)
    expect(LIMITED_LAYOUT_SEARCH).toBe(10_000n)
    expect(result?.limited).toBe(true)
    expect(result?.checkedCount).toBe(10n)
  })
})
