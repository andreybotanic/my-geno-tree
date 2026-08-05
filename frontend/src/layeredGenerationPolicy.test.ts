import { describe, expect, it } from 'vitest'
import {
  scheduleGenerations,
  selectAnchorGeneration,
  type GenerationCandidate,
} from './layeredGenerationPolicy'

const generation = (
  row: number,
  personCount: number,
  minimumWidth: number,
): GenerationCandidate => ({ minimumWidth, personCount, row })

describe('layered generation policy', () => {
  it('selects the most populous generation before every tie-breaker', () => {
    expect(selectAnchorGeneration([
      generation(-240, 5, 1_500),
      generation(0, 8, 4_000),
      generation(240, 6, 900),
    ], 240)).toBe(0)
  })

  it('uses width, reference distance and row as deterministic tie-breakers', () => {
    const tied = [
      generation(-240, 8, 2_000),
      generation(240, 8, 1_800),
      generation(480, 8, 1_800),
    ]

    expect(selectAnchorGeneration(tied, 480)).toBe(480)
    expect(selectAnchorGeneration(tied, 360)).toBe(240)
  })

  it('walks upward and downward by the larger adjacent generation', () => {
    const candidates = [
      generation(-480, 2, 600),
      generation(-240, 5, 1_500),
      generation(0, 9, 2_700),
      generation(240, 7, 2_100),
      generation(480, 3, 900),
    ]

    expect(scheduleGenerations(candidates, 0)).toEqual([0, 240, -240, 480, -480])
  })
})
