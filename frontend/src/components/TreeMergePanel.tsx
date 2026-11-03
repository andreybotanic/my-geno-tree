import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import { ApiRequestError } from '../api/client'
import type { TreeMergeDraft, TreeMergeList, TreeSnapshot } from '../api/generated'
import { formatPersonName } from '../personFormatting'

type Props = {
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onClose: () => void
}

export function TreeMergePanel({ snapshot, request, onClose }: Props) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState<TreeMergeDraft | null>(null)
  const [referenceId, setReferenceId] = useState('')
  const [content, setContent] = useState('')
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const textarea = useRef<HTMLTextAreaElement>(null)
  const pending = useRef<{ requestId: string; content: string } | null>(null)
  const base = `/trees/${snapshot.tree.id}/merges`

  useEffect(() => {
    const controller = new AbortController()
    request<TreeMergeList>(base, { signal: controller.signal })
      .then(({ items }) => {
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
    if (draft && !busy) textarea.current?.focus()
  }, [busy, draft])

  function showError(cause: unknown) {
    setError(cause instanceof ApiRequestError && cause.status === 409
      ? 'treeMerge.conflict'
      : cause instanceof ApiRequestError && cause.status === 401
        ? 'treeMerge.unauthorized'
        : 'treeMerge.failed')
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
      pending.current = null
    } catch (cause) {
      showError(cause)
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
    } catch (cause) {
      showError(cause)
    } finally {
      setBusy(false)
    }
  }

  return (
    <aside aria-label={t('treeMerge.title')} aria-busy={busy} className="tree-merge-panel"
      onKeyDown={(event) => { if (event.key === 'Escape') onClose() }}>
      <header className="tree-merge-heading">
        <h2>{t('treeMerge.title')}</h2>
        <button type="button" onClick={onClose} aria-label={t('common.close')}>×</button>
      </header>
      <p>{t('treeMerge.help')}</p>
      {error && (
        <div role="alert">
          <p>{t(error)}</p>
          {draft && <button type="button" disabled={busy} onClick={() => void reload()}>{t('treeMerge.reload')}</button>}
        </div>
      )}
      {busy && <p role="status">{t('common.loading')}</p>}
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
          <div aria-label={t('treeMerge.messages')} className="tree-merge-messages" role="log" aria-live="polite">
            {draft.messages.length === 0 && <p>{t('treeMerge.empty')}</p>}
            {draft.messages.map((message) => (
              <article key={message.id} className={`tree-merge-message tree-merge-message-${message.role}`}>
                <strong>{t(message.role === 'user' ? 'treeMerge.you' : 'treeMerge.assistant')}</strong>
                <p>{message.content}</p>
              </article>
            ))}
          </div>
          <form onSubmit={(event) => { event.preventDefault(); void send() }}>
            <label htmlFor="tree-merge-message">{t('treeMerge.message')}</label>
            <textarea id="tree-merge-message" ref={textarea} value={content} maxLength={10000}
              disabled={busy} onChange={(event) => setContent(event.target.value)} rows={4} />
            <div className="tree-merge-actions">
              <button className="primary-button" type="submit" disabled={busy || !content.trim()}>{t('treeMerge.send')}</button>
              <button type="button" disabled={busy} onClick={() => void cancel()}>{t('treeMerge.cancel')}</button>
            </div>
          </form>
        </>
      )}
    </aside>
  )
}
