import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import { ApiRequestError } from '../api/client'
import type { TreeMergeDraft, TreeMergeList, TreeMergeProposal, TreeMergeResult, TreeMergeTokenBudget, TreeSnapshot } from '../api/generated'
import { TreeMergeBudget } from './TreeMergeBudget'
import { TreeMergeThinking } from './TreeMergeThinking'
import { TreeMergePreview } from './TreeMergePreview'
import { TreeMergeVoiceInput } from './TreeMergeVoiceInput'

type Props = {
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onClose: () => void
  open?: boolean
  onApplied?: (result: TreeMergeResult) => Promise<void>
  onRefreshTree?: () => Promise<void>
  onDraftChange?: (draft: TreeMergeDraft | null) => void
  previewContainer?: HTMLElement | null
  selectedProposalPersonId?: string | null
  onCloseProposalPerson?: () => void
}

export function TreeMergePanel({ snapshot, request, onClose, onApplied, onRefreshTree, open = true, onDraftChange, previewContainer,
  selectedProposalPersonId, onCloseProposalPerson }: Props) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState<TreeMergeDraft | null>(null)
  const [budget, setBudget] = useState<TreeMergeTokenBudget | null>(null)
  const [content, setContent] = useState('')
  const [voiceBusy, setVoiceBusy] = useState(false)
  const [busy, setBusy] = useState(true)
  const [thinkingSince, setThinkingSince] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [observedAt, setObservedAt] = useState(Date.now)
  const textarea = useRef<HTMLTextAreaElement>(null)
  const messagesEnd = useRef<HTMLDivElement>(null)
  const pending = useRef<{ requestId: string; content: string } | null>(null)
  const base = `/trees/${snapshot.tree.id}/merges`
  const staleAttempt = draft?.state === 'processing' && draft.attemptStartedAt !== null &&
    observedAt - Date.parse(draft.attemptStartedAt) >= 5 * 60 * 1000

  useEffect(() => {
    try {
      for (const key of Object.keys(localStorage)) {
        if (key.startsWith('tree-merge-input:')) localStorage.removeItem(key)
      }
    } catch { /* Conversation content is never written to browser storage. */ }
    const controller = new AbortController()
    const selectedId = new URLSearchParams(window.location.search).get('merge')
    const loading = selectedId
      ? request<TreeMergeDraft>(`${base}/${encodeURIComponent(selectedId)}`, { signal: controller.signal }).then((item) => ({ items: [item], tokenBudget: item.tokenBudget }))
      : request<TreeMergeList>(base, { signal: controller.signal })
    loading.then(({ items, tokenBudget }) => {
        if (controller.signal.aborted) return
        const restored = items.find((item) => item.state !== 'cancelled') ?? null
        setDraft(restored)
        setBudget(tokenBudget ?? null)
        setBusy(false)
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setError('treeMerge.loadFailed')
          setBusy(false)
        }
      })
    return () => controller.abort()
  }, [base, request, snapshot.tree.id])

  useEffect(() => { onDraftChange?.(draft) }, [draft, onDraftChange])

  useLayoutEffect(() => {
    const input = textarea.current
    if (!input) return
    let width = -1
    function resize() {
      if (!input) return
      const style = getComputedStyle(input)
      const padding = (parseFloat(style.paddingTop) || 12) + (parseFloat(style.paddingBottom) || 0)
      const lineHeight = parseFloat(style.lineHeight) || 24
      input.style.height = 'auto'
      input.style.height = `${Math.min(Math.max(input.scrollHeight, lineHeight + padding), 7 * lineHeight + padding)}px`
    }
    resize()
    const observer = new ResizeObserver((entries) => {
      const next = entries[0]?.contentRect.width
      if (next !== undefined && next !== width) { width = next; resize() }
    })
    observer.observe(input)
    return () => observer.disconnect()
  }, [content, open, draft?.state])

  useEffect(() => {
    if (!draft || !open) return
    const url = new URL(window.location.href)
    url.searchParams.set('merge', draft.id)
    window.history.replaceState(window.history.state, '', url)
  }, [draft, open])

  useEffect(() => { messagesEnd.current?.scrollIntoView?.({ block: 'end' }) }, [draft?.messages.length, draft?.state])

  useEffect(() => {
    if (!draft || (draft.state !== 'processing' && thinkingSince === null)) return
    const controller = new AbortController()
    const timer = window.setInterval(() => {
      void request<TreeMergeDraft>(`${base}/${draft.id}`, { signal: controller.signal }).then((next) => {
        if (!controller.signal.aborted) { setDraft(next); setObservedAt(Date.now()) }
      }).catch(() => { /* The saved conversation remains available for retry. */ })
    }, 2000)
    return () => { controller.abort(); window.clearInterval(timer) }
  }, [base, draft, request, thinkingSince])

  useEffect(() => {
    if (open && !busy) textarea.current?.focus()
  }, [busy, draft?.id, open])

  function showError(cause: unknown) {
    setError(cause instanceof ApiRequestError && cause.status === 412 ? 'treeMerge.treeConflict'
      : cause instanceof ApiRequestError && cause.status === 409 ? 'treeMerge.conflict'
      : cause instanceof ApiRequestError && cause.status === 401 ? 'treeMerge.unauthorized'
      : cause instanceof ApiRequestError && cause.status === 422 ? 'treeMerge.invalidProposal' : 'treeMerge.failed')
  }

  function close() {
    const url = new URL(window.location.href)
    url.searchParams.delete('merge')
    window.history.replaceState(window.history.state, '', url)
    onClose()
  }

  async function proposalAction(action: 'proposal' | 'apply' | 'revalidate', proposal?: TreeMergeProposal) {
    if (!draft) return
    setBusy(true)
    setError(null)
    try {
      const next = await request<TreeMergeDraft>(`${base}/${draft.id}/${action}`, {
        method: action === 'proposal' ? 'PUT' : 'POST',
        headers: action === 'apply' ? { 'If-Match': draft.treeEtag } : {},
        body: JSON.stringify({ version: draft.version,
          ...(action === 'apply' ? { proposalVersion: draft.proposalVersion } : {}),
          ...(proposal ? { proposal } : {}),
        }),
      })
      if (action === 'revalidate') await onRefreshTree?.()
      setDraft(next)
      if (action === 'apply' && next.result) await onApplied?.(next.result)
    } catch (cause) {
      showError(cause)
      if (cause instanceof ApiRequestError && cause.status === 412) {
        try {
          const current = await request<TreeMergeDraft>(`${base}/${draft.id}`)
          await onRefreshTree?.()
          setDraft(current)
        } catch { /* Keep the reviewed input. */ }
      }
    } finally { setBusy(false) }
  }

  async function ensureDraft() {
    if (draft) return draft
    const next = await request<TreeMergeDraft>(base, {
      method: 'POST', body: JSON.stringify({ referencePersonId: null }),
    })
    setDraft(next)
    return next
  }

  async function send() {
    if (!content.trim()) return
    setBusy(true)
    setError(null)
    if (pending.current?.content !== content) {
      pending.current = { requestId: crypto.randomUUID(), content }
    }
    try {
      const current = await ensureDraft()
      const next = await request<TreeMergeDraft>(`${base}/${current.id}/messages`, {
        method: 'POST', body: JSON.stringify({ version: current.version, ...pending.current }),
      })
      setDraft(next)
      setContent('')
      const requestId = pending.current.requestId
      pending.current = null
      await process(next, requestId)
    } catch (cause) { showError(cause) }
    finally { setBusy(false) }
  }

  async function process(current: TreeMergeDraft, requestId: string) {
    setBusy(true)
    setThinkingSince(Date.now())
    setDraft({ ...current, progress: [] })
    setError(null)
    try {
      setDraft(await request<TreeMergeDraft>(`${base}/${current.id}/process`, {
        method: 'POST', body: JSON.stringify({ version: current.version, requestId }),
      }))
    } catch (cause) {
      showError(cause)
      try { setDraft(await request<TreeMergeDraft>(`${base}/${current.id}`)) } catch { /* Keep the saved message available. */ }
    } finally { setBusy(false); setThinkingSince(null) }
  }

  async function reload() {
    if (!draft) return
    setBusy(true)
    try { setDraft(await request<TreeMergeDraft>(`${base}/${draft.id}`)); setError(null) }
    catch (cause) { showError(cause) }
    finally { setBusy(false) }
  }

  async function cancel() {
    if (!draft) return
    setBusy(true)
    try {
      const cancelled = await request<TreeMergeDraft>(`${base}/${draft.id}/cancel`, {
        method: 'POST', body: JSON.stringify({ version: draft.version }),
      })
      setBudget(cancelled.tokenBudget ?? draft.tokenBudget ?? budget)
      setDraft(null)
      setContent('')
      setError(null)
      pending.current = null
      const url = new URL(window.location.href)
      url.searchParams.delete('merge')
      window.history.replaceState(window.history.state, '', url)
    } catch (cause) { showError(cause) }
    finally { setBusy(false) }
  }

  const pendingMessage = draft?.messages.findLast((message) => message.role === 'user')
  return (
    <aside aria-label={t('treeMerge.title')} aria-busy={busy} className="tree-merge-panel"
      onKeyDown={(event) => { if (event.key === 'Escape') close() }}>
      <header className="tree-merge-heading">
        <h2>{t('treeMerge.title')}</h2>
        {draft && draft.state !== 'applied' && <button type="button" className="tree-merge-reset"
          disabled={busy || voiceBusy} onClick={() => void cancel()}>{t('treeMerge.cancel')}</button>}
      </header>
      <div className="tree-merge-content">
        {error && <div role="alert">
          <p>{t(error)}</p>
          {draft && <button type="button" disabled={busy} onClick={() => void reload()}>{t('treeMerge.reload')}</button>}
        </div>}
        <div aria-label={t('treeMerge.messages')} className="tree-merge-messages" role="log" aria-live="polite">
          {draft?.messages.map((message) => (
            <article key={message.id} className={`tree-merge-message tree-merge-message-${message.role}`}>
              <strong>{t(message.role === 'user' ? 'treeMerge.you' : 'treeMerge.assistant')}</strong>
              <p>{message.failureCode ? t(`treeMerge.failure.${message.failureCode}`) : message.content}</p>
            </article>
          ))}
          {(thinkingSince !== null || draft?.state === 'processing') && <TreeMergeThinking startedAt={thinkingSince ?? (draft?.attemptStartedAt ? Date.parse(draft.attemptStartedAt) : Date.now())} progress={draft?.progress ?? []} />}
          {draft?.state === 'error' && !draft.messages.at(-1)?.failureCode && <p role="alert">{t('treeMerge.processingFailed')}</p>}
          {draft?.state === 'applied' && <p role="status">{t('treeMerge.applied')}</p>}
          <div ref={messagesEnd} />
        </div>
        {open && draft?.proposal && draft.state !== 'applied' && draft.state !== 'cancelled' && previewContainer !== null && createPortal(<TreeMergePreview key={`${draft.id}:${draft.proposalVersion}`}
          draft={{ ...draft, proposal: draft.proposal }} busy={busy || voiceBusy}
          graphMode={Boolean(onDraftChange)} selectedPersonId={selectedProposalPersonId ?? null} onClosePerson={() => onCloseProposalPerson?.()}
          onSave={(proposal) => proposalAction('proposal', proposal)}
          onApply={() => proposalAction('apply')}
          onRevalidate={() => proposalAction('revalidate')} />, previewContainer ?? document.body)}
        {draft && pendingMessage && (draft.state === 'error' || draft.state === 'collecting' || staleAttempt) &&
          (draft.messages.at(-1)?.role === 'user' || draft.messages.at(-1)?.failureCode) &&
          <button type="button" className="secondary-button" disabled={busy || voiceBusy}
            onClick={() => void process(draft, pendingMessage.requestId)}>{t('treeMerge.process')}</button>}
      </div>
      {draft?.state !== 'applied' ? (
        <form className="tree-merge-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
          <TreeMergeVoiceInput request={request} active={open}
            disabled={busy || draft?.state === 'processing'}
            onBusy={setVoiceBusy}
            onTranscript={(text) => setContent(content ? `${content}\n${text}` : text)}>
            <textarea id="tree-merge-message" aria-label={t('treeMerge.message')} placeholder={t('treeMerge.placeholder')}
              ref={textarea} value={content} maxLength={10000} disabled={busy || voiceBusy}
              onChange={(event) => setContent(event.target.value)} rows={1} />
            <TreeMergeBudget budget={draft?.tokenBudget ?? budget} />
            <button className="tree-merge-send" type="submit" aria-label={t('treeMerge.send')}
              disabled={busy || voiceBusy || !content.trim()}>
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
                <path d="m5 12 7-7 7 7m-7-7v15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
          </TreeMergeVoiceInput>
        </form>
      ) : <button className="secondary-button" type="button" onClick={() => {
        setBudget(draft.tokenBudget ?? budget)
        setDraft(null)
        setContent('')
        const url = new URL(window.location.href)
        url.searchParams.delete('merge')
        window.history.replaceState(window.history.state, '', url)
      }}>{t('treeMerge.newConversation')}</button>}
    </aside>
  )
}
