import { useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { ChatConversation, TreeMergeApplied, TreeMergePendingProposal, TreeMergeResult, TreeSnapshot } from '../api/generated'
import { ChatPanel } from './ChatPanel'
import { TreeMergePreview } from './TreeMergePreview'

type Props = {
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onClose: () => void
  open?: boolean
  onApplied?: (result: TreeMergeResult) => Promise<void>
  onRefreshTree?: () => Promise<void>
  onProposalChange?: (proposal: TreeMergePendingProposal | null) => void
  previewContainer?: HTMLElement | null
}

function pendingMerge(conversation: ChatConversation): TreeMergePendingProposal | null {
  // An agent can replace its proposal several times within the same turn.
  if (conversation.status === 'processing') return null
  return (conversation.tools.merge as TreeMergePendingProposal | undefined) ?? null
}

/** Connects the tree profile's merge tool to its preview and confirmation UI. */
export function TreeChatPanel({ snapshot, request, onApplied, onRefreshTree, onProposalChange, previewContainer, ...panel }: Props) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const applied = useRef(new Map<string, Promise<void>>())
  // Keep a failed UI refresh retryable in this tab; application receipts are not stored on the server.
  const pendingRefresh = useRef<{ id: string; result: TreeMergeResult } | null>(null)
  const previousStatus = useRef<string | undefined>(undefined)
  const appliedDuringAttempt = useRef(false)

  async function acceptApplied(id: string, result: TreeMergeResult) {
    appliedDuringAttempt.current = true
    const previous = applied.current.get(id)
    if (previous) return previous
    pendingRefresh.current = { id, result }
    const promise = Promise
      .resolve(
        result.newPersonIds?.length === 0 && onRefreshTree ? onRefreshTree() : onApplied?.(result)
      )
      .then(
        () => {
          if (pendingRefresh.current?.id === id) pendingRefresh.current = null
        }
      )
    applied.current.set(id, promise)
    try { await promise }
    catch (cause) { applied.current.delete(id); throw cause }
  }

  async function action(conversation: ChatConversation, proposal: TreeMergePendingProposal, name: 'apply' | 'discard' | 'revalidate', update: (next: ChatConversation) => void) {
    setBusy(true)
    setError(null)
    const path = `/trees/${snapshot.tree.id}/merge-proposals/${proposal.id}`
    try {
      const options = { method: name === 'discard' ? 'DELETE' : 'POST',
        headers: name === 'apply' ? { 'If-Match': proposal.treeEtag } : {},
        body: JSON.stringify({ version: proposal.version }) }
      if (name === 'apply') {
        const next = await request<TreeMergeApplied>(`${path}/apply`, options)
        update(next.conversation)
        await acceptApplied(next.operationId, next.result)
      } else {
        update(await request<ChatConversation>(name === 'discard' ? path : `${path}/revalidate`, options))
        if (name === 'revalidate') await onRefreshTree?.()
      }
    } catch (cause) {
      setError(
        cause instanceof ApiRequestError && cause.status === 412 ? 'treeMerge.treeConflict'
          : cause instanceof ApiRequestError && cause.status === 409 ? 'chat.conflict' : 'chat.failed'
      )
      // An interrupted apply response is ambiguous. Read fresh state/tree; never replay a consumed proposal.
      try { update(await request<ChatConversation>(`/chats/${conversation.id}`)); await onRefreshTree?.() }
      catch { /* Keep the last reviewed preview until a later refresh. */ }
    } finally { setBusy(false) }
  }

  return <ChatPanel {...panel} request={request} contextPath={`/trees/${snapshot.tree.id}/chat`}
    createBody={{ referencePersonId: null }} disabled={busy} placeholder={t('treeMerge.placeholder')}
    onConversationChange={(conversation) => {
      onProposalChange?.(conversation ? pendingMerge(conversation) : null)
      const finished = previousStatus.current === 'processing' && conversation?.status !== 'processing'
      if (previousStatus.current !== 'processing' && conversation?.status === 'processing') appliedDuringAttempt.current = false
      previousStatus.current = conversation?.status
      if (finished && !appliedDuringAttempt.current) void onRefreshTree?.().catch(() => setError('chat.loadFailed'))
    }}
    onStreamOpen={() => { void onRefreshTree?.().catch(() => setError('chat.loadFailed')) }}
    onToolEvent={(event) => {
      if (['merge', 'change_tree'].includes(event.tool) && event.action === 'applied') {
        void acceptApplied(event.operationId, event.result as TreeMergeResult).catch(() => setError('chat.loadFailed'))
      }
    }}
    renderTools={(conversation, chatBusy, update) => {
      const proposal = pendingMerge(conversation)
      return <>
        {error && <div role="alert"><p>{t(error)}</p><button type="button" disabled={busy} onClick={() => {
          const pending = pendingRefresh.current
          void (pending ? acceptApplied(pending.id, pending.result) : onRefreshTree?.())?.then(() => setError(null)).catch(() => setError('chat.loadFailed'))
        }}>{t('chat.reload')}</button></div>}
        {proposal && previewContainer !== null && createPortal(
          <TreeMergePreview key={`${proposal.id}:${proposal.version}`}
            proposal={proposal} busy={busy || chatBusy}
            onApply={() => action(conversation, proposal, 'apply', update)}
            onDiscard={() => action(conversation, proposal, 'discard', update)}
            onRevalidate={() => action(conversation, proposal, 'revalidate', update)} />,
          previewContainer ?? document.body
        )}
      </>
    }} />
}
