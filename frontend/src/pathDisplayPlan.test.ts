import { describe, expect, it } from 'vitest'
import type {
  MarriagePeriod,
  ParentLink,
  Person,
  PersonDetails,
} from './api/generated'
import {
  createLocalRelationshipPath,
  relationshipPerson,
} from './pathDisplayPlan'

const timestamp = '2026-08-13T00:00:00+00:00'

function person(id: string, gender: Person['gender'] = 'unknown'): Person {
  return {
    id,
    birthSurname: { state: 'known', value: id },
    givenName: { state: 'unknown' },
    patronymic: { state: 'unknown' },
    gender,
    birthDate: { state: 'unknown' },
    deathDate: null,
    birthPlace: null,
    deathPlace: null,
    photoThumbnailUrl: id === 'father' ? '/thumbnail' : null,
    createdAt: timestamp,
    updatedAt: timestamp,
  }
}

function parentLink(id: string, parentId: string, childId: string): ParentLink {
  return {
    id,
    parentId,
    childId,
    role: parentId === 'father' ? 'father' : 'mother',
  }
}

function marriage(malePersonId: string, femalePersonId: string): MarriagePeriod {
  return {
    id: 'marriage',
    malePersonId,
    femalePersonId,
    marriageDate: { state: 'unknown' },
    dissolutionDate: null,
    marriagePlace: null,
    dissolutionPlace: null,
    createdAt: timestamp,
    updatedAt: timestamp,
  }
}

function details(
  current: Person,
  people: Person[],
  parentLinks: ParentLink[],
  marriages: MarriagePeriod[] = [],
): PersonDetails {
  return {
    etag: '"tree"',
    person: current,
    events: [],
    relationships: {
      people: people
        .filter((candidate) => candidate.id !== current.id)
        .map(relationshipPerson),
      parentLinks,
      marriages,
    },
    permissions: {
      canEdit: true,
      canDelete: true,
      removableParentLinkIds: [],
      removableMarriageIds: [],
    },
  }
}

describe('createLocalRelationshipPath', () => {
  it('adds both parents when opening a hidden parent of the selected child', () => {
    const child = person('child')
    const father = person('father', 'male')
    const mother = person('mother', 'female')
    const fatherLink = parentLink('father-link', father.id, child.id)
    const motherLink = parentLink('mother-link', mother.id, child.id)

    const plan = createLocalRelationshipPath(
      child,
      details(child, [child, father, mother], [fatherLink, motherLink]),
      {
        person: relationshipPerson(father),
        relationship: { type: 'parent', link: fatherLink },
      },
    )

    expect(plan.people.find((candidate) => candidate.id === father.id))
      .toMatchObject({ photoThumbnailUrl: '/thumbnail' })
    expect(plan.parentLinks).toEqual([fatherLink, motherLink])
    expect(plan.nodes).toEqual(expect.arrayContaining([
      { personId: child.id, generation: 0, kind: 'route' },
      { personId: father.id, generation: -1, kind: 'route' },
      { personId: mother.id, generation: -1, kind: 'support' },
    ]))
    expect(plan.familyBlocks).toEqual([{
      id: 'local:father:mother:child',
      personIds: [father.id, mother.id],
      childIds: [child.id],
      married: false,
    }])
  })

  it('keeps the child below both parents when opening a hidden child', () => {
    const father = person('father', 'male')
    const mother = person('mother', 'female')
    const child = person('child')
    const fatherLink = parentLink('father-link', father.id, child.id)
    const motherLink = parentLink('mother-link', mother.id, child.id)

    const plan = createLocalRelationshipPath(
      father,
      details(father, [father, mother, child], [fatherLink, motherLink]),
      {
        person: relationshipPerson(child),
        relationship: { type: 'parent', link: fatherLink },
      },
    )

    expect(plan.parentLinks).toEqual([fatherLink, motherLink])
    expect(plan.nodes).toEqual(expect.arrayContaining([
      { personId: father.id, generation: 0, kind: 'route' },
      { personId: mother.id, generation: 0, kind: 'support' },
      { personId: child.id, generation: 1, kind: 'route' },
    ]))
  })

  it('uses the clicked marriage period for a hidden spouse', () => {
    const first = person('first', 'male')
    const second = person('second', 'female')
    const period = marriage(first.id, second.id)

    const plan = createLocalRelationshipPath(
      first,
      details(first, [first, second], [], [period]),
      {
        person: relationshipPerson(second),
        relationship: { type: 'marriage', marriage: period },
      },
    )

    expect(plan.people).toEqual([
      relationshipPerson(first),
      relationshipPerson(second),
    ])
    expect(plan.nodes).toEqual([
      { personId: first.id, generation: 0, kind: 'route' },
      { personId: second.id, generation: 0, kind: 'route' },
    ])
    expect(plan.marriages).toEqual([period])
  })
})
