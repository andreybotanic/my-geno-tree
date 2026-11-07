import { afterEach, describe, expect, it, vi } from 'vitest'
import { captureMicrophone } from './capture'

afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals() })

function environment() {
  const source = { connect: vi.fn(), disconnect: vi.fn() }
  const node = { connect: vi.fn(), disconnect: vi.fn(), port: { onmessage: null as ((event: MessageEvent) => void) | null,
    close: vi.fn(), postMessage: vi.fn() } }
  const context = { sampleRate: 24000, audioWorklet: { addModule: vi.fn().mockResolvedValue(undefined) },
    createMediaStreamSource: vi.fn(() => source), destination: {}, resume: vi.fn().mockResolvedValue(undefined), close: vi.fn().mockResolvedValue(undefined) }
  vi.stubGlobal('AudioContext', class { constructor() { return context } })
  vi.stubGlobal('AudioWorkletNode', class { constructor() { return node } })
  const event = (data: unknown) => node.port.onmessage?.(new MessageEvent('message', { data }))
  return { source, node, context, event }
}

describe('PCM microphone capture', () => {
  it('captures amplitude and audio and flushes before releasing resources', async () => {
    const env = environment()
    const peak = vi.fn()
    const audio = vi.fn()
    const capture = await captureMicrophone({} as MediaStream, peak, audio)
    env.event({ type: 'amplitude', value: 0.7 })
    const buffer = new ArrayBuffer(4800)
    env.event({ type: 'audio', buffer })
    env.event({ type: 'other' })
    expect(peak).toHaveBeenCalledExactlyOnceWith(0.7)
    expect(audio).toHaveBeenCalledExactlyOnceWith(buffer)
    const stopped = capture.stop()
    expect(capture.stop()).toBe(stopped)
    expect(env.node.port.postMessage).toHaveBeenCalledWith({ type: 'flush' })
    expect(env.context.close).not.toHaveBeenCalled()
    env.event({ type: 'flushed' })
    await stopped
    capture.cancel()
    expect(env.node.port.onmessage).toBeNull()
    expect(env.node.port.close).toHaveBeenCalledOnce()
    expect(env.node.disconnect).toHaveBeenCalledOnce()
    expect(env.context.close).toHaveBeenCalledOnce()
  })

  it('releases capture if the audio thread never acknowledges Stop', async () => {
    vi.useFakeTimers()
    const env = environment()
    const capture = await captureMicrophone({} as MediaStream, vi.fn(), vi.fn())
    const stopped = expect(capture.stop()).rejects.toThrow('CHAT_AUDIO_UNSUPPORTED')
    await vi.advanceTimersByTimeAsync(3000)
    await stopped
    expect(env.context.close).toHaveBeenCalledOnce()
  })

  it('rejects a different actual sample rate and closes failed contexts', async () => {
    const env = environment()
    env.context.sampleRate = 48000
    env.context.close.mockRejectedValueOnce(new Error('closed'))
    await expect(captureMicrophone({} as MediaStream, vi.fn(), vi.fn())).rejects.toThrow('CHAT_AUDIO_UNSUPPORTED')
    env.context.sampleRate = 24000
    env.context.audioWorklet.addModule.mockRejectedValueOnce(new Error('load'))
    await expect(captureMicrophone({} as MediaStream, vi.fn(), vi.fn())).rejects.toThrow('load')
  })
})
