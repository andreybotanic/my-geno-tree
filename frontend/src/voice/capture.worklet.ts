declare const sampleRate: number
declare class AudioWorkletProcessor {
  readonly port: MessagePort
}
declare function registerProcessor(name: string, processor: typeof AudioWorkletProcessor): void

class VoiceCaptureProcessor extends AudioWorkletProcessor {
  private samples = 0
  private peak = 0
  private pcm = new Int16Array(2400)
  private written = 0

  constructor() {
    super()
    this.port.onmessage = (event: MessageEvent<{ type: string }>) => {
      if (event.data.type !== 'flush') return
      if (this.written) {
        const buffer = this.pcm.slice(0, this.written).buffer
        this.port.postMessage({ type: 'audio', buffer }, [buffer])
        this.written = 0
      }
      this.port.postMessage({ type: 'flushed' })
    }
  }

  process(inputs: Float32Array[][]) {
    const channel = inputs[0]?.[0]
    if (!channel) return true
    for (const value of channel) {
      const sample = Math.max(-1, Math.min(1, value))
      this.pcm[this.written++] = Math.round(sample * (sample < 0 ? 32768 : 32767))
      if (this.written === this.pcm.length) {
        const buffer = this.pcm.buffer
        this.port.postMessage({ type: 'audio', buffer }, [buffer])
        this.pcm = new Int16Array(2400)
        this.written = 0
      }
      this.peak = Math.max(this.peak, Math.abs(value))
      if (++this.samples >= sampleRate / 50) {
        this.port.postMessage({ type: 'amplitude', value: this.peak })
        this.samples = 0
        this.peak = 0
      }
    }
    return true
  }
}

registerProcessor('voice-capture', VoiceCaptureProcessor)

export {}
