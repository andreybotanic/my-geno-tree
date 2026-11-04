import type { Person, TreeMergeProposal, TreeSnapshot } from './api/generated'
import { buildTreeEdges, layoutTree, PERSON_CARD_HEIGHT, PERSON_CARD_WIDTH } from './treeGraph'
import type { PersonFlowNode } from './components/PersonCard'

/** A disposable graph for review. Its IDs and positions never enter a mutation or layout cache. */
export function projectTreeMerge(snapshot: TreeSnapshot, proposal: TreeMergeProposal) {
  const people = new Map(snapshot.people.map((person) => [person.id, person]))
  const references = new Map<string, string>()
  const proposalIds = new Map<string, string>()
  const existingIds = new Set(people.keys())
  for (const row of proposal.people) {
    const id = row.resolution === 'existing' && row.existingPersonId ? row.existingPersonId : `merge:${row.id}`
    references.set(row.id, id)
    proposalIds.set(id, row.id)
    const before = people.get(id)
    const person: Person = {
      id, givenName: { state: 'unknown' }, birthSurname: { state: 'unknown' }, patronymic: { state: 'unknown' },
      gender: 'unknown', birthDate: { state: 'unknown' }, deathDate: null, birthPlace: null, deathPlace: null,
      photoThumbnailUrl: null, createdAt: snapshot.tree.createdAt, updatedAt: snapshot.tree.updatedAt,
      ...before, ...row.fields,
    }
    people.set(id, person)
  }
  const parentLinks = [...snapshot.parentLinks]
  for (const [index, link] of proposal.parents.entries()) {
    const parentId = references.get(link.parent)
    const childId = references.get(link.child)
    if (!parentId || !childId || parentId === childId) continue
    if (!parentLinks.some((item) => item.parentId === parentId && item.childId === childId)) {
      parentLinks.push({ id: `merge:parent:${index}`, parentId, childId, role: link.role })
    }
    const parent = people.get(parentId)!
    if (parent.gender === 'unknown') people.set(parentId, { ...parent, gender: link.role === 'mother' ? 'female' : 'male' })
  }
  const marriages = [...snapshot.marriages]
  for (const [index, link] of proposal.marriages.entries()) {
    const first = references.get(link.first)
    const second = references.get(link.second)
    if (!first || !second || first === second) continue
    if (!link.newPeriod && marriages.some((item) => [item.malePersonId, item.femalePersonId].includes(first)
      && [item.malePersonId, item.femalePersonId].includes(second))) continue
    const firstFemale = people.get(first)?.gender === 'female'
    marriages.push({ id: `merge:marriage:${index}`, malePersonId: firstFemale ? second : first, femalePersonId: firstFemale ? first : second,
      marriageDate: link.marriageDate, dissolutionDate: link.dissolutionDate, marriagePlace: null, dissolutionPlace: null,
      createdAt: snapshot.tree.createdAt, updatedAt: snapshot.tree.updatedAt })
  }
  const projected: TreeSnapshot = { ...snapshot, people: [...people.values()], parentLinks, marriages, layout: null, layoutRevision: null }
  const positions = layoutTree(projected)
  const nodes: PersonFlowNode[] = projected.people.map((person) => ({
    id: person.id, type: 'person', position: positions.get(person.id) ?? { x: 0, y: 0 },
    height: PERSON_CARD_HEIGHT, width: PERSON_CARD_WIDTH,
    data: { person, isReference: false, isMuted: existingIds.has(person.id) },
    ...(proposalIds.has(person.id) ? { className: 'merge-preview-person' } : {}),
  }))
  const edges = buildTreeEdges(projected, positions)
  return { nodes, edges, proposalIds }
}
