import { describe, expect, it } from 'vitest'
import type { MarriagePeriod, ParentLink, Person, TreeSnapshot } from './api/generated'
import { focusedTreeSnapshot, visiblePersonIdsFor } from './focusedTree'

const at = '2026-08-05T00:00:00+00:00'
const person = (id: string): Person => ({
  id,
  birthSurname: { state: 'known', value: id },
  givenName: { state: 'unknown' },
  patronymic: { state: 'unknown' },
  gender: 'unknown',
  birthDate: { state: 'unknown' },
  deathDate: null,
  birthPlace: null,
  deathPlace: null,
  photoThumbnailUrl: null,
  createdAt: at,
  updatedAt: at,
})
const parent = (parentId: string, childId: string, role: ParentLink['role'] = 'father'): ParentLink => ({
  id: `${parentId}-${childId}`,
  parentId,
  childId,
  role,
})
const married = (first: string, second: string, dissolved = false): MarriagePeriod => ({
  id: `${first}-${second}`,
  malePersonId: first,
  femalePersonId: second,
  marriageDate: { state: 'unknown' },
  dissolutionDate: dissolved ? { state: 'unknown' } : null,
  marriagePlace: null,
  dissolutionPlace: null,
  createdAt: at,
  updatedAt: at,
})

function family(reference: TreeSnapshot['tree']['reference']): TreeSnapshot {
  const ids = [
    'anchor', 'parent', 'other-parent', 'grandparent', 'half-sibling', 'half-sibling-other-parent',
    'aunt', 'cousin', 'cousin-parent', 'spouse', 'spouse-parent',
    'spouse-sibling', 'step-child', 'step-parent', 'niece', 'niece-parent',
    'child', 'grandchild', 'grandchild-parent', 'too-far-local-child',
  ]
  return {
    tree: { id: 'tree', name: 'Tree', reference, createdAt: at, updatedAt: at },
    etag: '"tree"',
    layoutRevision: null,
    layout: null,
    people: ids.map(person),
    parentLinks: [
      parent('grandparent', 'parent'),
      parent('grandparent', 'aunt'),
      parent('parent', 'anchor'),
      parent('other-parent', 'anchor', 'mother'),
      parent('parent', 'half-sibling'),
      parent('half-sibling-other-parent', 'half-sibling', 'mother'),
      parent('aunt', 'cousin'),
      parent('cousin-parent', 'cousin', 'mother'),
      parent('spouse-parent', 'spouse'),
      parent('spouse-parent', 'spouse-sibling'),
      parent('spouse', 'step-child'),
      parent('step-parent', 'step-child', 'mother'),
      parent('half-sibling', 'niece'),
      parent('niece-parent', 'niece', 'mother'),
      parent('anchor', 'child'),
      parent('child', 'grandchild'),
      parent('grandchild-parent', 'grandchild', 'mother'),
      parent('cousin', 'too-far-local-child'),
    ],
    marriages: [married('anchor', 'spouse', true)],
  }
}

describe('focused tree visibility', () => {
  it('applies every local rule once while keeping blood traversal recursive', () => {
    const tree = family({ type: 'person', personId: 'anchor' })
    const visible = visiblePersonIdsFor(tree, 'anchor')
    expect(visible).toEqual(new Set([
      'anchor', 'parent', 'other-parent', 'grandparent', 'half-sibling', 'half-sibling-other-parent',
      'aunt', 'cousin', 'cousin-parent', 'spouse', 'spouse-parent',
      'spouse-sibling', 'step-child', 'step-parent', 'niece', 'niece-parent',
      'child', 'grandchild', 'grandchild-parent',
    ]))
    expect(visible.has('too-far-local-child')).toBe(false)
  })

  it('treats dissolved marriages and half siblings as relationships', () => {
    const visible = visiblePersonIdsFor(family(null), 'anchor')
    expect(visible.has('spouse')).toBe(true)
    expect(visible.has('half-sibling')).toBe(true)
  })

  it('computes a couple as the exact union of two independent runs', () => {
    const tree = family({ type: 'marriage', personIds: ['anchor', 'spouse'] })
    const expected = new Set([
      ...visiblePersonIdsFor(tree, 'anchor'),
      ...visiblePersonIdsFor(tree, 'spouse'),
    ])
    const focused = focusedTreeSnapshot(tree)
    expect(new Set(focused.people.map(({ id }) => id))).toEqual(expected)
    expect(focused.parentLinks.every((link) => (
      expected.has(link.parentId) && expected.has(link.childId)
    ))).toBe(true)
  })
})
