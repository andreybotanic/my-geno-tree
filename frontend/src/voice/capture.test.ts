import { afterEach, describe, expect, it, vi } from 'vitest'
import { captureAmplitude } from './capture'

afterEach(() => vi.unstubAllGlobals())

describe('microphone amplitude capture', () => {
  it('connects a silent worklet to the same microphone and releases all resources', async () => {
    const source = { connect: vi.fn(), disconnect: vi.fn() }
    const node = { connect: vi.fn(), disconnect: vi.fn(), port: { onmessage: null as ((event: MessageEvent) => void) | null, close: vi.fn() } }
    const context = { audioWorklet: { addModule: vi.fn().mockResolvedValue(undefined) },
      createMediaStreamSource: vi.fn(() => source), destination: {}, resume: vi.fn().mockResolvedValue(undefined), close: vi.fn().mockResolvedValue(undefined) }
    vi.stubGlobal('AudioContext', class { constructor() { return context } })
    vi.stubGlobal('AudioWorkletNode', class { constructor() { return node } })
    const peak = vi.fn()
    const stream = {} as MediaStream
    const stop = await captureAmplitude(stream, peak)
    expect(context.createMediaStreamSource).toHaveBeenCalledWith(stream)
    expect(source.connect).toHaveBeenCalledWith(node)
    node.port.onmessage!(new MessageEvent('message', { data: { type: 'amplitude', value: 0.7 } }))
    node.port.onmessage!(new MessageEvent('message', { data: { type: 'other' } }))
    expect(peak).toHaveBeenCalledExactlyOnceWith(0.7)
    stop()
    expect(node.port.onmessage).toBeNull()
    expect(node.port.close).toHaveBeenCalledOnce()
    expect(source.disconnect).toHaveBeenCalledOnce()
    expect(node.disconnect).toHaveBeenCalledOnce()
    expect(context.close).toHaveBeenCalledOnce()
  })

  it('closes the context when loading the processor fails', async () => {
    const close = vi.fn().mockRejectedValue(new Error('already closed'))
    vi.stubGlobal('AudioContext', class { audioWorklet = { addModule: vi.fn().mockRejectedValue(new Error('load')) }; close = close })
    await expect(captureAmplitude({} as MediaStream, vi.fn())).rejects.toThrow('load')
    expect(close).toHaveBeenCalledOnce()
  })
})
