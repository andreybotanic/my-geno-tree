import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import i18n from '../i18n'
import { ChatVoiceInput } from './ChatVoiceInput'

class Recorder {
  static isTypeSupported = vi.fn(() => true)
  static instance: Recorder
  static blob = new Blob(['audio'], { type: 'audio/webm' })
  state = 'inactive'
  ondataavailable: ((event: { data: Blob }) => void) | null = null
  onstop: (() => void) | null = null
  onerror: (() => void) | null = null
  constructor() { Recorder.instance = this }
  start() { this.state = 'recording' }
  stop() {
    this.state = 'inactive'
    this.ondataavailable?.({ data: Recorder.blob })
    this.onstop?.()
  }
}
const stopTrack = vi.fn()
const getUserMedia = vi.fn(async () => ({ getTracks: () => [{ stop: stopTrack }] }))

function setup(handler: (path: string, init?: RequestInit) => Promise<unknown> = async () => ({ text: 'Мама — Вера.', durationSeconds: 2 })) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const onBusy = vi.fn()
  const onTranscript = vi.fn()
  const props = { active: true, children: <textarea aria-label="Message" />,
    request, disabled: false, onBusy, onTranscript }
  return { ...render(<ChatVoiceInput {...props} />), props, request, onBusy, onTranscript }
}

async function record() {
  fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
  fireEvent.click(await screen.findByRole('button', { name: 'Stop recording' }))
}

describe('ChatVoiceInput', () => {
  beforeEach(async () => {
    localStorage.clear()
    Recorder.blob = new Blob(['audio'], { type: 'audio/webm' })
    vi.clearAllMocks()
    Recorder.isTypeSupported.mockReturnValue(true)
    getUserMedia.mockResolvedValue({ getTracks: () => [{ stop: stopTrack }] })
    vi.stubGlobal('MediaRecorder', Recorder)
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } })
    await i18n.changeLanguage('en')
  })
  afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals() })

  it('records until stop, releases the microphone and returns only editable text without creating a draft', async () => {
    const { request, onTranscript, onBusy, container } = setup()
    await record()
    await waitFor(() => expect(onTranscript).toHaveBeenCalledWith('Мама — Вера.'))
    expect(stopTrack).toHaveBeenCalledOnce()
    expect(onBusy).toHaveBeenLastCalledWith(false)
    expect(request).toHaveBeenCalledOnce()
    const [path, init] = vi.mocked(request).mock.calls[0]!
    expect(path).toBe('/chat-transcriptions')
    const form = init!.body as FormData
    expect(form.get('audio')).toBeInstanceOf(File)
    expect(form.get('treeId')).toBeNull()
    expect(form.get('version')).toBeNull()
    expect(container.querySelector('input[type=file]')).toBeNull()
  })

  it('discards a failed upload and asks to record again without a saved-audio retry', async () => {
    const { request, onTranscript } = setup(async () => { throw new Error('offline') })
    await record()
    expect(await screen.findByRole('alert')).toHaveTextContent('record it again')
    expect(screen.queryByRole('button', { name: 'Retry transcription' })).toBeNull()
    expect(request).toHaveBeenCalledOnce()
    expect(onTranscript).not.toHaveBeenCalled()
  })

  it.each([
    ['CHAT_AUDIO_DURATION', 422, 'at most 90 seconds'],
    ['CHAT_AUDIO_SIZE', 422, 'must not exceed 10 MB'],
    ['CHAT_AUDIO_FORMAT', 422, 'without a video track'],
    ['CHAT_VOICE_RATE_LIMIT', 429, 'Too many'],
  ])('explains validation error %s', async (code, status, text) => {
    setup(async () => {
      throw new ApiRequestError(status, { code, message: null, fields: {}, details: {} })
    })
    await record()
    expect(await screen.findByRole('alert')).toHaveTextContent(text)
  })

  it('rejects oversized recordings and explains microphone permission failures', async () => {
    const { request } = setup()
    Recorder.blob = new Blob([new Uint8Array(10 * 1024 * 1024 + 1)])
    await record()
    expect(screen.getByRole('alert')).toHaveTextContent('10 MB')
    expect(request).not.toHaveBeenCalled()
    getUserMedia.mockRejectedValueOnce(new Error('permission'))
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('microphone'))
  })

  it('keeps text available when recording is unsupported', async () => {
    vi.stubGlobal('MediaRecorder', undefined)
    setup()
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    expect(screen.getByRole('alert')).toHaveTextContent('unavailable')
  })

  it('stops automatically within 90 seconds and releases resources on unmount', async () => {
    vi.useFakeTimers()
    const first = setup()
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Record voice' })) })
    await act(async () => { vi.advanceTimersByTime(89000) })
    expect(first.request).toHaveBeenCalledOnce()
    first.unmount()
    const second = setup()
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Record voice' })) })
    second.unmount()
    expect(Recorder.instance.state).toBe('inactive')
    expect(second.request).not.toHaveBeenCalled()
    expect(stopTrack).toHaveBeenCalled()
  })

  it('stops recording when the panel closes and does not start after a late microphone permission', async () => {
    const view = setup()
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Record voice' })) })
    view.rerender(<ChatVoiceInput {...view.props} active={false} />)
    await waitFor(() => expect(view.onTranscript).toHaveBeenCalled())
    expect(Recorder.instance.state).toBe('inactive')
    view.unmount()
    let grant!: (value: { getTracks: () => { stop: typeof stopTrack }[] }) => void
    getUserMedia.mockReturnValueOnce(new Promise((resolve) => { grant = resolve }))
    const late = setup()
    fireEvent.click(screen.getByRole('button', { name: 'Record voice' }))
    late.rerender(<ChatVoiceInput {...late.props} active={false} />)
    await act(async () => grant({ getTracks: () => [{ stop: stopTrack }] }))
    expect(late.request).not.toHaveBeenCalled()
  })
})
