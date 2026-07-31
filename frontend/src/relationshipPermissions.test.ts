import { describe, expect, it } from 'vitest'
import type {
  MarriagePeriod,
  ParentLink,
  Person,
  TreeSnapshot,
} from './api/generated'
import {
  canDeletePerson,
  canRemoveMarriage,
  canRemoveParentLink,
} from './relationshipPermissions'

const createdAt = '2026-07-31T00:00:00+00:00'
const person = (id: string): Person => ({
  id,
  birthSurname: { state: 'unknown' },
  givenName: { state: 'unknown' },
  patronymic: { state: 'unknown' },
  gender: 'unknown',
  birthDate: { state: 'unknown' },
  deathDate: null,
  photoThumbnailUrl: null,
  coordinates: { x: 0, y: 0 },
  createdAt,
  updatedAt: createdAt,
})
const link = (
  id: string,
  parentId: string,
  childId: string,
): ParentLink => ({ id, parentId, childId, role: 'father' })
const marriage = (
  id: string,
  malePersonId: string,
  femalePersonId: string,
  current = true,
): MarriagePeriod => ({
  id,
  malePersonId,
  femalePersonId,
  marriageDate: { state: 'unknown' },
  dissolutionDate: current ? null : { state: 'unknown' },
  createdAt,
  updatedAt: createdAt,
})
const snapshot = (
  parentLinks: ParentLink[],
  marriages: MarriagePeriod[],
): TreeSnapshot => ({
  tree: { id: 'tree', name: 'Tree', createdAt, updatedAt: createdAt },
  etag: '"tree"',
  people: ['a', 'b', 'c'].map(person),
  parentLinks,
  marriages,
})

describe('relationship permissions', () => {
  it('allows removing only links that keep every remaining person connected', () => {
    const bridge = link('bridge', 'a', 'b')
    const redundant = marriage('marriage', 'a', 'b')
    const child = link('child', 'b', 'c')
    const connected = snapshot([bridge, child], [redundant])
    expect(canRemoveParentLink(connected, bridge)).toBe(true)
    expect(canRemoveMarriage(connected, redundant)).toBe(true)
    expect(canRemoveParentLink(connected, child)).toBe(false)
  })

  it('rejects internal people and current spouses with a common child', () => {
    expect(canDeletePerson(
      snapshot([link('a-b', 'a', 'b'), link('b-c', 'b', 'c')], []),
      'b',
    )).toBe(false)
    expect(canDeletePerson(
      snapshot(
        [link('a-c', 'a', 'c'), link('b-c', 'b', 'c')],
        [marriage('a-b', 'a', 'b')],
      ),
      'a',
    )).toBe(false)
  })

  it('allows a connected leaf and ignores a former spouse restriction', () => {
    expect(canDeletePerson(
      snapshot(
        [link('a-c', 'a', 'c'), link('b-c', 'b', 'c')],
        [marriage('a-b', 'a', 'b', false)],
      ),
      'a',
    )).toBe(true)
    expect(canDeletePerson(
      { ...snapshot([], []), people: [person('a')] },
      'a',
    )).toBe(true)
  })
})
