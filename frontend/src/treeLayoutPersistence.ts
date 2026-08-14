import type {
  SaveTreeLayoutRequest,
  TreeSnapshot,
} from './api/generated'
import { layoutTree } from './treeGraph'

export const TREE_LAYOUT_ALGORITHM_VERSION = 1
export const TREE_LAYOUT_PERSISTENCE_THRESHOLD_MS = 2_000

const MAX_ABSOLUTE_COORDINATE = 1_000_000

export type TreePosition = { x: number, y: number }

export type TreeLayoutResolution = {
  positions: Map<string, TreePosition>
  saveRequest: SaveTreeLayoutRequest | null
}

export function treeLayoutCacheKey(snapshot: TreeSnapshot) {
  return [
    snapshot.tree.id,
    snapshot.layoutRevision ?? 'none',
    snapshot.layout?.algorithmVersion ?? 'none',
  ].join(':')
}

export function resolveTreeLayout(
  snapshot: TreeSnapshot,
  focusedSnapshot: TreeSnapshot,
  now: () => number = () => performance.now(),
): TreeLayoutResolution {
  const restored = restoreTreeLayout(snapshot, focusedSnapshot)
  if (restored) {
    return { positions: restored, saveRequest: null }
  }

  const startedAt = now()
  const positions = layoutTree(focusedSnapshot)
  const elapsed = now() - startedAt
  if (
    snapshot.layoutRevision === null
    || elapsed < TREE_LAYOUT_PERSISTENCE_THRESHOLD_MS
  ) {
    return { positions, saveRequest: null }
  }

  return {
    positions,
    saveRequest: {
      layoutRevision: snapshot.layoutRevision,
      algorithmVersion: TREE_LAYOUT_ALGORITHM_VERSION,
      positions: Object.fromEntries([...positions]
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([personId, position]) => [personId, [position.x, position.y]])),
    },
  }
}

export function restoreTreeLayout(
  snapshot: TreeSnapshot,
  focusedSnapshot: TreeSnapshot,
): Map<string, TreePosition> | null {
  if (
    snapshot.layout === null
    || snapshot.layout.algorithmVersion !== TREE_LAYOUT_ALGORITHM_VERSION
  ) {
    return null
  }

  const expectedIds = focusedSnapshot.people
    .map((person) => person.id)
    .sort((left, right) => left.localeCompare(right))
  const actualIds = Object.keys(snapshot.layout.positions)
    .sort((left, right) => left.localeCompare(right))
  if (
    expectedIds.length !== actualIds.length
    || expectedIds.some((personId, index) => personId !== actualIds[index])
  ) {
    return null
  }

  const positions = new Map<string, TreePosition>()
  for (const personId of expectedIds) {
    const coordinates = snapshot.layout.positions[personId]
    if (
      !coordinates
      || coordinates.length !== 2
      || !coordinates.every((coordinate) => (
        Number.isFinite(coordinate)
        && Math.abs(coordinate) <= MAX_ABSOLUTE_COORDINATE
      ))
    ) {
      return null
    }
    positions.set(personId, { x: coordinates[0], y: coordinates[1] })
  }

  return positions
}
