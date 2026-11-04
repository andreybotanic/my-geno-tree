import { useEffect, useEffectEvent, useState, type Dispatch, type SetStateAction } from 'react'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { TreeMergeDraft, TreeMergeEvent, TreeMergeSubscription } from '../api/generated'

// REST responses and SSE can cross in flight. Never roll back a newer draft or a finished attempt.
export function mergeTreeMergeEvent(current: TreeMergeDraft | null, event: TreeMergeEvent): TreeMergeDraft | null {
  if (!current) return current
  if (event.kind === 'draft') {
    const next = event.draft
    if (next.id !== current.id || next.treeId !== current.treeId || next.version < current.version) return current
    if (next.state === 'cancelled') return null
    if (next.version !== current.version || next.attemptId !== current.attemptId) return next
    return { ...next, progress: current.progress.length > next.progress.length ? current.progress : next.progress,
      tokenUsage: current.tokenUsage.total > next.tokenUsage.total ? current.tokenUsage : next.tokenUsage }
  }
  if (event.kind !== 'progress' || event.id !== current.id || event.version < current.version
    || (event.version === current.version && (event.attemptId !== current.attemptId || current.state !== 'processing'))) return current
  const sameAttempt = event.attemptId === current.attemptId
  return { ...current, version: event.version, state: 'processing', attemptId: event.attemptId,
    attemptStartedAt: event.attemptStartedAt, attemptSourceVersion: current.sourceVersion,
    progress: sameAttempt && current.progress.length > event.progress.length ? current.progress : event.progress,
    tokenUsage: sameAttempt && current.tokenUsage.total > event.tokenUsage.total ? current.tokenUsage : event.tokenUsage }
}

export function useTreeMergeEvents(path: string | null, request: AuthorizedJsonRequest,
  setDraft: Dispatch<SetStateAction<TreeMergeDraft | null>>, onApplied: (draft: TreeMergeDraft) => void) {
  const [error, setError] = useState<string | null>(null)
  const [revision, setRevision] = useState(0)
  const applied = useEffectEvent(onApplied)

  useEffect(() => {
    if (!path) return
    const controller = new AbortController()
    let source: EventSource | null = null
    let timer: number | undefined
    let failures = 0
    let authorizing = false

    function receive(event: TreeMergeEvent) {
      if (controller.signal.aborted) return
      setDraft((current) => mergeTreeMergeEvent(current, event))
      if (event.kind === 'draft' && event.draft.state === 'applied') applied(event.draft)
    }

    function failed(cause?: unknown) {
      if (controller.signal.aborted) return
      source?.close()
      source = null
      window.clearTimeout(timer)
      const unauthorized = cause instanceof ApiRequestError && (cause.status === 401 || cause.status === 404)
      setError(unauthorized ? 'treeMerge.unauthorized' : 'treeMerge.streamDisconnected')
      if (!unauthorized) timer = window.setTimeout(() => void authorize(), Math.min(30000, 2000 * 2 ** failures++))
    }

    async function authorize() {
      if (authorizing || controller.signal.aborted) return
      authorizing = true
      try {
        const subscription = await request<TreeMergeSubscription>(`${path}/events`, { method: 'POST', signal: controller.signal })
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
          void request<TreeMergeDraft>(path!, { signal: controller.signal }).then((draft) => {
            receive({ kind: 'draft', draft })
          }).catch(failed)
        }
        source.onmessage = (message) => {
          try { receive(JSON.parse(message.data) as TreeMergeEvent) }
          catch { failed() }
        }
        source.onerror = () => failed()
      } catch (cause) { failed(cause) }
      finally { authorizing = false }
    }
    void authorize()
    return () => { controller.abort(); source?.close(); window.clearTimeout(timer) }
  }, [path, request, setDraft, revision])

  return { error: path ? error : null, reconnect: () => setRevision((value) => value + 1) }
}
