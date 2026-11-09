import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { ChatVoiceServerMessage, ChatVoiceSession } from '../api/generated'
import { AmplitudeHistory } from '../voice/amplitudeHistory'
import { captureMicrophone, type MicrophoneCapture } from '../voice/capture'
import { ChatAudioHistogram } from './ChatAudioHistogram'

type Props = {
  active: boolean
  children: (recording: boolean) => ReactNode
  request: AuthorizedJsonRequest
  disabled: boolean
  blocked?: boolean
  onBusy: (busy: boolean) => void
  onTranscript: (text: string) => void
  onAccessError?: () => void
}
type Recording = { stop: () => void; cancel: () => void }
type Phase = 'idle' | 'preparing' | 'recording' | 'finishing'

export function ChatVoiceInput({ active, children, request, disabled, blocked = false, onBusy, onTranscript, onAccessError }: Props) {
  const { t } = useTranslation()
  const [phase, setPhase] = useState<Phase>('idle')
  const [seconds, setSeconds] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [history, setHistory] = useState(() => new AmplitudeHistory())
  const current = useRef<Recording | null>(null)

  useEffect(() => { if (!active) current.current?.stop() }, [active])
  useEffect(() => { if (blocked) current.current?.cancel() }, [blocked])
  useEffect(() => () => { current.current?.cancel() }, [])

  async function start() {
    setError(null)
    if (!navigator.mediaDevices?.getUserMedia || typeof AudioContext === 'undefined'
      || typeof AudioWorkletNode === 'undefined' || typeof WebSocket === 'undefined') {
      setError('chat.voice.unsupported')
      return
    }
    if (current.current) return
    setPhase('preparing')
    onBusy(true)
    const recordingHistory = new AmplitudeHistory()
    setHistory(recordingHistory)
    const controller = new AbortController()
    let stream: MediaStream | undefined
    let capture: MicrophoneCapture | undefined
    let socket: WebSocket | undefined
    let timer: number | undefined
    let timeout: number | undefined
    let acceptingAudio = false
    let finishing = false
    let audioBytes = 0
    let lastSequence = -1
    const phrases = new Set<string>()

    const valid = () => current.current === recording
    const cleanup = () => {
      controller.abort()
      window.clearInterval(timer)
      window.clearTimeout(timeout)
      stream?.getTracks().forEach((track) => track.stop())
      capture?.cancel()
      if (socket) {
        socket.onopen = null; socket.onmessage = null; socket.onerror = null; socket.onclose = null
        if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: 'cancel' }))
        socket.close()
      }
    }
    const finish = (message?: string) => {
      if (!valid()) return
      current.current = null
      cleanup()
      setPhase('idle')
      onBusy(false)
      if (message) setError(message)
    }
    const fail = (code: string) => {
      if (!valid()) return
      if (code === 'CHAT_BANNED' || code === 'CHAT_UNAVAILABLE' || code === 'AUTH_REQUIRED') onAccessError?.()
      finish(code === 'CHAT_BANNED' ? 'chat.access.blocked'
        : code === 'CHAT_UNAVAILABLE' ? 'chat.access.unavailable'
          : code === 'CHAT_VOICE_RATE_LIMIT' ? 'chat.voice.rateLimit'
            : code === 'CHAT_AUDIO_UNSUPPORTED' ? 'chat.voice.unsupported' : 'chat.voice.interrupted')
    }
    const recording: Recording = {
      cancel: () => finish(),
      stop: () => {
        if (!valid() || finishing) return
        if (!acceptingAudio) { finish(); return }
        finishing = true
        setPhase('finishing')
        window.clearInterval(timer)
        stream?.getTracks().forEach((track) => track.stop())
        void capture!.stop().then(() => {
          if (!valid()) return
          acceptingAudio = false
          socket!.send(JSON.stringify({ type: 'stop' }))
          timeout = window.setTimeout(() => fail('CHAT_TRANSCRIPTION_UNAVAILABLE'), 185000)
        }).catch(() => fail('CHAT_TRANSCRIPTION_UNAVAILABLE'))
      },
    }
    current.current = recording
    timeout = window.setTimeout(() => fail('CHAT_TRANSCRIPTION_UNAVAILABLE'), 30000)
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } })
      if (!valid()) { stream.getTracks().forEach((track) => track.stop()); return }
      capture = await captureMicrophone(stream, (value) => { if (acceptingAudio) recordingHistory.push(value) }, (buffer) => {
        if (!valid() || !acceptingAudio) return
        const remaining = 4320000 - audioBytes
        const part = buffer.byteLength <= remaining ? buffer : buffer.slice(0, remaining)
        if (socket!.readyState !== WebSocket.OPEN || socket!.bufferedAmount + part.byteLength > 262144) {
          fail('CHAT_TRANSCRIPTION_UNAVAILABLE')
          return
        }
        if (part.byteLength) { audioBytes += part.byteLength; socket!.send(part) }
        if (audioBytes >= 4320000) recording.stop()
      })
      if (!valid()) { capture.cancel(); return }
      const grant = await request<ChatVoiceSession>('/chat-transcription-sessions', {
        method: 'POST', body: '{}', signal: controller.signal,
      })
      if (!valid()) return
      socket = new WebSocket(new URL(grant.url, window.location.href).href.replace(/^http/, 'ws'))
      socket.onopen = () => {
        if (valid()) socket!.send(JSON.stringify({ type: 'start', sessionId: grant.id, credential: grant.credential }))
      }
      socket.onmessage = (event: MessageEvent<string>) => {
        if (!valid()) return
        try {
          const message = JSON.parse(event.data) as ChatVoiceServerMessage
          if (message.type === 'ready' && !acceptingAudio && !finishing) {
            window.clearTimeout(timeout)
            acceptingAudio = true
            recordingHistory.clear()
            setSeconds(0)
            setPhase('recording')
            const started = Date.now()
            timer = window.setInterval(() => {
              const elapsed = Math.floor((Date.now() - started) / 1000)
              setSeconds(elapsed)
              if (elapsed >= 90) recording.stop()
            }, 250)
          } else if (message.type === 'phrase' && typeof message.text === 'string'
            && typeof message.phraseId === 'string' && Number.isInteger(message.sequence)) {
            if (phrases.has(message.phraseId)) return
            if (message.sequence <= lastSequence || message.text.length > 10000) throw new Error('Invalid phrase')
            phrases.add(message.phraseId)
            lastSequence = message.sequence
            if (message.text.trim()) onTranscript(message.text)
          } else if (message.type === 'done') finish()
          else if (message.type === 'error') fail(message.code)
          else throw new Error('Unexpected voice event')
        } catch { fail('CHAT_TRANSCRIPTION_UNAVAILABLE') }
      }
      socket.onerror = socket.onclose = () => fail('CHAT_TRANSCRIPTION_UNAVAILABLE')
    } catch (cause) {
      if (!valid()) return
      if (cause instanceof ApiRequestError) fail(cause.body.code)
      else if (cause instanceof DOMException && cause.name === 'NotSupportedError') fail('CHAT_AUDIO_UNSUPPORTED')
      else if (cause instanceof Error && cause.message === 'CHAT_AUDIO_UNSUPPORTED') fail(cause.message)
      else finish(stream ? 'chat.voice.interrupted' : 'chat.voice.permission')
    }
  }

  const recording = phase === 'recording'
  const unavailable = disabled || blocked || phase !== 'idle'
  return <div className="chat-voice">
    {recording && <ChatAudioHistogram history={history} />}
    <div className="chat-input">
      {children(recording)}
      <button type="button" className={`chat-microphone${recording ? ' is-recording' : ''}`}
        disabled={!recording && unavailable} aria-label={t(recording ? 'chat.voice.stop' : 'chat.voice.record')}
        onClick={() => recording ? current.current?.stop() : void start()}>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
          {recording ? <rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor" /> : <>
            <rect x="9" y="3" width="6" height="12" rx="3" stroke="currentColor" strokeWidth="1.7" />
            <path d="M6 11v1a6 6 0 0 0 12 0v-1m-6 7v3m-3 0h6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
          </>}
        </svg>
      </button>
      {recording && <span className="chat-recording-time" role="status">{t('chat.voice.elapsed', { seconds })}</span>}
    </div>
    {phase === 'preparing' && <p role="status">{t('chat.voice.preparing')}</p>}
    {phase === 'finishing' && <p role="status">{t('chat.voice.finishing')}</p>}
    {error && <p role="alert">{t(error)}</p>}
  </div>
}
