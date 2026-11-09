import WebSocket from 'ws'
import type { ChatVoiceClientMessage, ChatVoiceServerMessage, ChatVoiceSession } from '../../frontend/src/api/generated/types.gen.js'

export type VoiceConfig = {
  authUrl: string
  speachesUrl: string
  model: string
  origins: string[]
  startTimeoutMs?: number
  recordingTimeoutMs?: number
  finishTimeoutMs?: number
  checkIntervalMs?: number
  maxAudioBytes?: number
  maxBufferedBytes?: number
}

type Event = Record<string, unknown>
type Phrase = { id: string; text?: string; sequence: number }
const maxRecordingSeconds: ChatVoiceSession['maxDurationSeconds'] = 180
const maxAudioBytes = maxRecordingSeconds * 24000 * 2
const compatibilityWarning = 'Specifying `session.turn_detection.prefix_padding_ms` is not supported. The server either does not support this field or it is not configurable.'

function object(value: unknown): Event {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('CHAT_AUDIO_FORMAT')
  return value as Event
}

/** One browser recording and one upstream socket, with bounded, non-replayable state. */
export class VoiceStream {
  private state: 'waiting' | 'connecting' | 'recording' | 'finishing' | 'closed' = 'waiting'
  private upstream: WebSocket | undefined
  private grant: Extract<ChatVoiceClientMessage, { type: 'start' }> | undefined
  private timer: ReturnType<typeof setTimeout>
  private heartbeat: ReturnType<typeof setInterval> | undefined
  private checking: Promise<Event> | undefined
  private clientQueue = Promise.resolve()
  private upstreamQueue = Promise.resolve()
  private queuedBytes = 0
  private sentBytes = 0
  private consumedBytes = 0
  private audioBytes = 0
  private activeSpeech: string | undefined
  private barrier: 'drain' | 'tail' | 'settled' | undefined
  private phrases: Phrase[] = []
  private emitted = 0
  private textLength = 0
  private language = ''
  private claimed = false

  constructor(private readonly client: WebSocket, private readonly config: VoiceConfig) {
    this.timer = setTimeout(() => this.fail('AUTH_REQUIRED'), config.startTimeoutMs ?? 5000)
    client.on('message', (data, binary) => {
      const buffer = Buffer.isBuffer(data) ? data : Array.isArray(data) ? Buffer.concat(data) : Buffer.from(data)
      const bytes = buffer.length
      this.queuedBytes += bytes
      if (this.queuedBytes > (config.maxBufferedBytes ?? 262144)) {
        this.fail('CHAT_TRANSCRIPTION_UNAVAILABLE')
        return
      }
      this.clientQueue = this.clientQueue.then(async () => {
        this.queuedBytes -= bytes
        if (this.state === 'closed') return
        await this.receiveClient(buffer, binary)
      }).catch((error: unknown) => this.fail(this.errorCode(error)))
    })
    client.on('close', () => this.close())
    client.on('error', () => this.close())
  }

  private errorCode(error: unknown) {
    return error instanceof Error && /^(?:AUTH_REQUIRED|CHAT_[A-Z_]+)$/.test(error.message)
      ? error.message : 'CHAT_TRANSCRIPTION_UNAVAILABLE'
  }

  private async authorize(operation: 'claim' | 'check' | 'release'): Promise<Event> {
    const grant = this.grant!
    const response = await fetch(`${this.config.authUrl}/${encodeURIComponent(grant.sessionId)}/authorize`, {
      method: 'POST', signal: AbortSignal.timeout(2000),
      headers: { Authorization: `Bearer ${grant.credential}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ operation }),
    })
    const body = object(await response.json())
    if (!response.ok) throw new Error(typeof body.code === 'string' ? body.code : 'AUTH_REQUIRED')
    return body
  }

  private check() {
    this.checking ??= this.authorize('check').finally(() => { this.checking = undefined })
    return this.checking
  }

  private async receiveClient(data: Buffer, binary: boolean) {
    if (binary) {
      if (this.state === 'finishing') return
      if (this.state !== 'recording' || data.length === 0 || data.length > 65536 || data.length % 2 !== 0) {
        throw new Error('CHAT_AUDIO_FORMAT')
      }
      const bytes = data
      const available = (this.config.maxAudioBytes ?? maxAudioBytes) - this.audioBytes
      const part = bytes.subarray(0, available)
      this.audioBytes += part.length
      this.append(part)
      if (part.length < bytes.length || this.audioBytes === (this.config.maxAudioBytes ?? maxAudioBytes)) this.stop()
      return
    }
    const message = object(JSON.parse(data.toString()))
    if (message.type === 'cancel' && Object.keys(message).length === 1) {
      this.close()
    } else if (this.state === 'waiting' && message.type === 'start'
      && Object.keys(message).length === 3 && typeof message.sessionId === 'string'
      && /^[\da-f-]{36}$/i.test(message.sessionId) && typeof message.credential === 'string'
      && message.credential.length <= 128) {
      this.grant = message as typeof this.grant
      this.state = 'connecting'
      clearTimeout(this.timer)
      this.timer = setTimeout(() => this.fail('CHAT_TRANSCRIPTION_UNAVAILABLE'), 10000)
      const authorization = await this.authorize('claim')
      this.claimed = true
      if (this.closed()) {
        void this.authorize('release').catch(() => undefined)
        return
      }
      if (authorization.language !== 'ru' && authorization.language !== 'en') throw new Error('AUTH_REQUIRED')
      this.language = authorization.language
      const url = new URL(this.config.speachesUrl)
      url.searchParams.set('model', 'transcription-only')
      const upstream = new WebSocket(url, { maxPayload: 262144, perMessageDeflate: false })
      this.upstream = upstream
      upstream.on('message', (raw, isBinary) => {
        this.upstreamQueue = this.upstreamQueue.then(async () => {
          if (this.closed()) return
          if (isBinary) throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
          await this.receiveUpstream(object(JSON.parse(raw.toString())))
        }).catch((error: unknown) => this.fail(this.errorCode(error)))
      })
      upstream.on('error', () => this.fail('CHAT_TRANSCRIPTION_UNAVAILABLE'))
      upstream.on('close', () => { if (!this.closed()) this.fail('CHAT_TRANSCRIPTION_UNAVAILABLE') })
    } else if (message.type === 'stop' && Object.keys(message).length === 1
      && (this.state === 'recording' || this.state === 'finishing')) {
      this.stop()
    } else {
      throw new Error('CHAT_AUDIO_FORMAT')
    }
  }

  private closed() { return this.state === 'closed' }

  private sendUpstream(message: Event) {
    const payload = JSON.stringify(message)
    if (!this.upstream || this.upstream.readyState !== WebSocket.OPEN
      || this.upstream.bufferedAmount + Buffer.byteLength(payload) > (this.config.maxBufferedBytes ?? 262144)) {
      throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
    }
    this.upstream.send(payload)
  }

  private append(bytes: Buffer) {
    if (!bytes.length) return
    this.sentBytes += bytes.length
    this.sendUpstream({ type: 'input_audio_buffer.append', audio: bytes.toString('base64') })
  }

  private stop() {
    if (this.state !== 'recording') return
    this.state = 'finishing'
    clearTimeout(this.timer)
    this.timer = setTimeout(() => this.fail('CHAT_TRANSCRIPTION_UNAVAILABLE'), this.config.finishTimeoutMs ?? 180000)
    this.barrier = 'drain'
    // The acknowledgment follows the last audio handlers and their speech events.
    this.sendUpstream({ type: 'session.update', session: {} })
  }

  private phrase(id: unknown) {
    if (typeof id !== 'string' || !id.length) throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
    let phrase = this.phrases.find((item) => item.id === id)
    if (!phrase) {
      phrase = { id, sequence: this.phrases.length }
      this.phrases.push(phrase)
    }
    return phrase
  }

  private async receiveUpstream(event: Event) {
    if (event.type === 'error') {
      const error = object(event.error)
      if (this.state === 'connecting' && error.message === compatibilityWarning) return
      throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
    }
    if (event.type === 'session.created' && this.state === 'connecting') {
      this.sendUpstream({ type: 'session.update', session: {
        input_audio_transcription: { model: this.config.model, language: this.language },
        turn_detection: { type: 'server_vad', create_response: false, prefix_padding_ms: 0,
          silence_duration_ms: 700, threshold: 0.5 },
      } })
    } else if (event.type === 'session.updated') {
      const session = object(event.session)
      const transcription = object(session.input_audio_transcription)
      const vad = object(session.turn_detection)
      if (session.input_audio_format !== 'pcm16' || transcription.model !== this.config.model
        || transcription.language !== this.language || vad.create_response !== false
        || vad.prefix_padding_ms !== 0 || vad.silence_duration_ms !== 700 || vad.threshold !== 0.5) {
        throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
      }
      if (this.state === 'connecting') {
        this.state = 'recording'
        clearTimeout(this.timer)
        this.timer = setTimeout(() => {
          try { this.stop() } catch { this.fail('CHAT_TRANSCRIPTION_UNAVAILABLE') }
        }, this.config.recordingTimeoutMs ?? maxRecordingSeconds * 1000)
        this.heartbeat = setInterval(() => {
          void this.check().catch((error: unknown) => this.fail(this.errorCode(error)))
        }, this.config.checkIntervalMs ?? 2000)
        this.send({ type: 'ready' })
      } else if (this.state === 'finishing' && this.barrier === 'drain') {
        if (this.activeSpeech) {
          this.append(Buffer.alloc(153600)) // 3.2 seconds closes even Speaches' three-second VAD window.
          this.barrier = 'tail'
          this.sendUpstream({ type: 'session.update', session: {} })
        } else if (this.sentBytes - this.consumedBytes >= 4800) {
          this.sendUpstream({ type: 'input_audio_buffer.commit' })
          this.barrier = 'tail'
          this.sendUpstream({ type: 'session.update', session: {} })
        } else {
          this.barrier = 'settled'
        }
      } else if (this.state === 'finishing' && this.barrier === 'tail') {
        if (this.activeSpeech) throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
        this.barrier = 'settled'
      }
    } else if (event.type === 'input_audio_buffer.speech_started') {
      this.activeSpeech = this.phrase(event.item_id).id
    } else if (event.type === 'input_audio_buffer.speech_stopped') {
      this.phrase(event.item_id)
      this.activeSpeech = undefined
      if (typeof event.audio_end_ms !== 'number' || !Number.isFinite(event.audio_end_ms)
        || event.audio_end_ms < 0) throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
      this.consumedBytes += Math.floor(event.audio_end_ms * 48)
    } else if (event.type === 'input_audio_buffer.committed') {
      this.phrase(event.item_id)
    } else if (event.type === 'conversation.item.input_audio_transcription.completed') {
      const phrase = this.phrase(event.item_id)
      if (phrase.text !== undefined) return
      if (typeof event.transcript !== 'string') throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
      phrase.text = event.transcript.trim()
      this.textLength += phrase.text.length
      if (this.textLength > 10000) throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
      await this.flushPhrases()
    } else if (event.type === 'conversation.item.input_audio_transcription.failed') {
      throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
    }
    if (this.state === 'finishing' && this.barrier === 'settled' && this.emitted === this.phrases.length) {
      await this.check()
      if (this.closed()) return
      this.send({ type: 'done', durationSeconds: this.audioBytes / 48000 })
      this.close()
    }
  }

  private async flushPhrases() {
    while (this.emitted < this.phrases.length) {
      const phrase = this.phrases[this.emitted]!
      if (phrase.text === undefined) break
      if (phrase.text) {
        await this.check()
        if (this.closed()) return
        this.send({ type: 'phrase', phraseId: phrase.id, sequence: phrase.sequence, text: phrase.text })
      }
      this.emitted++
    }
  }

  private send(message: ChatVoiceServerMessage) {
    const payload = JSON.stringify(message)
    if (this.client.readyState !== WebSocket.OPEN
      || this.client.bufferedAmount + Buffer.byteLength(payload) > (this.config.maxBufferedBytes ?? 262144)) {
      throw new Error('CHAT_TRANSCRIPTION_UNAVAILABLE')
    }
    this.client.send(payload)
  }

  private fail(code: string) {
    if (this.closed()) return
    try { this.send({ type: 'error', code }) } catch { /* The client may have disconnected. */ }
    this.close()
  }

  private close() {
    if (this.closed()) return
    this.state = 'closed'
    clearTimeout(this.timer)
    clearInterval(this.heartbeat)
    this.upstream?.terminate()
    this.client.close()
    const client = this.client
    const closing = setTimeout(() => client.terminate(), 1000)
    closing.unref()
    if (this.grant && this.claimed) void this.authorize('release').catch(() => undefined)
    this.phrases.length = 0
  }
}
