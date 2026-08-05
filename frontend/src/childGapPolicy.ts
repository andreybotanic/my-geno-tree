export const EXCESS_CHILD_GAP_THRESHOLD = 100

export type ChildGapDecision = {
  distances: number[]
  variable: boolean
}

/**
 * Keeps one common child step until it would waste more than 100 px in at
 * least one interval. The caller has already chosen the best legal child order.
 */
export function chooseChildGapDistances(requiredDistances: number[]): ChildGapDecision {
  if (requiredDistances.length === 0) return { distances: [], variable: false }
  const uniformDistance = Math.max(...requiredDistances)
  const variable = requiredDistances.some((distance) => (
    uniformDistance - distance > EXCESS_CHILD_GAP_THRESHOLD
  ))
  return {
    distances: variable
      ? [...requiredDistances]
      : requiredDistances.map(() => uniformDistance),
    variable,
  }
}
