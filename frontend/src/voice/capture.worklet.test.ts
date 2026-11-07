import { afterEach, expect, it, vi } from 'vitest'

afterEach(() => vi.unstubAllGlobals())

it('emits twenty-millisecond peaks, PCM16 frames and a flushed final partial frame', async () => {
  const postMessage = vi.fn()
  const port = { postMessage, onmessage: null as ((event: MessageEvent) => void) | null }
  let Processor!: new () => { process: (inputs: Float32Array[][]) => boolean }
  vi.stubGlobal('sampleRate', 24000)
  vi.stubGlobal('AudioWorkletProcessor', class { port = port })
  vi.stubGlobal('registerProcessor', (_name: string, value: typeof Processor) => { Processor = value })
  await import('./capture.worklet')
  const processor = new Processor()
  expect(processor.process([])).toBe(true)
  processor.process([[new Float32Array(240).fill(-0.75)]])
  expect(postMessage).not.toHaveBeenCalled()
  processor.process([[new Float32Array(240).fill(0.25)]])
  expect(postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'amplitude', value: 0.75 })
  processor.process([[new Float32Array(1920).fill(1)]])
  const frame = postMessage.mock.calls.find(([value]) => value.type === 'audio')![0].buffer as ArrayBuffer
  expect(frame.byteLength).toBe(4800)
  expect(new DataView(frame).getInt16(0, true)).toBe(-24576)
  expect(new DataView(frame).getInt16(4798, true)).toBe(32767)
  processor.process([[new Float32Array(100).fill(-1)]])
  port.onmessage!(new MessageEvent('message', { data: { type: 'ignored' } }))
  port.onmessage!(new MessageEvent('message', { data: { type: 'flush' } }))
  const tail = postMessage.mock.calls.filter(([value]) => value.type === 'audio').at(-1)![0].buffer as ArrayBuffer
  expect(tail.byteLength).toBe(200)
  expect(new DataView(tail).getInt16(0, true)).toBe(-32768)
  expect(postMessage).toHaveBeenLastCalledWith({ type: 'flushed' })
  port.onmessage!(new MessageEvent('message', { data: { type: 'flush' } }))
  expect(postMessage.mock.calls.filter(([value]) => value.type === 'audio')).toHaveLength(2)
})
