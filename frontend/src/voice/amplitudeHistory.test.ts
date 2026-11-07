import { describe, expect, it, vi } from 'vitest'
import { AmplitudeHistory, drawHistogram } from './amplitudeHistory'

describe('ten-second audio history', () => {
  it('starts silent, moves new peaks from right to left, and expires them after 500 samples', () => {
    const history = new AmplitudeHistory()
    expect(history.bars(300).every((value) => value === 0)).toBe(true)
    history.push(0.75)
    expect(history.bars(300).at(-1)).toBe(0.75)
    for (let i = 0; i < 10; i++) history.push(0)
    expect(history.bars(300).at(-2)).toBe(0.75)
    for (let i = 0; i < 490; i++) history.push(0)
    expect(history.bars(300).every((value) => value === 0)).toBe(true)
  })

  it('groups peaks for responsive widths, clamps bad input, and resets between recordings', () => {
    const history = new AmplitudeHistory()
    history.push(2)
    history.push(Number.NaN)
    history.push(-1)
    expect(history.bars(12)).toEqual([0, 1])
    expect(history.bars(0)).toEqual([1])
    expect(history.bars(6000)).toHaveLength(500)
    history.clear()
    expect(history.bars(12)).toEqual([0, 0])
  })

  it('draws separated four-pixel rectangles centered on the same horizontal axis', () => {
    const history = new AmplitudeHistory()
    history.push(0.5)
    const context = { clearRect: vi.fn(), fillRect: vi.fn(), fillStyle: '' }
    drawHistogram(context as unknown as CanvasRenderingContext2D, history, 12, 48, 'blue')
    expect(context.clearRect).toHaveBeenCalledWith(0, 0, 12, 48)
    expect(context.fillStyle).toBe('blue')
    expect(context.fillRect.mock.calls).toEqual([[1, 23, 4, 2], [7, 12, 4, 24]])
    drawHistogram(context as unknown as CanvasRenderingContext2D, history, 2, 48, 'blue')
    expect(context.fillRect).toHaveBeenLastCalledWith(0, 12, 2, 24)
  })
})
