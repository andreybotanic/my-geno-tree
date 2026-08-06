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

  it('keeps the reported reference-marriage ancestry compact and collision-free', () => {
    const ids = {
      paternalGrandfather: '019fbcbd-0086-7a1e-ac6c-761f03d002bb',
      paternalGrandmother: '019fbcbd-0088-78de-9d33-6797dcd07f0e',
      father: '019fbcbd-008b-7698-9334-db2d3ed4f395',
      paternalAunt: '019fbcbd-008d-75d0-86ba-3de949acfdef',
      mother: '019fbcbd-008e-787c-8d4b-cf46060f9964',
      maternalGrandfather: '019fbcbd-0090-73c4-b107-0f99c36d3ab0',
      maternalGrandmother: '019fbcbd-0091-742c-8a5e-6163baa799a6',
      referenceHusband: '019fbcbd-0093-703a-9c21-1b22f16454fc',
      paternalAuntChild: '019fbcbd-0093-7d22-9c21-1b22f51c73fc',
      referenceWife: '019fbcbd-0094-7d86-805a-725c60f7bb58',
      wifeFather: '019fbcbd-0096-76f6-8b4b-b2fc4d0aa6b6',
      wifeMother: '019fbcbd-0097-7216-a613-74c37a9e81e6',
      wifePaternalGrandfather: '019fbcbd-0099-7369-ad52-047dab68da83',
      wifePaternalGrandmother: '019fbcbd-0099-7e25-ad52-047daed4de21',
      wifeMaternalGrandfather: '019fbcbd-009c-7068-af73-5f96d2a36697',
      wifeMaternalGrandmother: '019fbcbd-009c-7e7c-af73-5f96d6a44da4',
      wifeSibling: '019fbcbd-009f-7996-9bcb-585dff2ddcb1',
      paternalAuntPartner: '019fbcbd-00a0-79a9-866f-65eb99513505',
      wifeSiblingPartner: '019fbcbd-00a2-74d2-b404-d602361697d5',
      childOne: '019fbcbd-00a4-73d6-8e03-93ac0255c10e',
      childTwo: '019fbcbd-00a5-7787-8c1a-906c7ea735fd',
      childThree: '019fbcbd-00a6-7660-a311-7178f9da7585',
      cousinOne: '019fbcbd-00a7-7b80-afe1-44ec31f5e6bc',
      cousinTwo: '019fbcbd-00a8-7ea4-89b3-a9850b387fb9',
      niece: '019fbcbd-00aa-71c6-89bd-3a9ff33ab6b0',
    } as const
    const people = Object.values(ids).map(person)
    const parentLinks: ParentLink[] = []
    const addParents = (childId: string, fatherId: string, motherId: string) => {
      parentLinks.push(
        link(`${childId}-father`, fatherId, childId, 'father'),
        link(`${childId}-mother`, motherId, childId, 'mother'),
      )
    }
    addParents(ids.father, ids.paternalGrandfather, ids.paternalGrandmother)
    addParents(ids.paternalAunt, ids.paternalGrandfather, ids.paternalGrandmother)
    addParents(ids.mother, ids.maternalGrandfather, ids.maternalGrandmother)
    addParents(ids.referenceHusband, ids.father, ids.mother)
    addParents(ids.paternalAuntChild, ids.father, ids.mother)
    addParents(ids.referenceWife, ids.wifeFather, ids.wifeMother)
    addParents(ids.wifeFather, ids.wifePaternalGrandfather, ids.wifePaternalGrandmother)
    addParents(ids.wifeMother, ids.wifeMaternalGrandfather, ids.wifeMaternalGrandmother)
    addParents(ids.wifeSibling, ids.wifeFather, ids.wifeMother)
    for (const childId of [ids.childOne, ids.childTwo, ids.childThree]) {
      addParents(childId, ids.referenceHusband, ids.referenceWife)
    }
    for (const childId of [ids.cousinOne, ids.cousinTwo]) {
      addParents(childId, ids.paternalAuntPartner, ids.paternalAuntChild)
    }
    addParents(ids.niece, ids.wifeSiblingPartner, ids.wifeSibling)
    const marriages = [
      marriage('paternal-grandparents', ids.paternalGrandfather, ids.paternalGrandmother),
      marriage('parents', ids.father, ids.mother),
      marriage('maternal-grandparents', ids.maternalGrandfather, ids.maternalGrandmother),
      marriage('reference', ids.referenceHusband, ids.referenceWife),
      marriage('wife-parents', ids.wifeFather, ids.wifeMother),
      marriage(
        'wife-paternal-grandparents',
        ids.wifePaternalGrandfather,
        ids.wifePaternalGrandmother,
      ),
      marriage(
        'wife-maternal-grandparents',
        ids.wifeMaternalGrandfather,
        ids.wifeMaternalGrandmother,
      ),
      marriage('paternal-aunt', ids.paternalAuntPartner, ids.paternalAuntChild),
      marriage('wife-sibling', ids.wifeSiblingPartner, ids.wifeSibling),
    ]
    const tree = snapshot(people, parentLinks, marriages, {
      type: 'marriage',
      personIds: [ids.referenceHusband, ids.referenceWife],
    })
    const positions = layoutTree(tree)
    const maternalParentCenter = (
      positions.get(ids.maternalGrandfather)!.x
      + positions.get(ids.maternalGrandmother)!.x
    ) / 2
    const wifePaternalParentCenter = (
      positions.get(ids.wifePaternalGrandfather)!.x
      + positions.get(ids.wifePaternalGrandmother)!.x
    ) / 2
    const paternalParentCenter = (
      positions.get(ids.paternalGrandfather)!.x
      + positions.get(ids.paternalGrandmother)!.x
    ) / 2
    const wifeMaternalParentCenter = (
      positions.get(ids.wifeMaternalGrandfather)!.x
      + positions.get(ids.wifeMaternalGrandmother)!.x
    ) / 2
    const parentRowShift = (
      paternalParentCenter
      - (positions.get(ids.father)!.x + positions.get(ids.paternalAunt)!.x) / 2
      + maternalParentCenter
      - positions.get(ids.mother)!.x
      + wifePaternalParentCenter
      - positions.get(ids.wifeFather)!.x
      + wifeMaternalParentCenter
      - positions.get(ids.wifeMother)!.x
    )
    expect(parentRowShift).toBeCloseTo(0)
    expect(Math.abs(
      positions.get(ids.paternalGrandfather)!.x - positions.get(ids.father)!.x,
    )).toBeLessThanOrEqual(640)
    expect(Math.abs(
      positions.get(ids.paternalAunt)!.x - positions.get(ids.paternalGrandfather)!.x,
    )).toBeLessThanOrEqual(400)
    const wifeParentCenter = (
      positions.get(ids.wifeFather)!.x + positions.get(ids.wifeMother)!.x
    ) / 2
    const wifeSiblingCenter = (
      positions.get(ids.referenceWife)!.x + positions.get(ids.wifeSibling)!.x
    ) / 2
    const wifeFatherParentCenter = (
      positions.get(ids.wifePaternalGrandfather)!.x
      + positions.get(ids.wifePaternalGrandmother)!.x
    ) / 2
    expect(Math.abs(wifeParentCenter - wifeSiblingCenter)).toBeLessThanOrEqual(20)
    expect(Math.abs(
      wifeFatherParentCenter - positions.get(ids.wifeFather)!.x,
    )).toBeLessThan(Math.abs(
      wifeFatherParentCenter - positions.get(ids.wifeMother)!.x,
    ))
    for (const [firstId, first] of positions) {
      for (const [secondId, second] of positions) {
        if (firstId >= secondId) continue
        const overlaps = first.x < second.x + PERSON_CARD_WIDTH
          && first.x + PERSON_CARD_WIDTH > second.x
          && first.y < second.y + PERSON_CARD_HEIGHT
          && first.y + PERSON_CARD_HEIGHT > second.y
        expect(overlaps, `${firstId} overlaps ${secondId}`).toBe(false)
        if (first.y === second.y) {
          expect(Math.abs(first.x - second.x)).toBeGreaterThanOrEqual(
            PERSON_CARD_WIDTH + 40,
          )
        }
      }
    }
    expect([...layoutTree(snapshot(
      [...people].reverse(),
      [...parentLinks].reverse(),
      [...marriages].reverse(),
      tree.tree.reference,
    ))]).toEqual([...positions])
  })

  it('lays out a complete ancestor tree without overlaps or input-order drift', () => {
    const people = [person('reference')]
    const parentLinks: ParentLink[] = []
    const marriages: MarriagePeriod[] = []
    let children = ['reference']
    const generations = [children]

    for (let generation = 1; generation < 6; generation += 1) {
      const parents = children.flatMap((childId, childIndex) => {
        const fatherId = `generation-${generation}-${childIndex * 2}`
        const motherId = `generation-${generation}-${childIndex * 2 + 1}`
        people.push(person(fatherId), person(motherId))
        parentLinks.push(
          link(`${childId}-father`, fatherId, childId, 'father'),
          link(`${childId}-mother`, motherId, childId, 'mother'),
        )
        marriages.push(marriage(`${childId}-parents`, fatherId, motherId))
        return [fatherId, motherId]
      })
      generations.push(parents)
      children = parents
    }

    const tree = snapshot(people, parentLinks, marriages, {
      type: 'person',
      personId: 'reference',
    })
    const positions = layoutTree(tree)

    for (const generation of generations) {
      const ordered = [...generation]
        .sort((first, second) => positions.get(first)!.x - positions.get(second)!.x)
      for (let index = 1; index < ordered.length; index += 1) {
        expect(
          positions.get(ordered[index]!)!.x - positions.get(ordered[index - 1]!)!.x,
        ).toBeGreaterThanOrEqual(PERSON_CARD_WIDTH + 40)
      }
    }

    expect([...layoutTree(snapshot(
      [...people].reverse(),
      [...parentLinks].reverse(),
      [...marriages].reverse(),
      tree.tree.reference,
    ))]).toEqual([...positions])
  })

  it('keeps the 101-person wide-tree regression collision-free and deterministic', () => {
    const people = ['reference', 'father', 'mother', 'paternal-grandfather',
      'paternal-grandmother', 'maternal-grandfather', 'maternal-grandmother'].map(person)
    const parentLinks: ParentLink[] = [
      link('reference-father', 'father', 'reference', 'father'),
      link('reference-mother', 'mother', 'reference', 'mother'),
      link('father-father', 'paternal-grandfather', 'father', 'father'),
      link('father-mother', 'paternal-grandmother', 'father', 'mother'),
      link('mother-father', 'maternal-grandfather', 'mother', 'father'),
      link('mother-mother', 'maternal-grandmother', 'mother', 'mother'),
    ]
    const marriages = [
      marriage('parents', 'father', 'mother'),
      marriage('paternal-grandparents', 'paternal-grandfather', 'paternal-grandmother'),
      marriage('maternal-grandparents', 'maternal-grandfather', 'maternal-grandmother'),
    ]
    const cousinBranches: string[][] = []

    for (const side of ['paternal', 'maternal']) {
      const grandfather = `${side}-grandfather`
      const grandmother = `${side}-grandmother`
      const cousins: string[] = []
      for (let branch = 1; branch <= 4; branch += 1) {
        const sibling = `${side}-sibling-${branch}`
        const spouse = `${sibling}-spouse`
        people.push(person(sibling), person(spouse))
        parentLinks.push(
          link(`${sibling}-father`, grandfather, sibling, 'father'),
          link(`${sibling}-mother`, grandmother, sibling, 'mother'),
        )
        marriages.push(marriage(`${sibling}-marriage`, sibling, spouse))
        for (let child = 1; child <= 4; child += 1) {
          const cousin = `${sibling}-child-${child}`
          cousins.push(cousin)
          people.push(person(cousin))
          parentLinks.push(
            link(`${cousin}-father`, sibling, cousin, 'father'),
            link(`${cousin}-mother`, spouse, cousin, 'mother'),
          )
        }
      }
      cousinBranches.push(cousins)
    }

    for (let branch = 1; branch <= 8; branch += 1) {
      const sibling = `sibling-${branch}`
      const spouse = `${sibling}-spouse`
      people.push(person(sibling), person(spouse))
      parentLinks.push(
        link(`${sibling}-father`, 'father', sibling, 'father'),
        link(`${sibling}-mother`, 'mother', sibling, 'mother'),
      )
      marriages.push(marriage(`${sibling}-marriage`, sibling, spouse))
      for (let child = 1; child <= 2; child += 1) {
        const nephew = `${sibling}-child-${child}`
        people.push(person(nephew))
        parentLinks.push(
          link(`${nephew}-father`, sibling, nephew, 'father'),
          link(`${nephew}-mother`, spouse, nephew, 'mother'),
        )
      }
    }

    people.push(person('reference-spouse'))
    marriages.push(marriage('reference-marriage', 'reference', 'reference-spouse'))
    for (let child = 1; child <= 7; child += 1) {
      const childId = `reference-child-${child}`
      people.push(person(childId))
      parentLinks.push(
        link(`${childId}-father`, 'reference', childId, 'father'),
        link(`${childId}-mother`, 'reference-spouse', childId, 'mother'),
      )
      if (child <= 3) {
        const spouse = `${childId}-spouse`
        const grandchild = `${childId}-child`
        people.push(person(spouse), person(grandchild))
        marriages.push(marriage(`${childId}-marriage`, childId, spouse))
        parentLinks.push(
          link(`${grandchild}-father`, childId, grandchild, 'father'),
          link(`${grandchild}-mother`, spouse, grandchild, 'mother'),
        )
      }
    }

    expect(people).toHaveLength(101)
    const tree = snapshot(people, parentLinks, marriages, {
      type: 'person', personId: 'reference',
    })
    const positions = layoutTree(tree)
    for (const [firstId, first] of positions) {
      for (const [secondId, second] of positions) {
        if (firstId >= secondId) continue
        const overlaps = first.x < second.x + PERSON_CARD_WIDTH
          && first.x + PERSON_CARD_WIDTH > second.x
          && first.y < second.y + PERSON_CARD_HEIGHT
          && first.y + PERSON_CARD_HEIGHT > second.y
        expect(overlaps, `${firstId} overlaps ${secondId}`).toBe(false)
      }
    }
    const branchSpans = cousinBranches.map((ids) => ({
      left: Math.min(...ids.map((id) => positions.get(id)!.x)),
      right: Math.max(...ids.map((id) => positions.get(id)!.x + PERSON_CARD_WIDTH)),
    }))
    expect(
      branchSpans[0]!.right <= branchSpans[1]!.left
      || branchSpans[1]!.right <= branchSpans[0]!.left,
    ).toBe(true)
    for (const cousins of cousinBranches) {
      for (let branch = 0; branch < cousins.length; branch += 4) {
        const childCenters = cousins.slice(branch, branch + 4)
          .map((id) => positions.get(id)!.x + PERSON_CARD_WIDTH / 2)
          .sort((left, right) => left - right)
        expect(childCenters.slice(1).map((center, index) => (
          center - childCenters[index]!
        ))).toEqual([300, 300, 300])
      }
    }
    expect([...layoutTree(snapshot(
      [...people].reverse(),
      [...parentLinks].reverse(),
      [...marriages].reverse(),
      tree.tree.reference,
    ))]).toEqual([...positions])
  }, 15_000)

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

  it('compacts narrow child intervals around one wide descendant branch', () => {
    const childIds = ['child-a', 'child-b', 'child-c', 'child-d']
    const grandchildIds = Array.from({ length: 6 }, (_, index) => `grandchild-${index}`)
    const family = snapshot(
      [
        'father', 'mother', ...childIds, 'wide-child-spouse', ...grandchildIds,
      ].map(person),
      [
        ...childIds.flatMap((childId, index) => [
          link(`father-${index}`, 'father', childId, 'father'),
          link(`mother-${index}`, 'mother', childId, 'mother'),
        ]),
        ...grandchildIds.flatMap((childId, index) => [
          link(`wide-${index}`, 'child-b', childId, 'father'),
          link(`spouse-${index}`, 'wide-child-spouse', childId, 'mother'),
        ]),
      ],
      [
        marriage('parents', 'father', 'mother'),
        marriage('wide-child', 'child-b', 'wide-child-spouse'),
      ],
      { type: 'marriage', personIds: ['father', 'mother'] },
    )
    const positions = layoutTree(family)
    const orderedChildren = [...childIds].sort((first, second) => (
      positions.get(first)!.x - positions.get(second)!.x
    ))
    const gaps = orderedChildren.slice(1).map((id, index) => (
      positions.get(id)!.x - positions.get(orderedChildren[index]!)!.x
    ))
    const parentCenter = (positions.get('father')!.x + positions.get('mother')!.x) / 2
    const extremeChildCenter = (
      positions.get(orderedChildren[0]!)!.x
      + positions.get(orderedChildren.at(-1)!)!.x
    ) / 2

    expect(Math.max(...gaps) - Math.min(...gaps)).toBeGreaterThan(100)
    expect(parentCenter).toBeCloseTo(extremeChildCenter)
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
    )).toBeGreaterThanOrEqual(320)
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

  it('keeps wide descendant blocks of siblings from interleaving', () => {
    const ids = [
      'grandfather', 'grandmother',
      'sibling-a', 'spouse-a', 'sibling-b', 'spouse-b',
      'a-child-1', 'a-child-2', 'b-child-1', 'b-child-2',
      'a-inlaw-1', 'a-inlaw-2', 'b-inlaw-1', 'b-inlaw-2',
      'a-grandchild-1', 'a-grandchild-2', 'a-grandchild-3', 'a-grandchild-4',
      'b-grandchild-1', 'b-grandchild-2', 'b-grandchild-3', 'b-grandchild-4',
    ]
    const parentLinks = [
      ...['sibling-a', 'sibling-b'].flatMap((childId) => [
        link(`grandfather-${childId}`, 'grandfather', childId, 'father'),
        link(`grandmother-${childId}`, 'grandmother', childId, 'mother'),
      ]),
      ...['a-child-1', 'a-child-2'].flatMap((childId) => [
        link(`sibling-a-${childId}`, 'sibling-a', childId, 'father'),
        link(`spouse-a-${childId}`, 'spouse-a', childId, 'mother'),
      ]),
      ...['b-child-1', 'b-child-2'].flatMap((childId) => [
        link(`sibling-b-${childId}`, 'sibling-b', childId, 'father'),
        link(`spouse-b-${childId}`, 'spouse-b', childId, 'mother'),
      ]),
      ...[1, 2].flatMap((familyIndex) => [1, 2].flatMap((childIndex) => [
        link(
          `a-child-${familyIndex}-grandchild-${childIndex}`,
          `a-child-${familyIndex}`,
          `a-grandchild-${(familyIndex - 1) * 2 + childIndex}`,
          'father',
        ),
        link(
          `a-inlaw-${familyIndex}-grandchild-${childIndex}`,
          `a-inlaw-${familyIndex}`,
          `a-grandchild-${(familyIndex - 1) * 2 + childIndex}`,
          'mother',
        ),
        link(
          `b-child-${familyIndex}-grandchild-${childIndex}`,
          `b-child-${familyIndex}`,
          `b-grandchild-${(familyIndex - 1) * 2 + childIndex}`,
          'father',
        ),
        link(
          `b-inlaw-${familyIndex}-grandchild-${childIndex}`,
          `b-inlaw-${familyIndex}`,
          `b-grandchild-${(familyIndex - 1) * 2 + childIndex}`,
          'mother',
        ),
      ])),
    ]
    const marriages = [
      marriage('grandparents', 'grandfather', 'grandmother'),
      marriage('sibling-a-marriage', 'sibling-a', 'spouse-a'),
      marriage('sibling-b-marriage', 'sibling-b', 'spouse-b'),
      marriage('a-child-1-marriage', 'a-child-1', 'a-inlaw-1'),
      marriage('a-child-2-marriage', 'a-child-2', 'a-inlaw-2'),
      marriage('b-child-1-marriage', 'b-child-1', 'b-inlaw-1'),
      marriage('b-child-2-marriage', 'b-child-2', 'b-inlaw-2'),
    ]
    const tree = snapshot(
      ids.map(person),
      parentLinks,
      marriages,
      { type: 'marriage', personIds: ['grandfather', 'grandmother'] },
    )
    const positions = layoutTree(tree)
    const branch = (prefix: 'a' | 'b') => ids.filter((id) => (
      id === `sibling-${prefix}`
      || id === `spouse-${prefix}`
      || id.startsWith(`${prefix}-`)
    ))
    const range = (personIds: string[]) => ({
      left: Math.min(...personIds.map((id) => positions.get(id)!.x)),
      right: Math.max(...personIds.map((id) => positions.get(id)!.x)),
    })
    const first = range(branch('a'))
    const second = range(branch('b'))
    const separated = first.right + 300 <= second.left
      || second.right + 300 <= first.left
    const cousinOrder = [...ids]
      .filter((id) => id.includes('grandchild'))
      .sort((left, right) => positions.get(left)!.x - positions.get(right)!.x)
      .map((id) => id[0])
    const transitions = cousinOrder.slice(1).filter((value, index) => (
      value !== cousinOrder[index]
    ))

    expect(separated).toBe(true)
    expect(transitions).toHaveLength(1)
    expect([...layoutTree(tree)]).toEqual([...positions])
  })

  it('keeps descendant blocks of half-siblings separate', () => {
    const family = snapshot(
      [
        'shared-parent', 'first-parent', 'second-parent',
        'first-sibling', 'second-sibling',
        'first-partner', 'second-partner',
        'first-child-1', 'first-child-2',
        'second-child-1', 'second-child-2',
      ].map(person),
      [
        link('shared-first', 'shared-parent', 'first-sibling', 'father'),
        link('first-first', 'first-parent', 'first-sibling', 'mother'),
        link('shared-second', 'shared-parent', 'second-sibling', 'father'),
        link('second-second', 'second-parent', 'second-sibling', 'mother'),
        ...['first-child-1', 'first-child-2'].flatMap((childId) => [
          link(`first-sibling-${childId}`, 'first-sibling', childId, 'father'),
          link(`first-partner-${childId}`, 'first-partner', childId, 'mother'),
        ]),
        ...['second-child-1', 'second-child-2'].flatMap((childId) => [
          link(`second-sibling-${childId}`, 'second-sibling', childId, 'father'),
          link(`second-partner-${childId}`, 'second-partner', childId, 'mother'),
        ]),
      ],
      [
        marriage('first-family', 'first-sibling', 'first-partner'),
        marriage('second-family', 'second-sibling', 'second-partner'),
      ],
      { type: 'person', personId: 'shared-parent' },
    )
    const positions = layoutTree(family)
    const firstRight = Math.max(...[
      'first-sibling', 'first-partner', 'first-child-1', 'first-child-2',
    ].map((id) => positions.get(id)!.x))
    const secondLeft = Math.min(...[
      'second-sibling', 'second-partner', 'second-child-1', 'second-child-2',
    ].map((id) => positions.get(id)!.x))

    expect(secondLeft - firstRight).toBeGreaterThanOrEqual(300)
    expect([...layoutTree(snapshot(
      [...family.people].reverse(),
      [...family.parentLinks].reverse(),
      [...family.marriages].reverse(),
      family.tree.reference,
    ))]).toEqual([...positions])
  })

  it('orients two ancestral sibling branches towards their connecting marriage', () => {
    const people = [
      'paternal-grandfather', 'paternal-grandmother',
      'maternal-grandfather', 'maternal-grandmother',
      'father', 'mother', 'proband',
      'paternal-relative-1', 'paternal-spouse-1',
      'paternal-relative-2', 'paternal-spouse-2',
      'maternal-relative-1', 'maternal-spouse-1',
      'maternal-relative-2', 'maternal-spouse-2',
      'paternal-cousin-1-1', 'paternal-cousin-1-2',
      'paternal-cousin-2-1', 'paternal-cousin-2-2',
      'maternal-cousin-1-1', 'maternal-cousin-1-2',
      'maternal-cousin-2-1', 'maternal-cousin-2-2',
    ].map(person)
    const parentLinks: ParentLink[] = [
      ...['father', 'paternal-relative-1', 'paternal-relative-2'].flatMap((childId) => [
        link(`paternal-grandfather-${childId}`, 'paternal-grandfather', childId, 'father'),
        link(`paternal-grandmother-${childId}`, 'paternal-grandmother', childId, 'mother'),
      ]),
      ...['mother', 'maternal-relative-1', 'maternal-relative-2'].flatMap((childId) => [
        link(`maternal-grandfather-${childId}`, 'maternal-grandfather', childId, 'father'),
        link(`maternal-grandmother-${childId}`, 'maternal-grandmother', childId, 'mother'),
      ]),
      link('father-proband', 'father', 'proband', 'father'),
      link('mother-proband', 'mother', 'proband', 'mother'),
    ]
    for (const branch of ['paternal', 'maternal'] as const) {
      for (const familyIndex of [1, 2]) {
        for (const childIndex of [1, 2]) {
          const childId = `${branch}-cousin-${familyIndex}-${childIndex}`
          parentLinks.push(
            link(
              `${branch}-relative-${familyIndex}-${childId}`,
              `${branch}-relative-${familyIndex}`,
              childId,
              'father',
            ),
            link(
              `${branch}-spouse-${familyIndex}-${childId}`,
              `${branch}-spouse-${familyIndex}`,
              childId,
              'mother',
            ),
          )
        }
      }
    }
    const marriages = [
      marriage('paternal-grandparents', 'paternal-grandfather', 'paternal-grandmother'),
      marriage('maternal-grandparents', 'maternal-grandfather', 'maternal-grandmother'),
      marriage('parents', 'father', 'mother'),
      ...(['paternal', 'maternal'] as const).flatMap((branch) => [1, 2].map((index) => (
        marriage(
          `${branch}-relative-marriage-${index}`,
          `${branch}-relative-${index}`,
          `${branch}-spouse-${index}`,
        )
      ))),
    ]
    const tree = snapshot(
      people,
      parentLinks,
      marriages,
      { type: 'person', personId: 'proband' },
    )
    const positions = layoutTree(tree)
    const paternalCousins = people.filter((item) => item.id.startsWith('paternal-cousin'))
    const maternalCousins = people.filter((item) => item.id.startsWith('maternal-cousin'))
    const paternalRight = Math.max(...paternalCousins.map((item) => positions.get(item.id)!.x))
    const maternalLeft = Math.min(...maternalCousins.map((item) => positions.get(item.id)!.x))

    expect(paternalRight).toBeLessThan(maternalLeft)
    expect(positions.get('father')!.x).toBeLessThan(positions.get('mother')!.x)
    expect(positions.get('mother')!.x - positions.get('father')!.x).toBe(320)
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

    expect(children[1]! - children[0]!).toBeGreaterThanOrEqual(320)
    expect(children[2]! - children[1]!).toBeGreaterThanOrEqual(320)
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

  it('compresses nested parent brackets above their children branching rail', () => {
    const family = snapshot(
      ['shared', 'near-spouse', 'far-spouse', 'near-child', 'far-child'].map(person),
      [
        link('shared-near', 'shared', 'near-child', 'mother'),
        link('near-parent', 'near-spouse', 'near-child', 'father'),
        link('shared-far', 'shared', 'far-child', 'mother'),
        link('far-parent', 'far-spouse', 'far-child', 'father'),
      ],
      [
        marriage('near-marriage', 'near-spouse', 'shared'),
        marriage('far-marriage', 'far-spouse', 'shared'),
      ],
      { type: 'person', personId: 'shared' },
    )
    const positions = new Map([
      ['shared', { x: 0, y: 0 }],
      ['near-spouse', { x: 320, y: 0 }],
      ['far-spouse', { x: 640, y: 0 }],
      ['near-child', { x: 320, y: 240 }],
      ['far-child', { x: 640, y: 240 }],
    ])
    const edges = new Map(buildTreeEdges(family, positions).map((edge) => [edge.id, edge]))

    expect(edges.get('family:near-spouse:shared')?.data?.bracketDepth).toBe(24)
    expect(edges.get('family:far-spouse:shared')?.data?.bracketDepth).toBe(40)
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
