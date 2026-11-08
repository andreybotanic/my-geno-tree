import { useCallback, useLayoutEffect, useRef, type RefObject } from 'react'
import { useReactFlow } from '@xyflow/react'

type CanvasSize = { width: number; height: number }

/** Keeps the same graph point at the center when panels resize the canvas. */
export function useCanvasCamera(canvasRef: RefObject<HTMLElement | null>, ready: boolean) {
  const flow = useReactFlow()
  const size = useRef<CanvasSize | null>(null)

  useLayoutEffect(
    () => {
      const element = canvasRef.current
      if (!element || !ready) return

      const resize = () => {
        const next = element.getBoundingClientRect()
        if (next.width <= 0 || next.height <= 0) return
        const previous = size.current
        size.current = { width: next.width, height: next.height }
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

      // React Flow's observer can still contain the width from before the panel opened.
      // Consume this size here so its later resize notification cannot shift the camera twice.
      size.current = { width: next.width, height: next.height }
      return flow.setViewport({
        x: next.width / 2 - x * options.zoom,
        y: next.height / 2 - y * options.zoom,
        zoom: options.zoom,
      }, { duration: options.duration })
    },
    [canvasRef, flow],
  )
}
