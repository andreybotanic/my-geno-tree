import { describe, expect, it } from 'vitest'
import type { Person, SearchRoutePlan } from './api/generated'
import {
  PERSON_CARD_HEIGHT,
  PERSON_CARD_WIDTH,
} from './treeGraph'
import { projectSearchRoute } from './searchRouteProjection'

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
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
})

describe('projectSearchRoute', () => {
  it('keeps visible cards fixed and places all overlay cards without intersections', () => {
    const visible = [person('reference'), person('nearby')]
    const base = new Map([
      ['reference', { x: 0, y: 0 }],
      ['nearby', { x: 320, y: -240 }],
    ])
    const plan: SearchRoutePlan = {
      etag: '"tree"',
      targetPersonId: 'grandmother',
      people: [person('reference'), person('father'), person('mother'), person('grandfather'), person('grandmother')],
      nodes: [
        { personId: 'reference', generation: 0, kind: 'route' },
        { personId: 'father', generation: -1, kind: 'support' },
        { personId: 'mother', generation: -1, kind: 'route' },
        { personId: 'grandfather', generation: -2, kind: 'support' },
        { personId: 'grandmother', generation: -2, kind: 'route' },
      ],
      parentLinks: [],
      marriages: [],
      familyBlocks: [],
    }

    const projection = projectSearchRoute(plan, visible, base)
    expect(projection.positions.get('reference')).toEqual({ x: 0, y: 0 })
    expect(projection.positions.get('nearby')).toEqual({ x: 320, y: -240 })
    expect(projection.overlayPeople.map((item) => item.id).sort()).toEqual([
      'father', 'grandfather', 'grandmother', 'mother',
    ])
    expect(projection.positions.get('mother')!.y).toBeLessThan(
      projection.positions.get('reference')!.y,
    )
    expect(projection.positions.get('grandmother')!.y).toBeLessThan(
      projection.positions.get('mother')!.y,
    )
    const rectangles = [...projection.positions.values()].map((position) => ({
      ...position,
      height: PERSON_CARD_HEIGHT,
      width: PERSON_CARD_WIDTH,
    }))
    for (const [index, first] of rectangles.entries()) {
      for (const second of rectangles.slice(index + 1)) {
        expect(
          first.x < second.x + second.width
          && first.x + first.width > second.x
          && first.y < second.y + second.height
          && first.y + first.height > second.y,
        ).toBe(false)
      }
    }
  })

  it('centres an added child under both displayed parents and emphasizes supporting parents', () => {
    const visible = [person('father'), person('mother'), person('unrelated')]
    const base = new Map([
      ['father', { x: 0, y: -240 }],
      ['mother', { x: 320, y: -240 }],
      ['unrelated', { x: 960, y: 0 }],
    ])
    const plan: SearchRoutePlan = {
      etag: '"tree"',
      targetPersonId: 'child',
      people: [...visible, person('child')],
      nodes: [
        { personId: 'father', generation: -1, kind: 'route' },
        { personId: 'mother', generation: -1, kind: 'support' },
        { personId: 'child', generation: 0, kind: 'route' },
      ],
      parentLinks: [
        { id: 'father-child', parentId: 'father', childId: 'child', role: 'father' },
        { id: 'mother-child', parentId: 'mother', childId: 'child', role: 'mother' },
      ],
      marriages: [],
      familyBlocks: [{
        id: 'parents',
        personIds: ['father', 'mother'],
        childIds: ['child'],
        married: false,
      }],
    }

    const projection = projectSearchRoute(plan, visible, base)
    const child = projection.positions.get('child')!
    expect(child.x + PERSON_CARD_WIDTH / 2).toBe(
      ((base.get('father')!.x + PERSON_CARD_WIDTH / 2)
        + (base.get('mother')!.x + PERSON_CARD_WIDTH / 2)) / 2,
    )
    expect(projection.routePersonIds).toEqual(new Set(['father', 'mother', 'child']))
  })

  it('keeps a displayed child centred under an added parent pair', () => {
    const visible = [person('child')]
    const base = new Map([['child', { x: 0, y: 0 }]])
    const plan: SearchRoutePlan = {
      etag: '"tree"',
      targetPersonId: 'father',
      people: [...visible, person('father'), person('mother')],
      nodes: [
        { personId: 'father', generation: -1, kind: 'route' },
        { personId: 'mother', generation: -1, kind: 'support' },
      ],
      parentLinks: [
        { id: 'father-child', parentId: 'father', childId: 'child', role: 'father' },
        { id: 'mother-child', parentId: 'mother', childId: 'child', role: 'mother' },
      ],
      marriages: [],
      familyBlocks: [{
        id: 'parents',
        personIds: ['father', 'mother'],
        childIds: ['child'],
        married: false,
      }],
    }

    const projection = projectSearchRoute(plan, visible, base)
    const father = projection.positions.get('father')!
    const mother = projection.positions.get('mother')!
    expect((father.x + mother.x + PERSON_CARD_WIDTH) / 2).toBe(
      base.get('child')!.x + PERSON_CARD_WIDTH / 2,
    )
    expect(father).not.toEqual(mother)
  })

  it('follows parent links from the visible child instead of trusting route generations', () => {
    const visible = [person('child')]
    const base = new Map([['child', { x: 0, y: 0 }]])
    const plan: SearchRoutePlan = {
      etag: '"tree"',
      targetPersonId: 'grandparent',
      people: [...visible, person('parent'), person('grandparent')],
      nodes: [
        { personId: 'child', generation: 0, kind: 'route' },
        { personId: 'parent', generation: 30, kind: 'route' },
        { personId: 'grandparent', generation: 60, kind: 'route' },
      ],
      parentLinks: [
        { id: 'parent-child', parentId: 'parent', childId: 'child', role: 'mother' },
        { id: 'grandparent-parent', parentId: 'grandparent', childId: 'parent', role: 'mother' },
      ],
      marriages: [],
      familyBlocks: [],
    }

    const projection = projectSearchRoute(plan, visible, base)
    const parent = projection.positions.get('parent')!
    const grandparent = projection.positions.get('grandparent')!
    expect(parent.y).toBeLessThan(base.get('child')!.y)
    expect(grandparent.y).toBeLessThan(parent.y)
  })
})
