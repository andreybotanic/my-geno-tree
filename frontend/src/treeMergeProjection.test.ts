import { describe, expect, it } from 'vitest'
import type { Person, TreeMergeProposal, TreeSnapshot } from './api/generated'
import { projectTreeMerge } from './treeMergeProjection'

const anna: Person = { id: 'anna', givenName: { state: 'known', value: 'Anna' }, birthSurname: { state: 'unknown' }, patronymic: { state: 'unknown' }, gender: 'female', birthDate: { state: 'unknown' }, deathDate: null, birthPlace: null, deathPlace: null, photoThumbnailUrl: null, createdAt: '2026-01-01', updatedAt: '2026-01-01' }
const snapshot: TreeSnapshot = { tree: { id: 'tree', name: 'Family', reference: { type: 'person', personId: 'anna' }, createdAt: '2026-01-01', updatedAt: '2026-01-01' }, people: [anna], parentLinks: [], marriages: [], etag: 'etag', layout: null, layoutRevision: 'revision' }
const proposal: TreeMergeProposal = { people: [
    { id: 'a', existingPersonId: 'anna', resolution: 'existing', lifeStatus: 'alive', fields: { birthDate: { state: 'known', kind: 'approximate', year: 1980 } }, evidence: [] },
    { id: 'b', existingPersonId: null, resolution: 'new', lifeStatus: 'alive', fields: { givenName: { state: 'known', value: 'Boris' } }, evidence: [] },
  ], parents: [{ parent: 'b', child: 'a', role: 'father', evidence: [] }], marriages: [], questions: [] }

describe(
  'merge projection',
  () => {
    it(
      'shows new cards and changes while fading existing people without mutating the snapshot or saving positions',
      () => {
        const before = JSON.stringify(snapshot)
        const result = projectTreeMerge(snapshot, proposal)
        expect(JSON.stringify(snapshot)).toBe(before)
        expect(result.nodes).toHaveLength(2)
        const existing = result.nodes.find((node) => node.id === 'anna')!
        expect(existing.data.isMuted).toBe(true)
        expect(existing.data.person.birthDate).toEqual({ state: 'known', kind: 'approximate', year: 1980 })
        const added = result.nodes.find((node) => node.id === 'merge:b')!
        expect(added.data.isMuted).toBe(false)
        expect(added.data.person.gender).toBe('male')
        expect(added.data.person.birthSurname).toEqual({ state: 'unknown' })
        expect(result.proposalIds.get('anna')).toBe('a')
        expect(result.edges).toHaveLength(1)
        expect(existing.position).not.toEqual(added.position)
      }
    )

    it(
      'lays out an empty tree, unknown mothers and unresolved matches without exposing temporary IDs as existing people',
      () => {
        const empty = { ...snapshot, people: [], tree: { ...snapshot.tree, reference: null } }
        const result = projectTreeMerge(empty, { ...proposal, people: proposal.people.map((p) => ({ ...p, resolution: 'undecided', existingPersonId: null })), parents: [{ parent: 'b', child: 'a', role: 'mother', evidence: [] }] })
        expect(result.nodes.every((node) => node.id.startsWith('merge:') && !node.data.isMuted)).toBe(true)
        expect(result.nodes.find((node) => node.id === 'merge:b')?.data.person.gender).toBe('female')
        expect(result.nodes.every((node) => Number.isFinite(node.position.x))).toBe(true)
      }
    )

    it(
      'deduplicates existing relations and renders new marriage periods in either person order',
      () => {
        const spouse = { ...anna, id: 'boris', givenName: { state: 'known' as const, value: 'Boris' }, gender: 'male' as const }
        const married = { ...snapshot, people: [anna, spouse], marriages: [{ id: 'marriage', malePersonId: 'boris', femalePersonId: 'anna', marriageDate: { state: 'unknown' as const }, dissolutionDate: null, marriagePlace: null, dissolutionPlace: null, createdAt: anna.createdAt, updatedAt: anna.updatedAt }] }
        const people = proposal.people.map((p) => ({ ...p, existingPersonId: p.id === 'b' ? 'boris' : 'anna', resolution: 'existing' as const }))
        const link = { first: 'a', second: 'b', marriageDate: { state: 'unknown' as const }, dissolutionDate: null, newPeriod: false, evidence: [] }
        const result = projectTreeMerge(married, { ...proposal, people, parents: [], marriages: [link] })
        expect(result.edges).toHaveLength(1)
        for (const pair of [['a', 'b'], ['b', 'a']] as const) {
          const fresh = projectTreeMerge({ ...married, marriages: [] }, { ...proposal, people, parents: [], marriages: [{ ...link, first: pair[0], second: pair[1], newPeriod: true }] })
          expect(fresh.edges).toHaveLength(1)
        }
        const linked = projectTreeMerge({ ...snapshot, people: [anna, spouse], parentLinks: [{ id: 'p', parentId: 'boris', childId: 'anna', role: 'father' }] }, { ...proposal, people })
        expect(linked.edges).toHaveLength(1)
      }
    )

    it(
      'ignores invalid relation endpoints while a proposal still needs clarification',
      () => {
        const result = projectTreeMerge(snapshot, { ...proposal, parents: [{ parent: 'a', child: 'a', role: 'mother', evidence: [] }, { parent: 'missing', child: 'a', role: 'mother', evidence: [] }], marriages: [{ first: 'missing', second: 'a', marriageDate: { state: 'unknown' }, dissolutionDate: null, newPeriod: false, evidence: [] }] })
        expect(result.edges).toEqual([])
      }
    )
  }
)
