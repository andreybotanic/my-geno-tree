import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Viewport } from '@xyflow/react'
import { useCanvasCamera } from './useCanvasCamera'

const flow = vi.hoisted(() => ({
  getViewport: vi.fn(),
  setViewport: vi.fn(),
  setCenter: vi.fn(async () => true),
}))
vi.mock('@xyflow/react', () => ({ useReactFlow: () => flow }))

class CanvasObserver {
  static current: CanvasObserver
  disconnect = vi.fn()
  observe = vi.fn()
  private callback: ResizeObserverCallback
  constructor(callback: ResizeObserverCallback) {
    this.callback = callback
    CanvasObserver.current = this
  }
  notify() { this.callback([], this as unknown as ResizeObserver) }
}

describe('canvas camera', () => {
  let viewport: Viewport
  let bounds: DOMRect
  let element: HTMLDivElement

  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('ResizeObserver', CanvasObserver)
    viewport = { x: 800, y: 450, zoom: 2 }
    bounds = new DOMRect(0, 0, 1600, 900)
    element = document.createElement('div')
    vi.spyOn(element, 'getBoundingClientRect').mockImplementation(() => bounds)
    flow.getViewport.mockImplementation(() => viewport)
    flow.setViewport.mockImplementation(async (next: Viewport) => { viewport = next; return true })
  })
  afterEach(() => vi.unstubAllGlobals())

  it.each([1920, 1440])(
    'centers the first selection using the resized %s-pixel canvas before React Flow observes it',
    async (width) => {
      bounds = new DOMRect(0, 0, width, 900)
      const { result } = renderHook(() => useCanvasCamera({ current: element }, true))
      bounds = new DOMRect(390, 0, width - 390, 900)
      await act(async () => { await result.current(300, 100, { zoom: 2, duration: 150 }) })
      // The sidebar's 390px origin shift is cancelled before the animated movement.
      expect(flow.setViewport.mock.calls[0]?.[0]).toEqual({ x: 410, y: 450, zoom: 2 })
      expect(flow.setViewport.mock.calls[0]?.[1]).toBeUndefined()
      expect(flow.setViewport.mock.calls[1]?.[1]).toEqual({ duration: 150 })
      expect(viewport).toEqual({ x: (width - 390) / 2 - 600, y: 250, zoom: 2 })
      expect(flow.setCenter).not.toHaveBeenCalled()
      const calls = flow.setViewport.mock.calls.length
      act(() => CanvasObserver.current.notify())
      expect(flow.setViewport).toHaveBeenCalledTimes(calls)
    },
  )

  it('keeps the first animation frame at the original screen position on both axes', async () => {
    bounds = new DOMRect(0, 50, 1600, 900)
    const { result } = renderHook(() => useCanvasCamera({ current: element }, true))
    bounds = new DOMRect(390, 90, 1210, 860)
    await act(async () => { await result.current(0, 0, { zoom: 2, duration: 150 }) })
    const firstFrame = flow.setViewport.mock.calls[0]![0] as Viewport
    expect(firstFrame.x + bounds.left).toBe(800)
    expect(firstFrame.y + bounds.top).toBe(500)
    expect(viewport).toEqual({ x: 605, y: 430, zoom: 2 })
    expect(flow.setViewport).toHaveBeenCalledTimes(2)
  })

  it('preserves the graph center and zoom at every intermediate chat animation size and on closing', () => {
    renderHook(() => useCanvasCamera({ current: element }, true))
    for (const width of [1580, 1490, 1320, 1120, 1400, 1600]) {
      bounds = new DOMRect(0, 0, width, 900)
      act(() => CanvasObserver.current.notify())
      expect(viewport).toEqual({ x: width / 2, y: 450, zoom: 2 })
    }
    // Repeated notifications do not accumulate a camera drift.
    const calls = flow.setViewport.mock.calls.length
    act(() => CanvasObserver.current.notify())
    expect(flow.setViewport).toHaveBeenCalledTimes(calls)
  })

  it('preserves a panned center at a different zoom when width and height change', () => {
    renderHook(() => useCanvasCamera({ current: element }, true))
    viewport = { x: 120, y: -80, zoom: 0.5 }
    bounds = new DOMRect(390, 50, 1210, 850)
    act(() => CanvasObserver.current.notify())
    expect(viewport).toEqual({ x: -75, y: -105, zoom: 0.5 })
  })

  it('ignores hidden sizes and disconnects the observer on unmount', () => {
    const { unmount } = renderHook(() => useCanvasCamera({ current: element }, true))
    bounds = new DOMRect()
    act(() => CanvasObserver.current.notify())
    expect(flow.setViewport).not.toHaveBeenCalled()
    unmount()
    expect(CanvasObserver.current.disconnect).toHaveBeenCalledOnce()
  })

  it('waits for camera initialization and can center without a measurable canvas', async () => {
    const ref = { current: element as HTMLElement | null }
    const { result, rerender } = renderHook(
      ({ ready }) => useCanvasCamera(ref, ready),
      { initialProps: { ready: false } },
    )
    expect(flow.setViewport).not.toHaveBeenCalled()
    ref.current = null
    rerender({ ready: true })
    await act(async () => { await result.current(0, 0, { zoom: 1, duration: 0 }) })
    expect(flow.setCenter).toHaveBeenCalledWith(0, 0, { zoom: 1, duration: 0 })
    ref.current = element
    bounds = new DOMRect()
    rerender({ ready: false })
    await act(async () => { await result.current(20, 30, { zoom: 1, duration: 0 }) })
    expect(flow.setCenter).toHaveBeenLastCalledWith(20, 30, { zoom: 1, duration: 0 })
  })
})
