import workletUrl from './capture.worklet.ts?worker&url'

export async function captureAmplitude(stream: MediaStream, onAmplitude: (value: number) => void) {
  const context = new AudioContext({ sampleRate: 24000 })
  try {
    await context.audioWorklet.addModule(workletUrl)
    const source = context.createMediaStreamSource(stream)
    const node = new AudioWorkletNode(context, 'voice-capture')
    node.port.onmessage = (event: MessageEvent<{ type: string; value: number }>) => {
      if (event.data.type === 'amplitude') onAmplitude(event.data.value)
    }
    source.connect(node)
    // The processor leaves its output silent; a connected output keeps capture running.
    node.connect(context.destination)
    await context.resume()
    return () => {
      node.port.onmessage = null
      node.port.close()
      source.disconnect()
      node.disconnect()
      void context.close().catch(() => undefined)
    }
  } catch (error) {
    await context.close().catch(() => undefined)
    throw error
  }
}
