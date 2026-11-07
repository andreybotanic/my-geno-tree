import workletUrl from './capture.worklet.ts?worker&url'

export type MicrophoneCapture = { stop: () => Promise<void>; cancel: () => void }

export async function captureMicrophone(
  stream: MediaStream,
  onAmplitude: (value: number) => void,
  onAudio: (buffer: ArrayBuffer) => void,
): Promise<MicrophoneCapture> {
  const context = new AudioContext({ sampleRate: 24000 })
  try {
    if (context.sampleRate !== 24000) throw new Error('CHAT_AUDIO_UNSUPPORTED')
    await context.audioWorklet.addModule(workletUrl)
    const source = context.createMediaStreamSource(stream)
    const node = new AudioWorkletNode(context, 'voice-capture', {
      channelCount: 1, channelCountMode: 'explicit', channelInterpretation: 'speakers',
    })
    let finish: (() => void) | undefined
    let stopped: Promise<void> | undefined
    let released = false
    const cancel = () => {
      if (released) return
      released = true
      finish?.()
      node.port.onmessage = null
      node.port.close()
      source.disconnect()
      node.disconnect()
      void context.close().catch(() => undefined)
    }
    node.port.onmessage = (event: MessageEvent<{ type: string; value: number; buffer: ArrayBuffer }>) => {
      if (event.data.type === 'amplitude') onAmplitude(event.data.value)
      else if (event.data.type === 'audio') onAudio(event.data.buffer)
      else if (event.data.type === 'flushed') finish?.()
    }
    source.connect(node)
    node.connect(context.destination)
    await context.resume()
    return {
      cancel,
      stop: () => {
        stopped ??= new Promise<void>((resolve, reject) => {
          const timeout = window.setTimeout(() => { reject(new Error('CHAT_AUDIO_UNSUPPORTED')); cancel() }, 3000)
          finish = () => { window.clearTimeout(timeout); resolve() }
          source.disconnect()
          node.port.postMessage({ type: 'flush' })
        }).finally(cancel)
        return stopped
      },
    }
  } catch (error) {
    await context.close().catch(() => undefined)
    throw error
  }
}
