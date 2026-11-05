import { act, renderHook } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { ChatConversation, ChatEvent } from '../api/generated'
import { mergeChatEvent, useChatEvents } from './useChatEvents'

import { conversation as draft } from '../test/chatFixtures'

const progress: ChatEvent = { kind: 'progress', id: draft.id, version: 3, attemptId: 'attempt',
  attemptStartedAt: '2026-11-04T08:00:00Z', progress: ['thinking', 'tree_loaded'], tokenUsage: { input: 10, output: 5, total: 15 } }

class Source {
  static instances: Source[] = []
  onopen: (() => void) | null = null
  onmessage: ((message: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  close = vi.fn()
  url: string
  options: EventSourceInit
  constructor(url: string, options: EventSourceInit) { this.url = url; this.options = options; Source.instances.push(this) }
  message(event: ChatEvent) { this.onmessage?.({ data: JSON.stringify(event) }) }
}

function setup(handler?: (path: string, init?: RequestInit) => Promise<unknown>) {
  const calls = vi.fn(handler ?? (async (path: string) => path.endsWith('/events')
    ? { url: '/.well-known/mercure?match=test', expiresIn: 300 } : draft))
  const applied = vi.fn()
  const hook = renderHook(({ path }: { path: string | null }) => {
    const [saved, setSaved] = useState<ChatConversation | null>(draft)
    const stream = useChatEvents(path, calls as AuthorizedJsonRequest, setSaved, applied)
    return { saved, ...stream }
  }, { initialProps: { path: '/chats/chat' as string | null } })
  return { ...hook, calls, applied }
}

describe('private tree merge SSE', () => {
  beforeEach(() => { vi.useFakeTimers(); Source.instances = []; vi.stubGlobal('EventSource', Source) })
  afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals() })

  it('subscribes, catches up once per connection, consumes progress and never polls state', async () => {
    const { result, calls, unmount, applied } = setup()
    await act(async () => {})
    const source = Source.instances[0]!
    expect(source.url).toBe('/.well-known/mercure?match=test')
    expect(source.options).toEqual({ withCredentials: true })
    await act(async () => source.onopen?.())
    act(() => source.message(progress))
    expect(result.current.saved?.status).toBe('processing')
    expect(result.current.saved?.progress).toEqual(['thinking', 'tree_loaded'])
    await act(async () => vi.advanceTimersByTimeAsync(20000))
    expect(calls).toHaveBeenCalledTimes(2)
    act(() => source.message({ kind: 'conversation', conversation: { ...draft, version: 4, status: 'idle' } }))
    expect(result.current.saved?.status).toBe('idle')
    await act(async () => vi.advanceTimersByTimeAsync(220000))
    expect(calls).toHaveBeenCalledTimes(3)
    expect(calls.mock.lastCall?.[0]).toMatch(/\/events$/)
    expect(Source.instances).toHaveLength(1)
    act(() => source.message({ kind: 'tool', id: draft.id, version: 5, tool: 'example', action: 'done', operationId: 'operation', result: {} }))
    expect(applied).toHaveBeenCalledOnce()
    unmount()
    expect(source.close).toHaveBeenCalledOnce()
    expect(calls.mock.calls[0]?.[1]?.signal?.aborted).toBe(true)
  })

  it('reconnects after an interruption, restores missed state and cleans up on switching drafts', async () => {
    const { result, calls, rerender } = setup()
    await act(async () => {})
    act(() => Source.instances[0]!.onerror?.())
    expect(result.current.error).toBe('chat.streamDisconnected')
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(Source.instances).toHaveLength(2)
    await act(async () => Source.instances[1]!.onopen?.())
    expect(result.current.error).toBeNull()
    expect(calls).toHaveBeenCalledTimes(3)
    rerender({ path: null })
    expect(Source.instances[1]!.close).toHaveBeenCalledOnce()
    expect(result.current.error).toBeNull()
    await act(async () => vi.advanceTimersByTimeAsync(300000))
    expect(calls).toHaveBeenCalledTimes(3)
  })

  it('clears a discarded draft in another tab and ignores removal of another draft', async () => {
    const { result } = setup()
    await act(async () => {})
    act(() => Source.instances[0]!.message({ kind: 'removed', id: 'other' }))
    expect(result.current.saved).toEqual(draft)
    act(() => Source.instances[0]!.message({ kind: 'removed', id: draft.id }))
    expect(result.current.saved).toBeNull()
  })

  it('clears expired drafts when renewing the subscription without retrying missing resources', async () => {
    const { result, calls } = setup(async () => { throw new ApiRequestError(404, { code: 'CHAT_NOT_FOUND', message: null, fields: {}, details: {} }) })
    await act(async () => {})
    expect(result.current.saved).toBeNull()
    expect(result.current.error).toBeNull()
    await act(async () => vi.advanceTimersByTimeAsync(300000))
    expect(calls).toHaveBeenCalledOnce()
  })

  it('backs off authorization errors, stops on unauthorized and supports manual reconnection', async () => {
    let status = 503
    const { result, calls } = setup(async () => { throw new ApiRequestError(status, { code: 'UNAVAILABLE', message: 'Unavailable', fields: {}, details: {} }) })
    await act(async () => {})
    expect(result.current.error).toBe('chat.streamDisconnected')
    status = 401
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(result.current.error).toBe('chat.unauthorized')
    await act(async () => vi.advanceTimersByTimeAsync(300000))
    expect(calls).toHaveBeenCalledTimes(2)
    await act(async () => result.current.reconnect())
    expect(calls).toHaveBeenCalledTimes(3)
  })

  it('ignores a late subscription or snapshot after unmount and recovers malformed events', async () => {
    let resolve!: (value: unknown) => void
    const pending = setup(() => new Promise((done) => { resolve = done }))
    pending.unmount()
    await act(async () => resolve({ url: '/events', expiresIn: 300 }))
    expect(Source.instances).toHaveLength(0)
    const active = setup((path) => path.endsWith('/events') ? Promise.resolve({ url: '/events', expiresIn: 300 })
      : new Promise((done) => { resolve = done }))
    await act(async () => {})
    act(() => Source.instances[0]!.onmessage?.({ data: '{' }))
    expect(active.result.current.error).toBe('chat.streamDisconnected')
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    act(() => Source.instances[1]!.onopen?.())
    active.unmount()
    await act(async () => resolve({ ...draft, version: 4, state: 'applied' }))
    expect(active.applied).not.toHaveBeenCalled()
  })

  it('rejects stale attempts and mismatched drafts and merges out-of-order progress monotonically', () => {
    expect(mergeChatEvent(null, progress)).toBeNull()
    const running = mergeChatEvent(draft, progress)!
    expect(mergeChatEvent(running, { ...progress, version: 2 })).toBe(running)
    expect(mergeChatEvent(running, { ...progress, attemptId: 'old' })).toBe(running)
    expect(mergeChatEvent(running, { ...progress, id: 'other' })).toBe(running)
    const small = { ...progress, progress: ['thinking'] as const, tokenUsage: draft.tokenUsage }
    const late = mergeChatEvent(running, { ...small, progress: [...small.progress] })!
    expect(late.progress).toEqual(running.progress)
    expect(late.tokenUsage).toEqual(running.tokenUsage)
    const earlierSnapshot = { ...running, progress: [], tokenUsage: draft.tokenUsage }
    expect(mergeChatEvent(running, { kind: 'conversation', conversation: earlierSnapshot })).toEqual(running)
    for (const invalid of [{ ...draft, id: 'other' }, { ...draft, profile: 'other' }, draft]) {
      expect(mergeChatEvent(running, { kind: 'conversation', conversation: invalid })).toBe(running)
    }
    const finished = { ...running, status: 'idle' as const }
    expect(mergeChatEvent(finished, progress)).toBe(finished)
    expect(mergeChatEvent(running, { ...progress, version: 5, attemptId: 'new' })?.version).toBe(5)
    expect(mergeChatEvent(running, { kind: 'conversation', conversation: { ...draft, version: 5 } })).toEqual({ ...draft, version: 5 })
  })
})
