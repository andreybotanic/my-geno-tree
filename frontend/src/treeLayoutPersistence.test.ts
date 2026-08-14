import { describe, expect, it, vi } from 'vitest'
import type { Person, TreeSnapshot } from './api/generated'
import * as treeGraph from './treeGraph'
import {
  resolveTreeLayout,
  restoreTreeLayout,
  treeLayoutCacheKey,
  TREE_LAYOUT_ALGORITHM_VERSION,
} from './treeLayoutPersistence'

const person: Person = {
  id: '0198d0a2-6c2b-7f4b-8f2e-1aa7bd51d8bb',
  birthSurname: { state: 'unknown' },
  givenName: { state: 'unknown' },
  patronymic: { state: 'unknown' },
  gender: 'unknown',
  birthDate: { state: 'unknown' },
  deathDate: null,
  birthPlace: null,
  deathPlace: null,
  photoThumbnailUrl: null,
  createdAt: '2026-08-14T00:00:00+00:00',
  updatedAt: '2026-08-14T00:00:00+00:00',
}
const secondPerson: Person = {
  ...person,
  id: '0198d0a2-6c2b-7f4b-8f2e-1aa7bd51d8bc',
}

function snapshot(layout: TreeSnapshot['layout'] = null): TreeSnapshot {
  return {
    tree: {
      id: '0198d0a2-6c2b-7f4b-8f2e-1aa7bd51d8aa',
      name: 'Tree',
      reference: { type: 'person', personId: person.id },
      createdAt: person.createdAt,
      updatedAt: person.updatedAt,
    },
    etag: '"tree"',
    layoutRevision: 'A'.repeat(43),
    layout,
    people: [person],
    parentLinks: [],
    marriages: [],
  }
}

describe('tree layout persistence', () => {
  it('restores a valid saved layout without running the layout algorithm', () => {
    const tree = snapshot({
      algorithmVersion: TREE_LAYOUT_ALGORITHM_VERSION,
      positions: { [person.id]: [125, -75] },
    })
    const layoutSpy = vi.spyOn(treeGraph, 'layoutTree')

    const result = resolveTreeLayout(tree, tree)

    expect(layoutSpy).not.toHaveBeenCalled()
    expect(result.positions.get(person.id)).toEqual({ x: 125, y: -75 })
    expect(result.saveRequest).toBeNull()
  })

  it('rejects saved positions with the wrong algorithm or person set', () => {
    const wrongVersion = snapshot({
      algorithmVersion: TREE_LAYOUT_ALGORITHM_VERSION + 1,
      positions: { [person.id]: [-130, -80] },
    })
    const wrongPeople = snapshot({
      algorithmVersion: TREE_LAYOUT_ALGORITHM_VERSION,
      positions: { foreign: [0, 0] },
    })

    expect(restoreTreeLayout(wrongVersion, wrongVersion)).toBeNull()
    expect(restoreTreeLayout(wrongPeople, wrongPeople)).toBeNull()
  })

  it('sorts and validates every restored person coordinate', () => {
    const tree = {
      ...snapshot({
        algorithmVersion: TREE_LAYOUT_ALGORITHM_VERSION,
        positions: {
          [secondPerson.id]: [20, 30],
          [person.id]: [0, 10],
        },
      }),
      people: [secondPerson, person],
    }
    expect([...restoreTreeLayout(tree, tree)!.keys()]).toEqual([
      person.id,
      secondPerson.id,
    ])

    const invalid = {
      ...tree,
      layout: {
        ...tree.layout!,
        positions: { ...tree.layout!.positions, [person.id]: [1_000_001, 0] as [number, number] },
      },
    }
    expect(restoreTreeLayout(invalid, invalid)).toBeNull()
  })

  it('saves only calculations taking at least two seconds', () => {
    const tree = snapshot()
    const fast = resolveTreeLayout(tree, tree, sequence(0, 1_999))
    const slow = resolveTreeLayout(tree, tree, sequence(0, 2_000))

    expect(fast.saveRequest).toBeNull()
    expect(slow.saveRequest).toEqual({
      layoutRevision: tree.layoutRevision,
      algorithmVersion: TREE_LAYOUT_ALGORITHM_VERSION,
      positions: { [person.id]: [-130, -80] },
    })
  })

  it('does not save a calculation when no structural revision exists', () => {
    const tree = { ...snapshot(), layoutRevision: null }

    expect(resolveTreeLayout(tree, tree, sequence(0, 5_000)).saveRequest).toBeNull()
  })

  it('keeps the in-memory geometry key across nonstructural snapshot changes', () => {
    const tree = snapshot()
    const renamed = {
      ...tree,
      etag: '"tree-next"',
      tree: { ...tree.tree, name: 'Renamed' },
      people: [{ ...person, givenName: { state: 'known', value: 'Anna' } as const }],
    }

    expect(treeLayoutCacheKey(renamed)).toBe(treeLayoutCacheKey(tree))
    expect(treeLayoutCacheKey({ ...renamed, layoutRevision: 'B'.repeat(43) }))
      .not.toBe(treeLayoutCacheKey(tree))
  })
})

function sequence(...values: number[]) {
  const now = vi.fn()
  for (const value of values) now.mockReturnValueOnce(value)
  return now
}
