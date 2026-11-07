import { describe, expect, it, vi } from 'vitest'
import { AmplitudeHistory, drawHistogram } from './amplitudeHistory'

describe('recording-aligned audio bars', () => {
  it('freezes completed fragments and moves them continuously without changing their height', () => {
    const history = new AmplitudeHistory()
    expect(history.bars(300, 0)).toEqual([])
    for (let sample = 1; sample <= 10; sample++) history.push(sample === 5 ? 0.75 : 0, sample * 20)
    const initial = history.bars(300, 220)[0]!
    expect(initial).toMatchObject({ id: 10, peak: 0.75, width: 4 })
    for (let sample = 11; sample <= 19; sample++) history.push(1, sample * 20)
    const moving = history.bars(300, 390)
    expect(moving).toHaveLength(1)
    expect(moving[0]).toMatchObject({ id: initial.id, peak: initial.peak })
    expect(moving[0]!.x).toBeLessThan(initial.x)
    history.push(0, 400)
    const completed = history.bars(300, 420)
    expect(completed.map(({ id, peak }) => ({ id, peak }))).toEqual([{ id: 10, peak: 0.75 }, { id: 20, peak: 1 }])
    expect(completed[1]!.x - completed[0]!.x).toBeCloseTo(6)
    const betweenSamples = history.bars(300, 430)
    expect(betweenSamples[0]!.x).toBeCloseTo(completed[0]!.x - 0.3)
    expect(betweenSamples[0]!.peak).toBe(initial.peak)
  })

  it('keeps fragment boundaries and peaks when the viewport is resized', () => {
    const history = new AmplitudeHistory()
    history.bars(300, 0)
    for (let sample = 1; sample <= 30; sample++) history.push(sample / 100, sample * 20)
    const original = history.bars(300, 700)
    const resized = history.bars(600, 700)
    expect(resized.map(({ id, peak }) => ({ id, peak }))).toEqual(original.map(({ id, peak }) => ({ id, peak })))
    expect(resized[0]!.x).toBeCloseTo(original[0]!.x * 2)
    expect(history.bars(0, 700)).toEqual([])
    expect(history.bars(12, 700)[0]!.width).toBeLessThan(1)
  })

  it('waits for completed fragments, clamps invalid amplitudes and resets for another recording', () => {
    const history = new AmplitudeHistory()
    for (let sample = 1; sample <= 9; sample++) history.push(sample === 1 ? 2 : Number.NaN, sample * 20)
    expect(history.bars(300, 190)).toEqual([])
    history.push(-1, 200)
    expect(history.bars(300, 220)[0]!.peak).toBe(1)
    history.clear()
    expect(history.bars(600, 0)).toEqual([])
    for (let sample = 1; sample <= 5; sample++) history.push(0, sample * 20)
    expect(history.bars(600, 120)).toMatchObject([{ id: 5, peak: 0 }])
  })

  it('keeps only recent fragments when initial layout is delayed and expires them as time passes', () => {
    const history = new AmplitudeHistory()
    for (let sample = 1; sample <= 2000; sample++) history.push(0.5, sample * 20)
    const recent = history.bars(300, 40150)
    expect(recent).toHaveLength(50)
    expect(recent[0]!.id).toBe(1510)
    expect(recent.at(-1)!.id).toBe(2000)
    expect(recent.every(({ peak }) => peak === 0.5)).toBe(true)
    expect(history.bars(300, 50500)).toEqual([])
  })

  it('expires old fragments while recording for more than ten seconds', () => {
    const history = new AmplitudeHistory()
    history.bars(300, 0)
    for (let sample = 1; sample <= 2000; sample++) history.push(0.25, sample * 20)
    const recent = history.bars(300, 40150)
    expect(recent).toHaveLength(50)
    expect(recent[0]!.id).toBe(1510)
    expect(recent.every(({ peak }) => peak === 0.25)).toBe(true)
  })

  it('draws centered rectangles of fixed height and spacing while their x coordinates change', () => {
    const history = new AmplitudeHistory()
    history.bars(300, 0)
    for (let sample = 1; sample <= 20; sample++) history.push(sample > 10 ? 0.5 : 0, sample * 20)
    const context = { clearRect: vi.fn(), fillRect: vi.fn(), fillStyle: '' }
    drawHistogram(context as unknown as CanvasRenderingContext2D, history, 300, 48, 'blue', 500)
    expect(context.clearRect).toHaveBeenCalledWith(0, 0, 300, 48)
    expect(context.fillStyle).toBe('blue')
    expect(context.fillRect.mock.calls).toEqual([[291, 23, 4, 2], [297, 12, 4, 24]])
    context.fillRect.mockClear()
    drawHistogram(context as unknown as CanvasRenderingContext2D, history, 300, 48, 'blue', 600)
    expect(context.fillRect.mock.calls).toEqual([[288, 23, 4, 2], [294, 12, 4, 24]])
  })
})
