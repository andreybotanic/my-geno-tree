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
    const merged = { ...next,
      messages: JSON.stringify(next.messages) === JSON.stringify(current.messages) ? current.messages : next.messages,
      tools: JSON.stringify(next.tools) === JSON.stringify(current.tools) ? current.tools : next.tools,
      progress: current.progress.length > next.progress.length ? current.progress : next.progress,
      tokenUsage: current.tokenUsage.total > next.tokenUsage.total ? current.tokenUsage : next.tokenUsage }
    // A fresh server clock alone must not reset previews, scrolling or draft input.
    const comparable = { ...current, access: { ...current.access, serverTime: next.access.serverTime } }
    return JSON.stringify(merged) === JSON.stringify(comparable) ? current : merged
  }
  if (event.kind !== 'progress' || event.id !== current.id || event.version < current.version
    || (event.version === current.version && (event.attemptId !== current.attemptId || current.status !== 'processing'))) return current
  const sameAttempt = event.attemptId === current.attemptId
  const next = { ...current, version: event.version, status: 'processing' as const, attemptId: event.attemptId,
    attemptStartedAt: event.attemptStartedAt,
    progress: sameAttempt && current.progress.length > event.progress.length ? current.progress : event.progress,
    tokenUsage: sameAttempt && current.tokenUsage.total > event.tokenUsage.total ? current.tokenUsage : event.tokenUsage }
  return JSON.stringify(next.progress) === JSON.stringify(current.progress)
    && JSON.stringify(next.tokenUsage) === JSON.stringify(current.tokenUsage)
    && next.version === current.version && next.status === current.status && sameAttempt ? current : next
}

export function useChatEvents(
  path: string | null,
  request: AuthorizedJsonRequest,
  setConversation: Dispatch<SetStateAction<ChatConversation | null>>,
  onTool: (event: Extract<ChatEvent, { kind: 'tool' }>) => void,
  onOpen?: () => void
) {
  const [error, setError] = useState<string | null>(null)
  const [revision, setRevision] = useState(0)
  const tool = useEffectEvent(onTool)
  const opened = useEffectEvent(() => onOpen?.())

  useEffect(
    () => {
      if (!path) return
      const controller = new AbortController()
      let source: EventSource | null = null
      let candidate: EventSource | null = null
      let timer: number | undefined
      let errorTimer: number | undefined
      let failures = 0
      let authorizing = false
      let openedBefore = false

      function receive(event: ChatEvent) {
        if (controller.signal.aborted) return
        setConversation((current) => mergeChatEvent(current, event))
        if (event.kind === 'tool') tool(event)
      }

      function failed(cause?: unknown, failedSource?: EventSource) {
        if (controller.signal.aborted || (failedSource && failedSource !== source && failedSource !== candidate)) return
        window.clearTimeout(timer)
        if (cause instanceof ApiRequestError && (cause.status === 404 || cause.status === 401)) {
          source?.close()
          candidate?.close()
          source = candidate = null
          window.clearTimeout(errorTimer)
          errorTimer = undefined
          if (cause.status === 404) setConversation(null)
          setError(cause.status === 401 ? 'chat.unauthorized' : null)
          return
        }
        if (failedSource === source) { source?.close(); source = null }
        if (failedSource === candidate) { candidate?.close(); candidate = null }
        // A failed renewal does not tear down a healthy stream.
        if (!source && errorTimer === undefined) {
          errorTimer = window.setTimeout(() => {
            errorTimer = undefined
            if (!controller.signal.aborted && !source) setError('chat.streamDisconnected')
          }, 5000)
        }
        timer = window.setTimeout(() => void authorize(), Math.min(30000, 2000 * 2 ** failures++))
      }

      async function authorize() {
        if (authorizing || controller.signal.aborted) return
        authorizing = true
        try {
          const subscription = await request<ChatSubscription>(`${path}/events`, { method: 'POST', signal: controller.signal })
          if (controller.signal.aborted) return
          // In the login's final minute a new token cannot extend the subscription.
          const renewIn = subscription.expiresIn > 60 ? subscription.expiresIn - 60 : subscription.expiresIn
          const renewalAt = Date.now() + Math.max(1000, renewIn * 1000)
          window.clearTimeout(timer)
          candidate?.close()
          const next = new EventSource(subscription.url, { withCredentials: true })
          candidate = next
          next.onopen = () => {
            if (controller.signal.aborted || candidate !== next) return
            const uninterrupted = source !== null
            const reconnecting = openedBefore
            source?.close()
            source = next
            candidate = null
            openedBefore = true
            failures = 0
            window.clearTimeout(errorTimer)
            errorTimer = undefined
            setError(null)
            window.clearTimeout(timer)
            timer = window.setTimeout(() => void authorize(), Math.max(1000, renewalAt - Date.now()))
            // During planned rotation both streams overlap: no missed state and no extra GET.
            if (uninterrupted) return
            void request<ChatConversation>(path!, { signal: controller.signal })
              .then((draft) => {
                if (controller.signal.aborted || source !== next) return
                receive({ kind: 'conversation', conversation: draft })
                if (reconnecting) opened()
              })
              .catch((cause: unknown) => {
                if (source === next && cause instanceof ApiRequestError && (cause.status === 404 || cause.status === 401)) failed(cause)
              })
          }
          next.onmessage = (message) => {
            if (source !== next) return
            try { receive(JSON.parse(message.data) as ChatEvent) }
            catch (cause) { failed(cause, next) }
          }
          next.onerror = () => failed(undefined, next)
        } catch (cause) { failed(cause) }
        finally { authorizing = false }
      }
      void authorize()
      return () => {
        controller.abort()
        source?.close()
        candidate?.close()
        window.clearTimeout(timer)
        window.clearTimeout(errorTimer)
      }
    },
    [path, request, setConversation, revision]
  )

  return { error: path ? error : null, reconnect: () => setRevision((value) => value + 1) }
}
