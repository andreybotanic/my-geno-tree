import { describe, expect, it } from 'vitest'
import type { MarriagePeriod, ParentLink, Person, TreeSnapshot } from './api/generated'
import {
  buildTreeEdges,
  layoutTree,
  PERSON_CARD_HEIGHT,
  PERSON_CARD_WIDTH,
} from './treeGraph'

const createdAt = '2026-08-05T00:00:00+00:00'
const person = (id: string): Person => ({
  id,
  birthSurname: { state: 'known', value: id },
  givenName: { state: 'unknown' },
  patronymic: { state: 'unknown' },
  gender: 'unknown',
  birthDate: { state: 'unknown' },
  deathDate: null,
  photoThumbnailUrl: null,
  createdAt,
  updatedAt: createdAt,
})
const link = (id: string, parentId: string, childId: string, role: ParentLink['role']): ParentLink => ({
  id, parentId, childId, role,
})
const marriage = (id: string, first: string, second: string): MarriagePeriod => ({
  id,
  malePersonId: first,
  femalePersonId: second,
  marriageDate: { state: 'unknown' },
  dissolutionDate: null,
  createdAt,
  updatedAt: createdAt,
})
const snapshot = (
  people: Person[],
  parentLinks: ParentLink[],
  marriages: MarriagePeriod[],
  reference: TreeSnapshot['tree']['reference'],
): TreeSnapshot => ({
  tree: { id: 'tree', name: 'Tree', reference, createdAt, updatedAt: createdAt },
  etag: '"tree"',
  people,
  parentLinks,
  marriages,
})

describe('deterministic tree layout', () => {
  const people = ['father', 'mother', 'anchor', 'sibling', 'child'].map(person)
  const links = [
    link('a', 'father', 'anchor', 'father'),
    link('b', 'mother', 'anchor', 'mother'),
    link('c', 'father', 'sibling', 'father'),
    link('d', 'mother', 'sibling', 'mother'),
    link('e', 'anchor', 'child', 'mother'),
  ]
  const marriages = [marriage('m', 'father', 'mother')]

  it('is invariant to every input-array order and centers a single reference card', () => {
    const tree = snapshot(people, links, marriages, { type: 'person', personId: 'anchor' })
    const first = layoutTree(tree)
    const reordered = layoutTree(snapshot(
      [...people].reverse(),
      [...links].reverse(),
      [...marriages].reverse(),
      tree.tree.reference,
    ))
    expect([...reordered]).toEqual([...first])
    expect(first.get('anchor')).toEqual({
      x: -PERSON_CARD_WIDTH / 2,
      y: -PERSON_CARD_HEIGHT / 2,
    })
  })

  it('keeps a reference marriage adjacent and centers its family block', () => {
    const tree = snapshot(people, links, marriages, {
      type: 'marriage',
      personIds: ['father', 'mother'],
    })
    const positions = layoutTree(tree)
    const first = positions.get('father')!
    const second = positions.get('mother')!
    expect(Math.abs(first.x - second.x)).toBe(320)
    expect((first.x + second.x) / 2 + PERSON_CARD_WIDTH / 2).toBe(0)
    expect(first.y).toBe(second.y)
  })

  it('does not overlap cards and produces one highlighted marriage bracket', () => {
    const tree = snapshot(people, links, marriages, {
      type: 'marriage',
      personIds: ['father', 'mother'],
    })
    const positions = layoutTree(tree)
    for (const [firstId, first] of positions) {
      for (const [secondId, second] of positions) {
        if (firstId >= secondId) continue
        const overlaps = first.x < second.x + PERSON_CARD_WIDTH
          && first.x + PERSON_CARD_WIDTH > second.x
          && first.y < second.y + PERSON_CARD_HEIGHT
          && first.y + PERSON_CARD_HEIGHT > second.y
        expect(overlaps).toBe(false)
      }
    }
    expect(buildTreeEdges(tree, positions)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        id: 'family:father:mother',
        data: expect.objectContaining({ married: true, reference: true }),
      }),
    ]))
  })
})
