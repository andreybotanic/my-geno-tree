import type {
  MarriagePeriod,
  ParentLink,
  TreeReference,
  TreeSnapshot,
} from './api/generated'

type VisibilityIndexes = {
  childrenByParent: Map<string, Set<string>>
  parentsByChild: Map<string, Set<string>>
  spousesByPerson: Map<string, Set<string>>
}

type VisibilityRule = {
  apply: (
    anchorId: string,
    visible: Set<string>,
    indexes: VisibilityIndexes,
  ) => void
  id: string
}

function addAll(target: Set<string>, values: Iterable<string>) {
  for (const value of values) {
    target.add(value)
  }
}

function siblingsOf(personId: string, indexes: VisibilityIndexes) {
  const siblings = new Set<string>()
  for (const parentId of indexes.parentsByChild.get(personId) ?? []) {
    addAll(siblings, indexes.childrenByParent.get(parentId) ?? [])
  }
  siblings.delete(personId)
  return siblings
}

function addChildWithKnownParents(
  childId: string,
  visible: Set<string>,
  indexes: VisibilityIndexes,
) {
  visible.add(childId)
  addAll(visible, indexes.parentsByChild.get(childId) ?? [])
}

export const VISIBILITY_RULES: readonly VisibilityRule[] = [
  {
    id: 'anchor',
    // Adds the reference person themself.
    apply: (anchorId, visible) => visible.add(anchorId),
  },
  {
    id: 'blood-ancestors',
    // Adds every known blood ancestor by recursively following parent links.
    apply: (anchorId, visible, indexes) => {
      const pending = [...(indexes.parentsByChild.get(anchorId) ?? [])]
      while (pending.length > 0) {
        const ancestorId = pending.pop()
        if (!ancestorId || visible.has(ancestorId)) continue
        visible.add(ancestorId)
        pending.push(...(indexes.parentsByChild.get(ancestorId) ?? []))
      }
    },
  },
  {
    id: 'blood-descendants-and-parents',
    // Adds every blood descendant recursively and both known parents of each descendant.
    apply: (anchorId, visible, indexes) => {
      const descendants = new Set<string>()
      const pending = [...(indexes.childrenByParent.get(anchorId) ?? [])]
      while (pending.length > 0) {
        const descendantId = pending.pop()
        if (!descendantId || descendants.has(descendantId)) continue
        descendants.add(descendantId)
        addChildWithKnownParents(descendantId, visible, indexes)
        pending.push(...(indexes.childrenByParent.get(descendantId) ?? []))
      }
    },
  },
  {
    id: 'parents-siblings-and-children',
    // Adds each parent's siblings, all of those siblings' children, and both parents of every such child.
    apply: (anchorId, visible, indexes) => {
      for (const parentId of indexes.parentsByChild.get(anchorId) ?? []) {
        for (const siblingId of siblingsOf(parentId, indexes)) {
          visible.add(siblingId)
          for (const childId of indexes.childrenByParent.get(siblingId) ?? []) {
            addChildWithKnownParents(childId, visible, indexes)
          }
        }
      }
    },
  },
  {
    id: 'siblings-and-spouses',
    // Adds the reference person's full or half siblings and every spouse from every marriage period.
    apply: (anchorId, visible, indexes) => {
      addAll(visible, siblingsOf(anchorId, indexes))
      addAll(visible, indexes.spousesByPerson.get(anchorId) ?? [])
    },
  },
  {
    id: 'spouse-parents-and-siblings',
    // Adds the parents and full or half siblings of each spouse of the reference person.
    apply: (anchorId, visible, indexes) => {
      for (const spouseId of indexes.spousesByPerson.get(anchorId) ?? []) {
        addAll(visible, indexes.parentsByChild.get(spouseId) ?? [])
        addAll(visible, siblingsOf(spouseId, indexes))
      }
    },
  },
  {
    id: 'spouse-other-children',
    // Adds every spouse's children from other partners and both known parents of each child.
    apply: (anchorId, visible, indexes) => {
      for (const spouseId of indexes.spousesByPerson.get(anchorId) ?? []) {
        for (const childId of indexes.childrenByParent.get(spouseId) ?? []) {
          if (childId !== anchorId) {
            addChildWithKnownParents(childId, visible, indexes)
          }
        }
      }
    },
  },
  {
    id: 'siblings-children',
    // Adds every full or half sibling's children and both known parents of each child.
    apply: (anchorId, visible, indexes) => {
      for (const siblingId of siblingsOf(anchorId, indexes)) {
        for (const childId of indexes.childrenByParent.get(siblingId) ?? []) {
          addChildWithKnownParents(childId, visible, indexes)
        }
      }
    },
  },
]

export function visiblePersonIdsFor(
  snapshot: TreeSnapshot,
  anchorId: string,
) {
  const indexes = buildIndexes(snapshot.parentLinks, snapshot.marriages)
  const visible = new Set<string>()
  for (const rule of VISIBILITY_RULES) {
    rule.apply(anchorId, visible, indexes)
  }
  return visible
}

export function focusedTreeSnapshot(snapshot: TreeSnapshot): TreeSnapshot {
  const reference = effectiveReference(snapshot)
  if (!reference) return snapshot

  const visible = reference.type === 'person'
    ? visiblePersonIdsFor(snapshot, reference.personId)
    : union(
        visiblePersonIdsFor(snapshot, reference.personIds[0]),
        visiblePersonIdsFor(snapshot, reference.personIds[1]),
      )

  return {
    ...snapshot,
    people: snapshot.people.filter((person) => visible.has(person.id)),
    parentLinks: snapshot.parentLinks.filter((link) => (
      visible.has(link.parentId) && visible.has(link.childId)
    )),
    marriages: snapshot.marriages.filter((marriage) => (
      visible.has(marriage.malePersonId)
      && visible.has(marriage.femalePersonId)
    )),
  }
}

export function effectiveReference(snapshot: TreeSnapshot): TreeReference | null {
  if (snapshot.tree.reference || snapshot.people.length === 0) {
    return snapshot.tree.reference
  }
  const first = [...snapshot.people].sort((left, right) => (
    left.createdAt.localeCompare(right.createdAt) || left.id.localeCompare(right.id)
  ))[0]
  return first ? { type: 'person', personId: first.id } : null
}

function buildIndexes(
  parentLinks: ParentLink[],
  marriages: MarriagePeriod[],
): VisibilityIndexes {
  const indexes: VisibilityIndexes = {
    childrenByParent: new Map(),
    parentsByChild: new Map(),
    spousesByPerson: new Map(),
  }
  for (const link of parentLinks) {
    addToIndex(indexes.childrenByParent, link.parentId, link.childId)
    addToIndex(indexes.parentsByChild, link.childId, link.parentId)
  }
  for (const marriage of marriages) {
    addToIndex(indexes.spousesByPerson, marriage.malePersonId, marriage.femalePersonId)
    addToIndex(indexes.spousesByPerson, marriage.femalePersonId, marriage.malePersonId)
  }
  return indexes
}

function addToIndex(index: Map<string, Set<string>>, key: string, value: string) {
  const values = index.get(key) ?? new Set<string>()
  values.add(value)
  index.set(key, values)
}

function union(first: Set<string>, second: Set<string>) {
  return new Set([...first, ...second])
}
