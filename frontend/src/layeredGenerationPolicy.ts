export type GenerationCandidate = {
  minimumWidth: number
  personCount: number
  row: number
}

/**
 * Starts from the most populous generation. Deterministic tie-breakers prefer
 * the narrower generation, then the one nearest the reference, then its row.
 */
export function selectAnchorGeneration(
  candidates: readonly GenerationCandidate[],
  referenceRow: number,
): number {
  if (candidates.length === 0) throw new Error('At least one generation is required')

  return [...candidates].sort((first, second) => (
    second.personCount - first.personCount
    || first.minimumWidth - second.minimumWidth
    || Math.abs(first.row - referenceRow) - Math.abs(second.row - referenceRow)
    || first.row - second.row
  ))[0]!.row
}

/**
 * Expands from the anchor through adjacent unplaced generations. When both
 * sides are available, the more populous side wins, then the narrower side;
 * an exact tie is resolved by the upper generation number.
 */
export function scheduleGenerations(
  candidates: readonly GenerationCandidate[],
  anchorRow: number,
): number[] {
  const ordered = [...candidates].sort((first, second) => first.row - second.row)
  const anchorIndex = ordered.findIndex(({ row }) => row === anchorRow)
  if (anchorIndex < 0) throw new Error('Anchor generation must exist')

  const result = [anchorRow]
  let upperIndex = anchorIndex - 1
  let lowerIndex = anchorIndex + 1
  while (upperIndex >= 0 || lowerIndex < ordered.length) {
    const upper = upperIndex >= 0 ? ordered[upperIndex] : undefined
    const lower = lowerIndex < ordered.length ? ordered[lowerIndex] : undefined
    const next = upper === undefined
      ? lower!
      : lower === undefined
        ? upper
        : compareAdjacentCandidates(upper, lower) <= 0 ? upper : lower
    result.push(next.row)
    if (next === upper) upperIndex -= 1
    else lowerIndex += 1
  }
  return result
}

function compareAdjacentCandidates(
  upper: GenerationCandidate,
  lower: GenerationCandidate,
): number {
  return lower.personCount - upper.personCount
    || upper.minimumWidth - lower.minimumWidth
    || upper.row - lower.row
}
