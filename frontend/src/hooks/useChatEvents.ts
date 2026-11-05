import { useEffect, useEffectEvent, useState, type Dispatch, type SetStateAction } from 'react'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { ChatConversation, ChatEvent, ChatSubscription } from '../api/generated'

// REST responses and SSE can cross in flight. Never roll back a newer draft or a finished attempt.
export function mergeChatEvent(current: ChatConversation | null, event: ChatEvent): ChatConversation | null {
  if (!current) return current
  if (event.kind === 'removed') return event.id === current.id ? null : current
  if (event.kind === 'conversation') {
    const next = event.conversation
    if (next.id !== current.id || next.profile !== current.profile || next.version < current.version) return current
    if (next.version !== current.version || next.attemptId !== current.attemptId) return next
    return { ...next, progress: current.progress.length > next.progress.length ? current.progress : next.progress,
      tokenUsage: current.tokenUsage.total > next.tokenUsage.total ? current.tokenUsage : next.tokenUsage }
  }
  if (event.kind !== 'progress' || event.id !== current.id || event.version < current.version
    || (event.version === current.version && (event.attemptId !== current.attemptId || current.status !== 'processing'))) return current
  const sameAttempt = event.attemptId === current.attemptId
  return { ...current, version: event.version, status: 'processing', attemptId: event.attemptId,
    attemptStartedAt: event.attemptStartedAt,
    progress: sameAttempt && current.progress.length > event.progress.length ? current.progress : event.progress,
    tokenUsage: sameAttempt && current.tokenUsage.total > event.tokenUsage.total ? current.tokenUsage : event.tokenUsage }
}

export function useChatEvents(path: string | null, request: AuthorizedJsonRequest,
  setConversation: Dispatch<SetStateAction<ChatConversation | null>>, onTool: (event: Extract<ChatEvent, { kind: 'tool' }>) => void, onOpen?: () => void) {
  const [error, setError] = useState<string | null>(null)
  const [revision, setRevision] = useState(0)
  const tool = useEffectEvent(onTool)
  const opened = useEffectEvent(() => onOpen?.())

  useEffect(() => {
    if (!path) return
    const controller = new AbortController()
    let source: EventSource | null = null
    let timer: number | undefined
    let failures = 0
    let authorizing = false

    function receive(event: ChatEvent) {
      if (controller.signal.aborted) return
      setConversation((current) => mergeChatEvent(current, event))
      if (event.kind === 'tool') tool(event)
    }

    function failed(cause?: unknown) {
      if (controller.signal.aborted) return
      source?.close()
      source = null
      window.clearTimeout(timer)
      if (cause instanceof ApiRequestError && cause.status === 404) {
        setConversation(null)
        setError(null)
        return
      }
      const unauthorized = cause instanceof ApiRequestError && cause.status === 401
      setError(unauthorized ? 'chat.unauthorized' : 'chat.streamDisconnected')
      if (!unauthorized) timer = window.setTimeout(() => void authorize(), Math.min(30000, 2000 * 2 ** failures++))
    }

    async function authorize() {
      if (authorizing || controller.signal.aborted) return
      authorizing = true
      try {
        const subscription = await request<ChatSubscription>(`${path}/events`, { method: 'POST', signal: controller.signal })
        if (controller.signal.aborted) return
        window.clearTimeout(timer)
        // Refresh authorization only. A healthy connection needs no repeated state GETs.
        timer = window.setTimeout(() => void authorize(), Math.max(1000, (subscription.expiresIn - 60) * 1000))
        if (source) return
        source = new EventSource(subscription.url, { withCredentials: true })
        source.onopen = () => {
          failures = 0
          setError(null)
          // The hub keeps no history. One snapshot closes the initial/reconnect race.
          void request<ChatConversation>(path!, { signal: controller.signal }).then((draft) => {
            receive({ kind: 'conversation', conversation: draft })
            opened()
          }).catch(failed)
        }
        source.onmessage = (message) => {
          try { receive(JSON.parse(message.data) as ChatEvent) }
          catch { failed() }
        }
        source.onerror = () => failed()
      } catch (cause) { failed(cause) }
      finally { authorizing = false }
    }
    void authorize()
    return () => { controller.abort(); source?.close(); window.clearTimeout(timer) }
  }, [path, request, setConversation, revision])

  return { error: path ? error : null, reconnect: () => setRevision((value) => value + 1) }
}
