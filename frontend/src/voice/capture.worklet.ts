declare const sampleRate: number
declare class AudioWorkletProcessor {
  readonly port: MessagePort
}
declare function registerProcessor(name: string, processor: typeof AudioWorkletProcessor): void

class VoiceCaptureProcessor extends AudioWorkletProcessor {
  private samples = 0
  private peak = 0

  process(inputs: Float32Array[][]) {
    const channel = inputs[0]?.[0]
    if (!channel) return true
    for (const value of channel) {
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
