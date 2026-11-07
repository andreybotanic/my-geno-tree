import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { drawHistogram, type AmplitudeHistory } from '../voice/amplitudeHistory'

export function ChatAudioHistogram({ history }: { history: AmplitudeHistory }) {
  const { t } = useTranslation()
  const canvas = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    const element = canvas.current!
    const context = element.getContext('2d')
    if (!context) return
    let width = 0
    let frame = 0
    let lastDraw = -Infinity
    const observer = new ResizeObserver(([entry]) => {
      width = entry!.contentRect.width
      const scale = window.devicePixelRatio || 1
      element.width = Math.round(width * scale)
      element.height = Math.round(48 * scale)
      context.setTransform(scale, 0, 0, scale, 0, 0)
    })
    observer.observe(element)
    const draw = (now: number) => {
      if (now - lastDraw >= 1000 / 30) {
        drawHistogram(context, history, width, 48, getComputedStyle(element).color)
        lastDraw = now
      }
      frame = requestAnimationFrame(draw)
    }
    frame = requestAnimationFrame(draw)
    return () => { observer.disconnect(); cancelAnimationFrame(frame) }
  }, [history])

  return <canvas ref={canvas} className="chat-audio-histogram" role="img" aria-label={t('chat.voice.visualization')} />
}
