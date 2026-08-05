import { describe, expect, it } from 'vitest'
import { chooseChildGapDistances } from './childGapPolicy'

describe('child gap policy', () => {
  it('keeps an equal step when the largest reducible interval is 100 px', () => {
    expect(chooseChildGapDistances([300, 400, 350])).toEqual({
      distances: [400, 400, 400],
      variable: false,
    })
  })

  it('shrinks individual intervals when the largest reducible interval is 101 px', () => {
    expect(chooseChildGapDistances([300, 401, 350])).toEqual({
      distances: [300, 401, 350],
      variable: true,
    })
  })

  it('does not manufacture a gap for a single child', () => {
    expect(chooseChildGapDistances([])).toEqual({ distances: [], variable: false })
  })
})
