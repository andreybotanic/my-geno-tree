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
})
