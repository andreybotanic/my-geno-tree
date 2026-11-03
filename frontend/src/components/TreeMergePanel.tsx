import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import { ApiRequestError } from '../api/client'
import type { TreeMergeDraft, TreeMergeList, TreeMergeProposal, TreeMergeResult, TreeSnapshot } from '../api/generated'
import { formatPersonName } from '../personFormatting'
import { TreeMergePreview } from './TreeMergePreview'

type Props = {
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onClose: () => void
  onApplied?: (result: TreeMergeResult) => Promise<void>
}

export function TreeMergePanel({ snapshot, request, onClose, onApplied }: Props) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState<TreeMergeDraft | null>(null)
  const [referenceId, setReferenceId] = useState('')
  const [content, setContent] = useState('')
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [observedAt, setObservedAt] = useState(Date.now)
  const textarea = useRef<HTMLTextAreaElement>(null)
  const messagesEnd = useRef<HTMLDivElement>(null)
  const pending = useRef<{ requestId: string; content: string } | null>(null)
  const base = `/trees/${snapshot.tree.id}/merges`
  const staleAttempt = draft?.state === 'processing' && draft.attemptStartedAt !== null &&
    observedAt - Date.parse(draft.attemptStartedAt) >= 5 * 60 * 1000

  useEffect(() => {
    const controller = new AbortController()
    const selectedId = new URLSearchParams(window.location.search).get('merge')
    const loading = selectedId
      ? request<TreeMergeDraft>(`${base}/${encodeURIComponent(selectedId)}`, { signal: controller.signal }).then((item) => ({ items: [item] }))
      : request<TreeMergeList>(base, { signal: controller.signal })
    loading.then(({ items }) => {
        if (controller.signal.aborted) return
        setDraft(items.find((item) => item.state !== 'cancelled') ?? null)
        setBusy(false)
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setError('treeMerge.loadFailed')
          setBusy(false)
        }
      })
    return () => controller.abort()
  }, [base, request])

  useEffect(() => {
    if (!draft) return
    const url = new URL(window.location.href)
    url.searchParams.set('merge', draft.id)
    window.history.replaceState(window.history.state, '', url)
  }, [draft])

  useEffect(() => { messagesEnd.current?.scrollIntoView?.({ block: 'end' }) }, [draft?.messages.length])

  useEffect(() => {
    if (draft?.state !== 'processing') return
    const controller = new AbortController()
    const timer = window.setInterval(() => {
      void request<TreeMergeDraft>(`${base}/${draft.id}`, { signal: controller.signal }).then((next) => {
        if (!controller.signal.aborted) { setDraft(next); setObservedAt(Date.now()) }
      }).catch(() => { /* The saved conversation remains available for retry. */ })
    }, 2000)
    return () => { controller.abort(); window.clearInterval(timer) }
  }, [base, draft?.id, draft?.state, request])

  useEffect(() => {
    if (draft?.id && !busy) textarea.current?.focus()
  }, [busy, draft?.id])

  function showError(cause: unknown) {
    setError(cause instanceof ApiRequestError && cause.status === 412 ? 'treeMerge.treeConflict'
      : cause instanceof ApiRequestError && cause.status === 409 ? 'treeMerge.conflict'
      : cause instanceof ApiRequestError && cause.status === 401
        ? 'treeMerge.unauthorized'
        : cause instanceof ApiRequestError && cause.status === 422 ? 'treeMerge.invalidProposal'
        : 'treeMerge.failed')
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
      setDraft(next)
      if (action === 'apply' && next.result) await onApplied?.(next.result)
    } catch (cause) {
      showError(cause)
      if (cause instanceof ApiRequestError && cause.status === 412) {
        try { setDraft(await request<TreeMergeDraft>(`${base}/${draft.id}`)) } catch { /* Keep the reviewed input. */ }
      }
    } finally {
      setBusy(false)
    }
  }

  async function start() {
    setBusy(true)
    setError(null)
    try {
      setDraft(await request<TreeMergeDraft>(base, {
        method: 'POST',
        body: JSON.stringify({ referencePersonId: referenceId || null }),
      }))
    } catch (cause) {
      showError(cause)
    } finally {
      setBusy(false)
    }
  }

  async function send() {
    if (!draft || !content.trim()) return
    setBusy(true)
    setError(null)
    if (pending.current?.content !== content) {
      pending.current = { requestId: crypto.randomUUID(), content }
    }
    try {
      const next = await request<TreeMergeDraft>(`${base}/${draft.id}/messages`, {
        method: 'POST',
        body: JSON.stringify({ version: draft.version, ...pending.current }),
      })
      setDraft(next)
      setContent('')
      const requestId = pending.current.requestId
      pending.current = null
      await process(next, requestId)
    } catch (cause) {
      showError(cause)
    } finally {
      setBusy(false)
    }
  }

  async function process(current: TreeMergeDraft, requestId: string) {
    setBusy(true)
    setError(null)
    try {
      const next = await request<TreeMergeDraft>(`${base}/${current.id}/process`, {
        method: 'POST', body: JSON.stringify({ version: current.version, requestId }),
      })
      setDraft(next)
    } catch (cause) {
      showError(cause)
      try { setDraft(await request<TreeMergeDraft>(`${base}/${current.id}`)) } catch { /* Keep the saved message available. */ }
    } finally {
      setBusy(false)
    }
  }

  async function reload() {
    if (!draft) return
    setBusy(true)
    try {
      setDraft(await request<TreeMergeDraft>(`${base}/${draft.id}`))
      setError(null)
    } catch (cause) {
      showError(cause)
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    if (!draft) return
    setBusy(true)
    try {
      await request<TreeMergeDraft>(`${base}/${draft.id}/cancel`, {
        method: 'POST', body: JSON.stringify({ version: draft.version }),
      })
      setDraft(null)
      setContent('')
      setError(null)
      pending.current = null
      const url = new URL(window.location.href)
      url.searchParams.delete('merge')
      window.history.replaceState(window.history.state, '', url)
    } catch (cause) {
      showError(cause)
    } finally {
      setBusy(false)
    }
  }

  return (
    <aside aria-label={t('treeMerge.title')} aria-busy={busy} className="tree-merge-panel"
      onKeyDown={(event) => { if (event.key === 'Escape') close() }}>
      <header className="tree-merge-heading">
        <h2>{t('treeMerge.title')}</h2>
        <button type="button" className="secondary-button compact-button" onClick={close} aria-label={t('common.close')}>×</button>
      </header>
      <p>{t('treeMerge.help')}</p>
      {error && (
        <div role="alert">
          <p>{t(error)}</p>
          {draft && <button type="button" disabled={busy} onClick={() => void reload()}>{t('treeMerge.reload')}</button>}
        </div>
      )}
      {busy && <p role="status">{t('common.loading')}</p>}
      {draft?.state === 'processing' && <p role="status">{t('treeMerge.processing')}</p>}
      {draft?.state === 'error' && <p role="alert">{t('treeMerge.processingFailed')}</p>}
      {!draft ? (
        <form onSubmit={(event) => { event.preventDefault(); void start() }}>
          {snapshot.people.length > 0 && (
            <label>
              {t('treeMerge.reference')}
              <select value={referenceId} onChange={(event) => setReferenceId(event.target.value)} required>
                <option value="">{t('treeMerge.chooseReference')}</option>
                {snapshot.people.map((person) => <option key={person.id} value={person.id}>{formatPersonName(person, t)}</option>)}
              </select>
              <small>{t('treeMerge.referenceHelp')}</small>
            </label>
          )}
          <button className="primary-button" disabled={busy || (snapshot.people.length > 0 && !referenceId)} type="submit">
            {t('treeMerge.start')}
          </button>
        </form>
      ) : (
        <>
          {draft.referencePersonId && <p className="tree-merge-reference">{t('treeMerge.reference')}: {
            snapshot.people.find((person) => person.id === draft.referencePersonId)
              ? formatPersonName(snapshot.people.find((person) => person.id === draft.referencePersonId)!, t)
              : t('person.unknown')
          }</p>}
          <div aria-label={t('treeMerge.messages')} className="tree-merge-messages" role="log" aria-live="polite">
            {draft.messages.length === 0 && <p>{t('treeMerge.empty')}</p>}
            {draft.messages.map((message) => (
              <article key={message.id} className={`tree-merge-message tree-merge-message-${message.role}`}>
                <strong>{t(message.role === 'user' ? 'treeMerge.you' : 'treeMerge.assistant')}</strong>
                <p>{message.content}</p>
              </article>
            ))}
            <div ref={messagesEnd} />
          </div>
          {draft.proposal && <TreeMergePreview key={`${draft.id}:${draft.version}`}
            draft={{ ...draft, proposal: draft.proposal }} busy={busy}
            onSave={(proposal) => proposalAction('proposal', proposal)}
            onApply={() => proposalAction('apply')}
            onRevalidate={() => proposalAction('revalidate')} />}
          {(draft.state === 'error' || draft.state === 'collecting' || staleAttempt) && draft.messages.at(-1)?.role === 'user' &&
            <button type="button" className="secondary-button" disabled={busy}
              onClick={() => void process(draft, draft.messages.at(-1)!.requestId)}>{t('treeMerge.process')}</button>}
          {draft.state !== 'applied' && (
          <form onSubmit={(event) => { event.preventDefault(); void send() }}>
            <label htmlFor="tree-merge-message">{t('treeMerge.message')}</label>
            <textarea id="tree-merge-message" ref={textarea} value={content} maxLength={10000}
              disabled={busy} onChange={(event) => setContent(event.target.value)} rows={4} />
            <div className="tree-merge-actions">
              <button className="primary-button" type="submit" disabled={busy || !content.trim()}>{t('treeMerge.send')}</button>
              <button type="button" className="secondary-button" disabled={busy} onClick={() => void cancel()}>{t('treeMerge.cancel')}</button>
            </div>
          </form>
          )}
          {draft.state === 'applied' && <button className="secondary-button" type="button" onClick={() => {
            setDraft(null)
            const url = new URL(window.location.href)
            url.searchParams.delete('merge')
            window.history.replaceState(window.history.state, '', url)
          }}>{t('treeMerge.newConversation')}</button>}
        </>
      )}
    </aside>
  )
}
