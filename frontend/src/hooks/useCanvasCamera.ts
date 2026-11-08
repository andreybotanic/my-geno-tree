import { useCallback, useLayoutEffect, useRef, type RefObject } from 'react'
import { useReactFlow } from '@xyflow/react'

type CanvasBounds = Pick<DOMRect, 'left' | 'top' | 'width' | 'height'>

/** Keeps the same graph point at the center when panels resize the canvas. */
export function useCanvasCamera(canvasRef: RefObject<HTMLElement | null>, ready: boolean) {
  const flow = useReactFlow()
  const bounds = useRef<CanvasBounds | null>(null)

  useLayoutEffect(
    () => {
      const element = canvasRef.current
      if (!element || !ready) return

      const resize = () => {
        const next = element.getBoundingClientRect()
        if (next.width <= 0 || next.height <= 0) return
        const previous = bounds.current
        bounds.current = next
        if (!previous || (previous.width === next.width && previous.height === next.height)) return

        const viewport = flow.getViewport()
        void flow.setViewport({
          ...viewport,
          x: viewport.x + (next.width - previous.width) / 2,
          y: viewport.y + (next.height - previous.height) / 2,
        })
      }

      resize()
      const observer = new ResizeObserver(resize)
      observer.observe(element)
      return () => observer.disconnect()
    },
    [canvasRef, flow, ready],
  )

  return useCallback(
    (x: number, y: number, options: { zoom: number; duration: number }) => {
      const next = canvasRef.current?.getBoundingClientRect()
      if (!next || next.width <= 0 || next.height <= 0) return flow.setCenter(x, y, options)

      const previous = bounds.current
      bounds.current = next
      // Opening the sidebar moves the canvas origin before the camera animation starts.
      // Keep the old screen position for the animation's first frame.
      if (previous && (previous.left !== next.left || previous.top !== next.top)) {
        const viewport = flow.getViewport()
        void flow.setViewport({
          ...viewport,
          x: viewport.x + previous.left - next.left,
          y: viewport.y + previous.top - next.top,
        })
      }

      // Consume the measured bounds before React Flow's resize observer can report them.
      return flow.setViewport({
        x: next.width / 2 - x * options.zoom,
        y: next.height / 2 - y * options.zoom,
        zoom: options.zoom,
      }, { duration: options.duration })
    },
    [canvasRef, flow],
  )
}
