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
      expect(viewport).toEqual({ x: (width - 390) / 2 - 600, y: 250, zoom: 2 })
      expect(flow.setCenter).not.toHaveBeenCalled()
      const calls = flow.setViewport.mock.calls.length
      act(() => CanvasObserver.current.notify())
      expect(flow.setViewport).toHaveBeenCalledTimes(calls)
    },
  )

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
