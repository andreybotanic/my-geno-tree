import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import { captureMicrophone } from '../voice/capture'
import i18n from '../i18n'
import { ChatVoiceInput } from './ChatVoiceInput'

vi.mock('../voice/capture', () => ({ captureMicrophone: vi.fn() }))

class Socket {
  static OPEN = 1
  static instance: Socket
  readyState = 0
  bufferedAmount = 0
  onopen: (() => void) | null = null
  onmessage: ((event: MessageEvent<string>) => void) | null = null
  onerror: (() => void) | null = null
  onclose: (() => void) | null = null
  sent: unknown[] = []
  close = vi.fn(() => { this.readyState = 3 })
  readonly url: string
  constructor(url: string) { this.url = url; Socket.instance = this }
  send(data: unknown) { this.sent.push(typeof data === 'string' ? JSON.parse(data) : data) }
  open() { this.readyState = 1; this.onopen?.() }
  event(data: unknown) { this.onmessage?.(new MessageEvent('message', { data: JSON.stringify(data) })) }
}

const grant = { id: '01900000-0000-7000-8000-000000000001', credential: 'secret',
  url: '/api/v1/chat-transcriptions/stream', sampleRate: 24000, maxDurationSeconds: 90, expiresIn: 30 }
const stopTrack = vi.fn()
const getUserMedia = vi.fn()
const cancel = vi.fn()
const stop = vi.fn()
let amplitude!: (value: number) => void
let audio!: (value: ArrayBuffer) => void

function setup(handler: () => Promise<unknown> = async () => grant) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const props = { active: true, children: <textarea aria-label="Message" />, request,
    disabled: false, onBusy: vi.fn(), onTranscript: vi.fn(), onAccessError: vi.fn() }
  return { ...render(<ChatVoiceInput {...props} />), props, request }
}

async function begin() {
  fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
  await waitFor(() => expect(Socket.instance?.readyState).toBe(0))
  await act(async () => { Socket.instance.open(); Socket.instance.event({ type: 'ready' }) })
  return Socket.instance
}

describe('streaming voice input', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    Socket.instance = undefined as unknown as Socket
    getUserMedia.mockResolvedValue({ getTracks: () => [{ stop: stopTrack }] })
    stop.mockResolvedValue(undefined)
    vi.mocked(captureMicrophone).mockImplementation(async (_stream, onAmplitude, onAudio) => {
      amplitude = onAmplitude; audio = onAudio
      return { stop, cancel }
    })
    vi.stubGlobal('WebSocket', Socket)
    vi.stubGlobal('AudioContext', class {})
    vi.stubGlobal('AudioWorkletNode', class {})
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } })
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null)
    await i18n.changeLanguage('en')
  })
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); vi.unstubAllGlobals() })

  it('streams audio and phrases, deduplicates results and flushes the final audio before Stop', async () => {
    const view = setup()
    const socket = await begin()
    expect(view.request).toHaveBeenCalledWith('/chat-transcription-sessions', expect.objectContaining({ body: '{}', method: 'POST' }))
    expect(socket.url).not.toContain('secret')
    expect(socket.sent[0]).toEqual({ type: 'start', sessionId: grant.id, credential: 'secret' })
    expect(screen.getByRole('img')).toHaveAccessibleName(/10 seconds/)
    const pcm = new ArrayBuffer(4800)
    await act(async () => {
      amplitude(0.5); audio(pcm)
      socket.event({ type: 'phrase', phraseId: 'one', sequence: 0, text: 'Мама — Вера.' })
      socket.event({ type: 'phrase', phraseId: 'one', sequence: 0, text: 'Мама — Вера.' })
    })
    expect(view.props.onTranscript).toHaveBeenCalledExactlyOnceWith('Мама — Вера.')
    expect(socket.sent).toContain(pcm)
    stop.mockImplementationOnce(async () => { audio(new ArrayBuffer(200)) })
    fireEvent.click(screen.getByRole('button', { name: 'Stop recording' }))
    await waitFor(() => expect(socket.sent.at(-1)).toEqual({ type: 'stop' }))
    expect(socket.sent.at(-2)).toBeInstanceOf(ArrayBuffer)
    expect(stopTrack).toHaveBeenCalled()
    expect(view.props.onBusy).toHaveBeenLastCalledWith(true)
    expect(screen.getByRole('status')).toHaveTextContent('last phrase')
    await act(async () => {
      socket.event({ type: 'phrase', phraseId: 'two', sequence: 1, text: 'Папа — Иван.' })
      socket.event({ type: 'done', durationSeconds: 1 })
    })
    expect(view.props.onTranscript).toHaveBeenLastCalledWith('Папа — Иван.')
    expect(view.props.onBusy).toHaveBeenLastCalledWith(false)
    expect(cancel).toHaveBeenCalled()
    expect(socket.close).toHaveBeenCalled()
    expect(view.request).toHaveBeenCalledOnce()
  })

  it('keeps completed phrases on interruption and permits a new recording without reconnecting', async () => {
    const view = setup()
    const socket = await begin()
    const late = socket.onmessage!
    await act(async () => {
      socket.event({ type: 'phrase', phraseId: 'one', sequence: 0, text: 'Kept' })
      socket.onclose?.()
    })
    expect(screen.getByRole('alert')).toHaveTextContent('Completed phrases are kept')
    expect(view.props.onTranscript).toHaveBeenCalledExactlyOnceWith('Kept')
    expect(view.request).toHaveBeenCalledOnce()
    expect(screen.getByRole('button', { name: 'Record voice' })).toBeEnabled()
    Socket.instance = undefined as unknown as Socket
    await begin()
    await act(async () => late(new MessageEvent('message', { data: JSON.stringify({ type: 'phrase', phraseId: 'late', sequence: 1, text: 'Ignore' }) })))
    expect(view.props.onTranscript).toHaveBeenCalledOnce()
    expect(view.request).toHaveBeenCalledTimes(2)
  })

  it('cancels on a ban, unmounts safely and ignores late results', async () => {
    const view = setup()
    const socket = await begin()
    const late = socket.onmessage!
    view.rerender(<ChatVoiceInput {...view.props} blocked />)
    expect(socket.close).toHaveBeenCalled()
    expect(stopTrack).toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Record voice' })).toBeDisabled()
    await act(async () => late(new MessageEvent('message', { data: '{"type":"phrase","text":"late","phraseId":"late","sequence":0}' })))
    expect(view.props.onTranscript).not.toHaveBeenCalled()
    view.unmount()
  })

  it('finishes when hidden and cancels a late microphone permission', async () => {
    const view = setup()
    const socket = await begin()
    view.rerender(<ChatVoiceInput {...view.props} active={false} />)
    await waitFor(() => expect(socket.sent).toContainEqual({ type: 'stop' }))
    view.unmount()
    let resolve!: (value: { getTracks: () => { stop: typeof stopTrack }[] }) => void
    getUserMedia.mockReturnValueOnce(new Promise((done) => { resolve = done }))
    const late = setup()
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    late.rerender(<ChatVoiceInput {...late.props} active={false} />)
    await act(async () => resolve({ getTracks: () => [{ stop: stopTrack }] }))
    expect(late.request).not.toHaveBeenCalled()
  })

  it.each([
    ['CHAT_BANNED', 403, 'blocked'], ['CHAT_UNAVAILABLE', 503, 'unavailable'],
    ['CHAT_VOICE_RATE_LIMIT', 429, 'Too many'],
  ])('explains %s when issuing a recording grant', async (code, status, text) => {
    const view = setup(async () => { throw new ApiRequestError(status, { code, message: null, fields: {}, details: {} }) })
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(text)
    expect(cancel).toHaveBeenCalled()
    expect(view.props.onBusy).toHaveBeenLastCalledWith(false)
    if (code !== 'CHAT_VOICE_RATE_LIMIT') expect(view.props.onAccessError).toHaveBeenCalled()
  })

  it('handles unsupported capture, permission denial and worklet failures', async () => {
    const view = setup()
    vi.stubGlobal('AudioContext', undefined)
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    expect(screen.getByRole('alert')).toHaveTextContent('unavailable in this browser')
    vi.stubGlobal('AudioContext', class {})
    getUserMedia.mockRejectedValueOnce(new Error('denied'))
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Check browser permission')
    vi.mocked(captureMicrophone).mockRejectedValueOnce(new Error('CHAT_AUDIO_UNSUPPORTED'))
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('unavailable in this browser'))
    expect(view.request).not.toHaveBeenCalled()
  })

  it('enforces duration and reports a finalization timeout', async () => {
    vi.useFakeTimers()
    setup()
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Record voice' })) })
    const socket = Socket.instance
    await act(async () => { socket.open(); socket.event({ type: 'ready' }) })
    await act(async () => { await vi.advanceTimersByTimeAsync(90000) })
    expect(socket.sent).toContainEqual({ type: 'stop' })
    await act(async () => { await vi.advanceTimersByTimeAsync(185000) })
    expect(screen.getByRole('alert')).toHaveTextContent('Completed phrases are kept')
  })

  it('rejects malformed events and a slow connection without losing earlier phrases', async () => {
    const view = setup()
    const socket = await begin()
    await act(async () => socket.event({ type: 'error', code: 'AUTH_REQUIRED' }))
    expect(view.props.onAccessError).toHaveBeenCalled()
    Socket.instance = undefined as unknown as Socket
    const next = await begin()
    await act(async () => next.onmessage!(new MessageEvent('message', { data: 'broken' })))
    expect(screen.getByRole('alert')).toBeVisible()
    Socket.instance = undefined as unknown as Socket
    const slow = await begin()
    slow.bufferedAmount = 262145
    await act(async () => audio(new ArrayBuffer(4800)))
    expect(slow.close).toHaveBeenCalled()
  })

  it('cancels capture that finishes initializing after the component closes', async () => {
    let resolve!: (value: { stop: typeof stop; cancel: typeof cancel }) => void
    vi.mocked(captureMicrophone).mockReturnValueOnce(new Promise((done) => { resolve = done }))
    const view = setup()
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    await waitFor(() => expect(captureMicrophone).toHaveBeenCalled())
    view.unmount()
    await act(async () => resolve({ stop, cancel }))
    expect(cancel).toHaveBeenCalled()
    expect(view.request).not.toHaveBeenCalled()
  })

  it('caps streamed samples at ninety seconds and flushes only once', async () => {
    setup()
    const socket = await begin()
    await act(async () => {
      for (let frame = 0; frame < 901; frame++) audio(new ArrayBuffer(4800))
    })
    const frames = socket.sent.filter((value): value is ArrayBuffer => value instanceof ArrayBuffer)
    expect(frames.reduce((total, value) => total + value.byteLength, 0)).toBe(4320000)
    expect(stop).toHaveBeenCalledOnce()
    expect(socket.sent.filter((value) => (value as { type?: string }).type === 'stop')).toHaveLength(1)
  })

  it('reports unsupported context creation and failed audio flushing', async () => {
    const view = setup()
    vi.mocked(captureMicrophone).mockRejectedValueOnce(new DOMException('Unsupported sample rate', 'NotSupportedError'))
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('unavailable in this browser')
    await begin()
    stop.mockRejectedValueOnce(new Error('audio thread stopped'))
    fireEvent.click(screen.getByRole('button', { name: 'Stop recording' }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Completed phrases are kept'))
    expect(view.props.onBusy).toHaveBeenLastCalledWith(false)
  })
})
