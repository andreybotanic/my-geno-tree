import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { TreeMergeTranscription } from '../api/generated'

type Props = {
  active: boolean
  children: ReactNode
  request: AuthorizedJsonRequest
  disabled: boolean
  onBusy: (busy: boolean) => void
  onTranscript: (text: string) => void
}

const maxBytes = 10 * 1024 * 1024

export function TreeMergeVoiceInput({ active, children, request, disabled, onBusy, onTranscript }: Props) {
  const { t } = useTranslation()
  const [recording, setRecording] = useState(false)
  const [seconds, setSeconds] = useState(0)
  const [working, setWorking] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const recorder = useRef<MediaRecorder | null>(null)
  const stream = useRef<MediaStream | null>(null)
  const timer = useRef<number | null>(null)
  const mounted = useRef(true)
  const visible = useRef(active)

  useEffect(() => {
    visible.current = active
    if (!active && recorder.current?.state === 'recording') recorder.current.stop()
  }, [active])

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
      if (timer.current !== null) window.clearInterval(timer.current)
      if (recorder.current?.state === 'recording') recorder.current.stop()
      stream.current?.getTracks().forEach((track) => track.stop())
    }
  }, [])

  async function transcribe(file: Blob) {
    if (file.size > maxBytes) { setError('treeMerge.voice.size'); return }
    setWorking(true)
    onBusy(true)
    setError(null)
    try {
      const form = new FormData()
      form.set('audio', file, 'recording')
      const result = await request<TreeMergeTranscription>('/tree-merge-transcriptions', { method: 'POST', body: form })
      if (mounted.current) onTranscript(result.text)
    } catch (cause) {
      if (!mounted.current) return
      const code = cause instanceof ApiRequestError ? cause.body.code : ''
      setError(code === 'TREE_MERGE_AUDIO_DURATION' ? 'treeMerge.voice.duration'
        : code === 'TREE_MERGE_AUDIO_SIZE' ? 'treeMerge.voice.size'
        : code === 'TREE_MERGE_AUDIO_FORMAT' ? 'treeMerge.voice.format'
        : cause instanceof ApiRequestError && cause.status === 429 ? 'treeMerge.voice.rateLimit'
        : 'treeMerge.voice.failed')
    } finally {
      if (mounted.current) { setWorking(false); onBusy(false) }
    }
  }

  async function start() {
    setError(null)
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setError('treeMerge.voice.unsupported'); return
    }
    onBusy(true)
    setWorking(true)
    try {
      const audioStream = await navigator.mediaDevices.getUserMedia({ audio: true })
      if (!mounted.current || !visible.current) {
        audioStream.getTracks().forEach((track) => track.stop())
        if (mounted.current) { setWorking(false); onBusy(false) }
        return
      }
      stream.current = audioStream
      const mimeType = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4']
        .find((mime) => MediaRecorder.isTypeSupported(mime))
      if (!mimeType) throw new Error('Unsupported recording format')
      const instance = new MediaRecorder(audioStream, { mimeType, audioBitsPerSecond: 64000 })
      recorder.current = instance
      const chunks: Blob[] = []
      let size = 0
      instance.ondataavailable = (event) => {
        size += event.data.size
        chunks.push(event.data)
        if (size > maxBytes && instance.state === 'recording') instance.stop()
      }
      instance.onstop = () => {
        if (timer.current !== null) window.clearInterval(timer.current)
        audioStream.getTracks().forEach((track) => track.stop())
        recorder.current = null
        stream.current = null
        instance.onstop = null
        instance.ondataavailable = null
        if (!mounted.current) { chunks.length = 0; return }
        setRecording(false)
        onBusy(false)
        const file = new Blob(chunks, { type: mimeType })
        chunks.length = 0
        void transcribe(file)
      }
      instance.onerror = () => { if (mounted.current) setError('treeMerge.voice.failed') }
      instance.start(1000)
      setWorking(false)
      setSeconds(0)
      setRecording(true)
      const started = Date.now()
      timer.current = window.setInterval(() => {
        const elapsed = Math.floor((Date.now() - started) / 1000)
        setSeconds(elapsed)
        // Leave room for encoder padding within the server's strict 90-second limit.
        if (elapsed >= 89 && instance.state === 'recording') instance.stop()
      }, 250)
    } catch {
      stream.current?.getTracks().forEach((track) => track.stop())
      if (mounted.current) { setError('treeMerge.voice.permission'); setWorking(false); onBusy(false) }
    }
  }

  const unavailable = disabled || working || recording
  return <div className="tree-merge-voice">
    <div className="tree-merge-input">
      {children}
      <button type="button" className={`tree-merge-microphone${recording ? ' is-recording' : ''}`}
        disabled={!recording && unavailable} aria-label={t(recording ? 'treeMerge.voice.stop' : 'treeMerge.voice.record')}
        onClick={() => recording ? recorder.current?.stop() : void start()}>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
          {recording ? <rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor" /> : <>
            <rect x="9" y="3" width="6" height="12" rx="3" stroke="currentColor" strokeWidth="1.7" />
            <path d="M6 11v1a6 6 0 0 0 12 0v-1m-6 7v3m-3 0h6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
          </>}
        </svg>
      </button>
      {recording && <span className="tree-merge-recording-time" role="status">{t('treeMerge.voice.elapsed', { seconds })}</span>}
    </div>
    {working && <p role="status">{t('treeMerge.voice.processing')}</p>}
    {error && <p role="alert">{t(error)}</p>}
  </div>
}
