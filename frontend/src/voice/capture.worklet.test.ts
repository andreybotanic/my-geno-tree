import { afterEach, expect, it, vi } from 'vitest'

afterEach(() => vi.unstubAllGlobals())

it('collects twenty-millisecond peaks across audio processing blocks', async () => {
  const postMessage = vi.fn()
  let Processor!: new () => { process: (inputs: Float32Array[][]) => boolean }
  vi.stubGlobal('sampleRate', 24000)
  vi.stubGlobal('AudioWorkletProcessor', class { port = { postMessage } })
  vi.stubGlobal('registerProcessor', (_name: string, value: typeof Processor) => { Processor = value })
  await import('./capture.worklet')
  const processor = new Processor()
  expect(processor.process([])).toBe(true)
  expect(processor.process([[new Float32Array(240).fill(-0.75)]])).toBe(true)
  expect(postMessage).not.toHaveBeenCalled()
  processor.process([[new Float32Array(240).fill(0.25)]])
  expect(postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'amplitude', value: 0.75 })
  processor.process([[new Float32Array(480)]])
  expect(postMessage).toHaveBeenLastCalledWith({ type: 'amplitude', value: 0 })
})
