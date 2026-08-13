import type {
  MarriagePeriod,
  ParentLink,
  Person,
  PersonDetails,
  PersonRelationshipPerson,
  SearchRoutePlan,
} from './api/generated'

export type PersonCardPerson = Pick<
  Person,
  | 'id'
  | 'birthSurname'
  | 'givenName'
  | 'patronymic'
  | 'gender'
  | 'birthDate'
  | 'deathDate'
  | 'photoThumbnailUrl'
>

export type PathDisplayPlan = Omit<SearchRoutePlan, 'people'> & {
  people: PersonCardPerson[]
}

export type RelationshipNavigation = {
  person: PersonRelationshipPerson
  relationship: {
    type: 'parent'
    link: ParentLink
  } | {
    type: 'marriage'
    marriage: MarriagePeriod
  }
}

export function relationshipPerson(person: Person): PersonRelationshipPerson {
  return {
    id: person.id,
    birthSurname: person.birthSurname,
    givenName: person.givenName,
    patronymic: person.patronymic,
    gender: person.gender,
    birthDate: person.birthDate,
    deathDate: person.deathDate,
    photoThumbnailUrl: person.photoThumbnailUrl,
  }
}

export function createLocalRelationshipPath(
  person: Person,
  details: PersonDetails,
  navigation: RelationshipNavigation,
): PathDisplayPlan {
  if (navigation.relationship.type === 'marriage') {
    return {
      etag: details.etag,
      targetPersonId: navigation.person.id,
      people: [relationshipPerson(person), navigation.person],
      nodes: [
        { personId: person.id, generation: 0, kind: 'route' },
        { personId: navigation.person.id, generation: 0, kind: 'route' },
      ],
      parentLinks: [],
      marriages: [navigation.relationship.marriage],
      familyBlocks: [],
    }
  }

  const link = navigation.relationship.link
  const childId = link.childId
  const links = details.relationships.parentLinks
    .filter((candidate) => candidate.childId === childId)
    .sort((left, right) => left.id.localeCompare(right.id))
  const people = new Map<string, PersonCardPerson>([
    [person.id, relationshipPerson(person)],
    [navigation.person.id, navigation.person],
  ])
  const relatedPeople = new Map(details.relationships.people.map((candidate) => [
    candidate.id,
    candidate,
  ]))
  for (const parentLink of links) {
    const relatedPerson = relatedPeople.get(parentLink.parentId)
    if (relatedPerson) {
      people.set(relatedPerson.id, relatedPerson)
    }
  }

  const isSelectedPersonTheChild = person.id === childId
  const parentGeneration = isSelectedPersonTheChild ? -1 : 0
  const nodes = [...people.keys()]
    .sort((left, right) => left.localeCompare(right))
    .map((personId) => ({
      personId,
      generation: personId === childId ? (isSelectedPersonTheChild ? 0 : 1) : parentGeneration,
      kind: personId === person.id || personId === navigation.person.id
        ? 'route' as const
        : 'support' as const,
    }))
  const parentIds = links.map((candidate) => candidate.parentId)
  const marriages = parentIds.length === 2
    ? details.relationships.marriages.filter((marriage) => samePair(
      marriage.malePersonId,
      marriage.femalePersonId,
      parentIds[0]!,
      parentIds[1]!,
    ))
    : []

  return {
    etag: details.etag,
    targetPersonId: navigation.person.id,
    people: [...people.values()].sort((left, right) => left.id.localeCompare(right.id)),
    nodes,
    parentLinks: links,
    marriages,
    familyBlocks: parentIds.length === 2 ? [{
      id: `local:${[...parentIds].sort().join(':')}:${childId}`,
      personIds: [parentIds[0]!, parentIds[1]!],
      childIds: [childId],
      married: marriages.length > 0,
    }] : [],
  }
}

function samePair(
  first: string,
  second: string,
  expectedFirst: string,
  expectedSecond: string,
) {
  return [first, second].sort().join(':') === [expectedFirst, expectedSecond].sort().join(':')
}
