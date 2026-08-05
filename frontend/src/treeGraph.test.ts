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

  it('centers every sibling block below its parents', () => {
    const family = snapshot(
      ['father', 'mother', 'first', 'second', 'third'].map(person),
      ['first', 'second', 'third'].flatMap((childId, index) => [
        link(`father-${index}`, 'father', childId, 'father'),
        link(`mother-${index}`, 'mother', childId, 'mother'),
      ]),
      [marriage('parents', 'father', 'mother')],
      { type: 'marriage', personIds: ['father', 'mother'] },
    )
    const positions = layoutTree(family)
    const parentCenter = (
      positions.get('father')!.x + positions.get('mother')!.x
    ) / 2
    const childCenter = ['first', 'second', 'third'].reduce((sum, id) => (
      sum + positions.get(id)!.x
    ), 0) / 3

    expect(childCenter).toBe(parentCenter)
    expect(positions.get('first')!.y - positions.get('father')!.y).toBe(240)
    expect(positions.get('second')!.y).toBe(positions.get('first')!.y)
    expect(positions.get('third')!.y).toBe(positions.get('first')!.y)
  })

  it('keeps a sibling group contiguous except for their spouses', () => {
    const husbandOne = '019fbcbd-00a0-79a9-866f-65eb99513505'
    const wifeOne = '019fbcbd-0093-7d22-9c21-1b22f51c73fc'
    const husbandTwo = '019fbcbd-0093-703a-9c21-1b22f16454fc'
    const wifeTwo = '019fbcbd-0094-7d86-805a-725c60f7bb58'
    const family = snapshot(
      [
        'parents-father',
        'parents-mother',
        'wife-two-father',
        'wife-two-mother',
        husbandOne,
        husbandTwo,
        wifeOne,
        wifeTwo,
      ].map(person),
      [
        link('father-husband-two', 'parents-father', husbandTwo, 'father'),
        link('mother-husband-two', 'parents-mother', husbandTwo, 'mother'),
        link('father-wife-one', 'parents-father', wifeOne, 'father'),
        link('mother-wife-one', 'parents-mother', wifeOne, 'mother'),
        link('father-wife-two', 'wife-two-father', wifeTwo, 'father'),
        link('mother-wife-two', 'wife-two-mother', wifeTwo, 'mother'),
      ],
      [
        marriage('first-couple', husbandOne, wifeOne),
        marriage('second-couple', husbandTwo, wifeTwo),
      ],
      { type: 'person', personId: wifeOne },
    )
    const positions = layoutTree(family)
    const row = [husbandOne, wifeOne, husbandTwo, wifeTwo]
      .sort((left, right) => positions.get(left)!.x - positions.get(right)!.x)

    const firstSiblingIndex = Math.min(row.indexOf(wifeOne), row.indexOf(husbandTwo))
    const lastSiblingIndex = Math.max(row.indexOf(wifeOne), row.indexOf(husbandTwo))
    expect(row.slice(firstSiblingIndex + 1, lastSiblingIndex).every((id) => (
      id === husbandOne || id === wifeTwo
    ))).toBe(true)
    expect(Math.abs(row.indexOf(husbandOne) - row.indexOf(wifeOne))).toBe(1)
    expect(Math.abs(row.indexOf(husbandTwo) - row.indexOf(wifeTwo))).toBe(1)
  })

  it('preserves the wider gap between half-sibling family blocks', () => {
    const family = snapshot(
      ['shared', 'first-spouse', 'second-spouse', 'first-child', 'second-child'].map(person),
      [
        link('shared-first', 'shared', 'first-child', 'father'),
        link('first-parent', 'first-spouse', 'first-child', 'mother'),
        link('shared-second', 'shared', 'second-child', 'father'),
        link('second-parent', 'second-spouse', 'second-child', 'mother'),
      ],
      [
        marriage('first-marriage', 'shared', 'first-spouse'),
        marriage('second-marriage', 'shared', 'second-spouse'),
      ],
      { type: 'person', personId: 'shared' },
    )
    const positions = layoutTree(family)

    expect(Math.abs(
      positions.get('first-child')!.x - positions.get('second-child')!.x,
    )).toBeGreaterThanOrEqual(380)
  })

  it('keeps every neighbouring card apart in a dense generation', () => {
    const densePeople = ['grandfather', 'grandmother'].map(person)
    const denseLinks: ParentLink[] = []
    const denseMarriages: MarriagePeriod[] = [
      marriage('grandparents', 'grandfather', 'grandmother'),
    ]
    for (let familyIndex = 0; familyIndex < 8; familyIndex += 1) {
      const parentId = `parent-${familyIndex}`
      const spouseId = `spouse-${familyIndex}`
      densePeople.push(person(parentId), person(spouseId))
      denseLinks.push(
        link(`${parentId}-father`, 'grandfather', parentId, 'father'),
        link(`${parentId}-mother`, 'grandmother', parentId, 'mother'),
      )
      denseMarriages.push(marriage(`marriage-${familyIndex}`, parentId, spouseId))
      for (let childIndex = 0; childIndex < 3; childIndex += 1) {
        const childId = `child-${familyIndex}-${childIndex}`
        densePeople.push(person(childId))
        denseLinks.push(
          link(`${childId}-father`, parentId, childId, 'father'),
          link(`${childId}-mother`, spouseId, childId, 'mother'),
        )
      }
    }
    const positions = layoutTree(snapshot(
      densePeople,
      denseLinks,
      denseMarriages,
      { type: 'marriage', personIds: ['grandfather', 'grandmother'] },
    ))
    const rows = new Map<number, Array<{ id: string; x: number }>>()
    for (const [id, position] of positions) {
      const row = rows.get(position.y) ?? []
      row.push({ id, x: position.x })
      rows.set(position.y, row)
    }

    for (const row of rows.values()) {
      const ordered = row.sort((left, right) => left.x - right.x || left.id.localeCompare(right.id))
      for (let index = 1; index < ordered.length; index += 1) {
        expect(ordered[index]!.x - ordered[index - 1]!.x).toBeGreaterThanOrEqual(300)
      }
    }
  })

  it('keeps unrelated family blocks separate on the same generation', () => {
    const family = snapshot(
      [
        'left-father', 'left-mother', 'left-child-1', 'left-child-2',
        'right-father', 'right-mother', 'right-child-1', 'right-child-2',
      ].map(person),
      [
        link('lf-lc1', 'left-father', 'left-child-1', 'father'),
        link('lm-lc1', 'left-mother', 'left-child-1', 'mother'),
        link('lf-lc2', 'left-father', 'left-child-2', 'father'),
        link('lm-lc2', 'left-mother', 'left-child-2', 'mother'),
        link('rf-rc1', 'right-father', 'right-child-1', 'father'),
        link('rm-rc1', 'right-mother', 'right-child-1', 'mother'),
        link('rf-rc2', 'right-father', 'right-child-2', 'father'),
        link('rm-rc2', 'right-mother', 'right-child-2', 'mother'),
      ],
      [
        marriage('left-marriage', 'left-father', 'left-mother'),
        marriage('right-marriage', 'right-father', 'right-mother'),
      ],
      { type: 'person', personId: 'left-father' },
    )
    const positions = layoutTree(family)
    const leftChildren = ['left-child-1', 'left-child-2'].map((id) => positions.get(id)!.x)
    const rightChildren = ['right-child-1', 'right-child-2'].map((id) => positions.get(id)!.x)

    expect(
      Math.max(...leftChildren) < Math.min(...rightChildren)
      || Math.max(...rightChildren) < Math.min(...leftChildren),
    ).toBe(true)
  })

  it('balances one parent between several spouse families', () => {
    const family = snapshot(
      [
        'shared', 'first-spouse', 'second-spouse', 'third-spouse',
        'first-child', 'second-child', 'third-child',
      ].map(person),
      [
        link('shared-first', 'shared', 'first-child', 'mother'),
        link('first-parent', 'first-spouse', 'first-child', 'father'),
        link('shared-second', 'shared', 'second-child', 'mother'),
        link('second-parent', 'second-spouse', 'second-child', 'father'),
        link('shared-third', 'shared', 'third-child', 'mother'),
        link('third-parent', 'third-spouse', 'third-child', 'father'),
      ],
      [
        marriage('first-marriage', 'first-spouse', 'shared'),
        marriage('second-marriage', 'second-spouse', 'shared'),
        marriage('third-marriage', 'third-spouse', 'shared'),
      ],
      { type: 'person', personId: 'shared' },
    )
    const positions = layoutTree(family)
    const children = ['first-child', 'second-child', 'third-child']
      .map((id) => positions.get(id)!.x)
      .sort((left, right) => left - right)

    expect(children[1]! - children[0]!).toBeGreaterThanOrEqual(380)
    expect(children[2]! - children[1]!).toBeGreaterThanOrEqual(380)
    for (const [spouseId, childId] of [
      ['first-spouse', 'first-child'],
      ['second-spouse', 'second-child'],
      ['third-spouse', 'third-child'],
    ] as const) {
      expect(positions.get(childId)!.x).toBe(
        (positions.get('shared')!.x + positions.get(spouseId)!.x) / 2,
      )
    }
  })

  it('stacks multiple partner brackets without crossing them', () => {
    const family = snapshot(
      ['centre', 'left', 'near-right', 'far-right'].map(person),
      [],
      [
        marriage('left-marriage', 'left', 'centre'),
        marriage('near-marriage', 'centre', 'near-right'),
        marriage('far-marriage', 'centre', 'far-right'),
      ],
      { type: 'person', personId: 'centre' },
    )
    const positions = new Map([
      ['left', { x: -320, y: 0 }],
      ['centre', { x: 0, y: 0 }],
      ['near-right', { x: 320, y: 0 }],
      ['far-right', { x: 640, y: 0 }],
    ])
    const edges = new Map(buildTreeEdges(family, positions).map((edge) => [edge.id, edge]))

    expect(edges.get('family:centre:left')?.data).toMatchObject({
      bracketDepth: 24,
      targetOffset: -32,
    })
    expect(edges.get('family:centre:near-right')?.data).toMatchObject({
      bracketDepth: 24,
      sourceOffset: 32,
    })
    expect(edges.get('family:centre:far-right')?.data).toMatchObject({
      bracketDepth: 42,
      sourceOffset: 0,
    })
  })

  it('renders unmarried co-parents as one family and a lone parent separately', () => {
    const family = snapshot(
      ['father', 'mother', 'shared-child', 'single-child'].map(person),
      [
        link('shared-father', 'father', 'shared-child', 'father'),
        link('shared-mother', 'mother', 'shared-child', 'mother'),
        link('single-mother', 'mother', 'single-child', 'mother'),
      ],
      [],
      { type: 'person', personId: 'mother' },
    )
    const positions = layoutTree(family)
    const edges = new Map(buildTreeEdges(family, positions).map((edge) => [edge.id, edge]))

    expect(edges.get('family:father:mother')).toMatchObject({
      data: {
        married: false,
        reference: false,
        childPoints: [expect.objectContaining({ id: 'shared-child' })],
      },
    })
    expect(edges.get('parent:single-mother')).toMatchObject({
      source: 'mother',
      target: 'single-child',
      type: 'singleParent',
    })
  })

  it('omits edges whose visible endpoint is missing', () => {
    const family = snapshot(
      ['father', 'mother', 'child'].map(person),
      [
        link('father-child', 'father', 'child', 'father'),
        link('mother-child', 'mother', 'child', 'mother'),
      ],
      [marriage('parents', 'father', 'mother')],
      { type: 'marriage', personIds: ['father', 'mother'] },
    )

    expect(buildTreeEdges(family, new Map([
      ['father', { x: 0, y: 0 }],
    ]))).toEqual([])
  })

  it('handles an empty tree without inventing positions or edges', () => {
    const empty = snapshot([], [], [], null)

    expect(layoutTree(empty)).toEqual(new Map())
    expect(buildTreeEdges(empty, new Map())).toEqual([])
  })

  it('resolves geometrically tied family outlets by stable identifiers', () => {
    const family = snapshot(
      ['centre', 'alpha', 'beta'].map(person),
      [],
      [
        marriage('alpha-marriage', 'centre', 'alpha'),
        marriage('beta-marriage', 'centre', 'beta'),
      ],
      { type: 'person', personId: 'centre' },
    )
    const positions = new Map([
      ['centre', { x: 0, y: 0 }],
      ['alpha', { x: 320, y: 0 }],
      ['beta', { x: 320, y: 0 }],
    ])

    expect(buildTreeEdges(family, positions).map((edge) => edge.id)).toEqual([
      'family:alpha:centre',
      'family:beta:centre',
    ])
  })
})
