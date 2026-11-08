import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import type { TreeSnapshot } from '../api/generated'
import { ChatPanel } from './ChatPanel'

type Props = {
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onClose: () => void
  open?: boolean
  onApplied?: () => Promise<void>
  onRefreshTree?: () => Promise<void>
}

/** Refresh committed changes independently of the agent's tool loop. */
export function TreeChatPanel({ snapshot, request, onApplied, onRefreshTree, ...panel }: Props) {
  const { t } = useTranslation()
  const applied = useRef(new Set<string>())
  const previousStatus = useRef<string | undefined>(undefined)
  const [refreshFailed, setRefreshFailed] = useState(false)
  async function refresh(afterAction = false) {
    try {
      if (afterAction && onApplied) await onApplied()
      else await onRefreshTree?.()
      setRefreshFailed(false)
    } catch { setRefreshFailed(true) }
  }
  return <ChatPanel {...panel} request={request} personActions contextPath={`/trees/${snapshot.tree.id}/chat`}
    createBody={{ referencePersonId: null }} placeholder={t('chat.treePlaceholder')}
    onStreamOpen={() => { void refresh() }}
    onConversationChange={(conversation) => {
      const wasProcessing = previousStatus.current === 'processing'
      previousStatus.current = conversation?.status
      if (wasProcessing && conversation?.status !== 'processing') void refresh()
    }}
    onToolEvent={(event) => {
      if (event.action !== 'applied' || applied.current.has(event.operationId)) return
      applied.current.add(event.operationId)
      void refresh(true)
    }}
    renderTools={() => refreshFailed && <div role="alert">
      <p>{t('chat.loadFailed')}</p>
      <button type="button" className="secondary-button" onClick={() => { void refresh() }}>{t('chat.reload')}</button>
    </div>} />
}
