import { createServer, type Server } from 'node:http'
import { once } from 'node:events'
import type { AddressInfo } from 'node:net'
import WebSocket, { WebSocketServer } from 'ws'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createGateway } from '../src/server.js'
import type { VoiceConfig } from '../src/stream.js'

type Event = Record<string, unknown>
const id = '01900000-0000-7000-8000-000000000001'
const cleanup: (() => Promise<void>)[] = []
afterEach(async () => { vi.restoreAllMocks(); for (const close of cleanup.splice(0).reverse()) await close() })
const port = (server: Server | WebSocketServer) => (server.address() as AddressInfo).port

async function fixture(options: Partial<VoiceConfig> = {}, ready = true) {
  let authError: string | undefined
  let language = 'ru'
  const operations: string[] = []
  const auth = createServer((request, response) => {
    let body = ''
    request.on('data', (chunk: Buffer) => { body += chunk.toString() })
    request.on('end', () => {
      const operation = (JSON.parse(body) as { operation: string }).operation
      operations.push(operation)
      response.writeHead(authError && operation !== 'release' ? 403 : 200, { 'Content-Type': 'application/json' })
      response.end(JSON.stringify(authError && operation !== 'release' ? { code: authError } : { language }))
    })
  })
  auth.listen(0, '127.0.0.1')
  await once(auth, 'listening')
  const speaches = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await once(speaches, 'listening')
  let peer!: WebSocket
  let session: Event = { input_audio_format: 'pcm16' }
  let onAppend: ((bytes: Buffer) => void) | undefined
  let onCommit: (() => void) | undefined
  let onConfigure: ((value: Event) => Event) | undefined
  const upstreamEvents: Event[] = []
  const send = (event: Event) => peer.send(JSON.stringify(event))
  speaches.on('connection', (socket) => {
    peer = socket
    socket.on('error', () => undefined)
    send({ type: 'session.created' })
    socket.on('message', (raw) => {
      const event = JSON.parse(raw.toString()) as Event
      upstreamEvents.push(event)
      if (event.type === 'session.update') {
        session = { ...session, ...event.session as Event }
        if (onConfigure) session = onConfigure(session)
        send({ type: 'session.updated', session })
      } else if (event.type === 'input_audio_buffer.append') {
        onAppend?.(Buffer.from(event.audio as string, 'base64'))
      } else if (event.type === 'input_audio_buffer.commit') {
        if (onCommit) onCommit()
        else {
          send({ type: 'input_audio_buffer.committed', item_id: 'silent' })
          send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'silent', transcript: '' })
        }
      }
    })
  })
  const gateway = createGateway({ authUrl: `http://127.0.0.1:${port(auth)}`,
    speachesUrl: `ws://127.0.0.1:${port(speaches)}/v1/realtime`, model: 'small', origins: ['https://localhost'], ...options })
  gateway.server.listen(0, '127.0.0.1')
  await once(gateway.server, 'listening')
  const url = `ws://127.0.0.1:${port(gateway.server)}/api/v1/chat-transcriptions/stream`
  const client = new WebSocket(url, { origin: 'https://localhost' })
  const messages: Event[] = []
  client.on('message', (raw) => messages.push(JSON.parse(raw.toString()) as Event))
  client.on('error', () => undefined)
  await once(client, 'open')
  cleanup.push(async () => {
    client.terminate()
    for (const socket of gateway.sockets.clients) socket.terminate()
    for (const socket of speaches.clients) socket.terminate()
    await Promise.all([
      new Promise<void>((resolve) => gateway.server.close(() => resolve())),
      new Promise<void>((resolve) => speaches.close(() => resolve())),
    ])
    auth.closeAllConnections()
    await new Promise<void>((resolve) => auth.close(() => resolve()))
  })
  const start = () => client.send(JSON.stringify({ type: 'start', sessionId: id, credential: 'credential' }))
  const wait = async (type: string) => {
    await vi.waitFor(() => expect(messages.some((message) => message.type === type)).toBe(true))
    return messages.find((message) => message.type === type)!
  }
  if (ready) { start(); await wait('ready') }
  return { client, messages, send, wait, start, operations, upstreamEvents, gateway, url,
    setAuthError: (value: string) => { authError = value }, setLanguage: (value: string) => { language = value },
    onAppend: (handler: typeof onAppend) => { onAppend = handler },
    onCommit: (handler: typeof onCommit) => { onCommit = handler },
    onConfigure: (handler: typeof onConfigure) => { onConfigure = handler },
    peer: () => peer,
  }
}

describe('voice gateway', () => {
  it('authenticates and configures transcription-only sessions, then orders and deduplicates phrases', async () => {
    const f = await fixture()
    expect(f.operations).toContain('claim')
    const config = f.upstreamEvents.find((event) => event.type === 'session.update')!.session as Event
    expect(config.input_audio_transcription).toEqual({ model: 'small', language: 'ru' })
    expect(config.turn_detection).toMatchObject({ create_response: false, threshold: 0.5, silence_duration_ms: 700 })
    for (const item of ['first', 'second']) {
      f.send({ type: 'input_audio_buffer.speech_started', item_id: item })
      f.send({ type: 'input_audio_buffer.speech_stopped', item_id: item, audio_end_ms: 100 })
      f.send({ type: 'input_audio_buffer.committed', item_id: item })
    }
    f.send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'second', transcript: 'Папа — Иван.' })
    f.send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'first', transcript: '  Мама — Вера.  ' })
    await f.wait('phrase')
    await vi.waitFor(() => expect(f.messages.filter((event) => event.type === 'phrase')).toHaveLength(2))
    expect(f.messages.filter((event) => event.type === 'phrase').map((event) => event.text)).toEqual(['Мама — Вера.', 'Папа — Иван.'])
    f.send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'first', transcript: 'duplicate' })
    f.client.send('{"type":"stop"}')
    expect((await f.wait('done')).durationSeconds).toBe(0)
    expect(f.messages.filter((event) => event.type === 'phrase')).toHaveLength(2)
    expect(f.operations.filter((value) => value === 'check').length).toBeGreaterThanOrEqual(3)
  })

  it('flushes active speech with internal silence and waits for its last transcript', async () => {
    const f = await fixture()
    f.onAppend((bytes) => {
      if (bytes.length === 4800) f.send({ type: 'input_audio_buffer.speech_started', item_id: 'tail' })
      if (bytes.length === 153600) {
        f.send({ type: 'input_audio_buffer.speech_stopped', item_id: 'tail', audio_end_ms: 3300 })
        f.send({ type: 'input_audio_buffer.committed', item_id: 'tail' })
        f.send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'tail', transcript: 'Last phrase' })
      }
    })
    f.client.send(Buffer.alloc(4800))
    f.client.send('{"type":"stop"}')
    const done = await f.wait('done')
    expect(done.durationSeconds).toBe(0.1)
    expect(f.messages.find((event) => event.type === 'phrase')?.text).toBe('Last phrase')
  })

  it('commits an unclassified tail and ignores an empty transcription', async () => {
    const f = await fixture()
    f.client.send(Buffer.alloc(4800))
    f.client.send('{"type":"stop"}')
    await f.wait('done')
    expect(f.upstreamEvents.some((event) => event.type === 'input_audio_buffer.commit')).toBe(true)
    expect(f.messages.some((event) => event.type === 'phrase')).toBe(false)
  })

  it('enforces sample and wall-clock limits without counting internal padding', async () => {
    const f = await fixture({ maxAudioBytes: 6000 })
    f.client.send(Buffer.alloc(4800))
    f.client.send(Buffer.alloc(4800))
    f.client.send(Buffer.alloc(4800))
    expect((await f.wait('done')).durationSeconds).toBe(0.125)
    const timed = await fixture({ recordingTimeoutMs: 20 })
    await timed.wait('done')
  })

  it('accepts 180 seconds of audio with the default sample limit and then stops', async () => {
    const f = await fixture()
    let received = 0
    f.onAppend((bytes) => { received += bytes.length })
    for (let second = 1; second <= 180; second++) {
      f.client.send(Buffer.alloc(48000))
      await vi.waitFor(() => expect(received).toBeGreaterThanOrEqual(second * 48000), { interval: 1 })
      if (second < 180) expect(f.messages.some((message) => message.type === 'done')).toBe(false)
    }
    expect((await f.wait('done')).durationSeconds).toBe(180)
  })

  it.each(['CHAT_BANNED', 'CHAT_UNAVAILABLE', 'AUTH_REQUIRED'])('stops when active access changes: %s', async (code) => {
    const f = await fixture({ checkIntervalMs: 20 })
    f.setAuthError(code)
    expect((await f.wait('error')).code).toBe(code)
  })

  it('rechecks access before releasing a phrase', async () => {
    const f = await fixture()
    f.setAuthError('CHAT_BANNED')
    f.send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'blocked', transcript: 'never release' })
    await f.wait('error')
    expect(f.messages.some((event) => event.type === 'phrase')).toBe(false)
  })

  it.each(['[]', 'broken', '{"type":"stop"}', '{"type":"start","sessionId":"wrong","credential":"x"}'])('rejects invalid startup messages: %s', async (message) => {
    const f = await fixture({}, false)
    f.client.send(message)
    await f.wait('error')
  })

  it('rejects audio before authentication and invalid PCM frames', async () => {
    const waiting = await fixture({}, false)
    waiting.client.send(Buffer.alloc(2))
    expect((await waiting.wait('error')).code).toBe('CHAT_AUDIO_FORMAT')
    for (const length of [0, 3]) {
      const f = await fixture()
      f.client.send(Buffer.alloc(length))
      expect((await f.wait('error')).code).toBe('CHAT_AUDIO_FORMAT')
    }
  })

  it('requires timely authentication and accepts cancellation while preparing', async () => {
    const timed = await fixture({ startTimeoutMs: 20 }, false)
    expect((await timed.wait('error')).code).toBe('AUTH_REQUIRED')
    const cancelled = await fixture({}, false)
    const closed = once(cancelled.client, 'close')
    cancelled.client.send('{"type":"cancel"}')
    await closed
    expect(cancelled.operations).toHaveLength(0)
  })

  it('rejects failed grants and unsupported languages', async () => {
    const denied = await fixture({}, false)
    denied.setAuthError('AUTH_REQUIRED')
    denied.start()
    expect((await denied.wait('error')).code).toBe('AUTH_REQUIRED')
    expect(denied.operations).not.toContain('release')
    const badLanguage = await fixture({}, false)
    badLanguage.setLanguage('de')
    badLanguage.start()
    await badLanguage.wait('error')
  })

  it('accepts only the known configuration warning from Speaches 0.8.3', async () => {
    const f = await fixture({}, false)
    f.onConfigure((session) => {
      f.send({ type: 'error', error: { message: 'Specifying `session.turn_detection.prefix_padding_ms` is not supported. The server either does not support this field or it is not configurable.' } })
      return session
    })
    f.start()
    await f.wait('ready')
    expect(f.messages.some((message) => message.type === 'error')).toBe(false)
    f.client.send('{"type":"cancel"}')
  })

  it('enforces the frame size at the WebSocket boundary', async () => {
    const f = await fixture()
    const closed = once(f.client, 'close')
    f.client.send(Buffer.alloc(65538))
    expect((await closed)[0]).toBe(1009)
  })

  it('fails if Speaches ignores transcription-only configuration', async () => {
    const f = await fixture({}, false)
    f.onConfigure((session) => ({ ...session, turn_detection: { create_response: true } }))
    f.start()
    await f.wait('error')
  })

  it.each([
    { type: 'error', error: { message: 'Other error' } },
    { type: 'conversation.item.input_audio_transcription.failed' },
    { type: 'conversation.item.input_audio_transcription.completed', item_id: 'bad', transcript: false },
    { type: 'conversation.item.input_audio_transcription.completed', item_id: 'bad', transcript: 'x'.repeat(10001) },
    { type: 'input_audio_buffer.speech_started', item_id: '' },
    { type: 'input_audio_buffer.speech_stopped', item_id: 'bad', audio_end_ms: -1 },
  ])('handles provider failure $type', async (event) => {
    const f = await fixture()
    f.send(event)
    await f.wait('error')
  })

  it('handles broken upstream frames and connections', async () => {
    const malformed = await fixture()
    malformed.peer().send('broken')
    await malformed.wait('error')
    const binary = await fixture()
    binary.peer().send(Buffer.alloc(2))
    await binary.wait('error')
    const lost = await fixture()
    lost.peer().close()
    await lost.wait('error')
  })

  it('times out unfinished phrases and rejects saturated queues', async () => {
    const timed = await fixture({ finishTimeoutMs: 20 })
    timed.send({ type: 'input_audio_buffer.speech_started', item_id: 'unfinished' })
    timed.client.send('{"type":"stop"}')
    await timed.wait('error')
    const full = await fixture({ maxBufferedBytes: 1024 })
    full.client.send(Buffer.alloc(4800))
    await full.wait('error')
  })

  it('serves health and rejects foreign origins and unknown paths', async () => {
    const f = await fixture()
    expect(await (await fetch(f.url.replace('ws:', 'http:'))).text()).toBe('ok')
    for (const [url, origin] of [[f.url, 'https://foreign.example'], [f.url + '/other', 'https://localhost']]) {
      const client = new WebSocket(url!, { origin: origin! })
      const error = await once(client, 'error')
      expect(String(error[0])).toContain('403')
    }
  })
})
