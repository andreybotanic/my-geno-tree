import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type AuthorizedBlobRequest, type AuthorizedJsonRequest } from '../api/client'
import type { DocumentPage, TreeDocument, TreeSnapshot } from '../api/generated'
import { downloadDocument, formatDocumentSize } from '../documents'
import { formatPersonName } from '../personFormatting'
import DownloadIcon from '../assets/icons/export.svg?react'

type Props = {
  snapshot: TreeSnapshot
  personId?: string
  request: AuthorizedJsonRequest
  requestBlob: AuthorizedBlobRequest
  onChanged: () => Promise<void>
}

function errorKey(error: unknown) {
  if (error instanceof ApiRequestError) {
    if (error.status === 412) return 'documents.errors.conflict'
    if (error.status === 401 || error.status === 404) return 'documents.errors.access'
    if (error.body.code === 'DOCUMENT_TOO_LARGE') return 'documents.errors.size'
    if (['DOCUMENT_CONTENT_INVALID', 'DOCUMENT_UPLOAD_INVALID', 'DOCUMENT_NAME_INVALID'].includes(error.body.code)) {
      return 'documents.errors.invalid'
    }
    if (error.body.code === 'DOCUMENT_NOT_RETRYABLE') return 'documents.errors.notRetryable'
  }
  return 'documents.errors.failed'
}

export function DocumentsPanel({ snapshot, personId, request, requestBlob, onChanged }: Props) {
  const { t, i18n } = useTranslation()
  const inputId = useId()
  const base = `/trees/${snapshot.tree.id}/documents`
  const [filter, setFilter] = useState<'all' | 'person'>('all')
  const [target, setTarget] = useState(personId ?? '')
  const [items, setItems] = useState<TreeDocument[]>([])
  const [cursor, setCursor] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [loadError, setLoadError] = useState('')
  const [pendingDelete, setPendingDelete] = useState<TreeDocument | null>(null)
  const [results, setResults] = useState<{ name: string; error: string | null }[]>([])
  const etag = useRef(snapshot.etag)
  const working = useRef(false)
  const sequence = useRef(0)
  const nextCursor = useRef<string | null>(null)
  const pageCount = useRef(1)
  useEffect(() => { etag.current = snapshot.etag }, [snapshot.etag])

  const load = useCallback(async (append = false) => {
    const version = ++sequence.current
    const query = new URLSearchParams()
    if (filter === 'person' && personId) {
      query.set('scope', 'person')
      query.set('personId', personId)
    }
    if (append && nextCursor.current) query.set('cursor', nextCursor.current)
    try {
      let page = await request<DocumentPage>(base + '?' + query.toString())
      if (!Array.isArray(page.items)) throw new Error('Invalid document page')
      const loaded = [...page.items]
      if (!append) {
        for (let index = 1; index < pageCount.current && page.nextCursor; ++index) {
          query.set('cursor', page.nextCursor)
          page = await request<DocumentPage>(base + '?' + query.toString())
          loaded.push(...page.items)
        }
      }
      if (version !== sequence.current) return
      if (append) ++pageCount.current
      setItems((current) => append ? [...current, ...loaded] : loaded)
      nextCursor.current = page.nextCursor
      setCursor(page.nextCursor)
      setLoadError('')
    } catch (caught) {
      if (version === sequence.current) setLoadError(errorKey(caught))
    } finally {
      if (version === sequence.current) setLoading(false)
    }
  }, [base, filter, personId, request])

  useEffect(() => { pageCount.current = 1 }, [filter, personId])

  useEffect(() => {
    const requests = sequence;
    setLoading(true)
    void load()
    const timer = window.setInterval(() => {
      if (!working.current) void load()
    }, 3000)
    return () => { ++requests.current; window.clearInterval(timer) }
  }, [load, snapshot.etag])

  const upload = async (files: File[]) => {
    working.current = true
    setBusy(true)
    setResults([])
    setError('')
    let changed = false
    for (const file of files) {
      try {
        const form = new FormData()
        form.set('file', file)
        if (target) form.set('personId', target)
        const result = await request<{ document: TreeDocument; etag: string }>(base, {
          method: 'POST', body: form, headers: { 'If-Match': etag.current },
        })
        etag.current = result.etag
        changed = true
        setResults((current) => [...current, { name: file.name, error: result.document.status === 'failed' ? 'documents.errors.queue' : null }])
      } catch (caught) {
        setResults((current) => [...current, { name: file.name, error: errorKey(caught) }])
        if (caught instanceof ApiRequestError && [401, 404, 412].includes(caught.status)) {
          setError(errorKey(caught))
          break
        }
      }
    }
    try {
      if (changed) await onChanged()
      await load()
    } catch { setError('documents.errors.failed') }
    working.current = false
    setBusy(false)
  }

  const remove = async (item: TreeDocument) => {
    working.current = true
    setBusy(true)
    try {
      const result = await request<{ etag: string }>(base + '/' + item.id, {
        method: 'DELETE', headers: { 'If-Match': etag.current },
      })
      etag.current = result.etag
      setPendingDelete(null)
      await onChanged()
      await load()
    } catch (caught) { setPendingDelete(null); setError(errorKey(caught)) }
    finally { working.current = false; setBusy(false) }
  }

  const retry = async (item: TreeDocument) => {
    working.current = true
    setBusy(true)
    try {
      await request(base + '/' + item.id + '/retry', { method: 'POST' })
      await load()
    } catch (caught) { setError(errorKey(caught)) }
    finally { working.current = false; setBusy(false) }
  }

  return <section className="documents-panel" aria-label={t('documents.title')}>
    <div className="documents-upload-box">
    <p className="documents-hint">{t('documents.hint')}</p>
    <div className="documents-controls">
    <label className="form-field">{t('documents.attachTo')}
      <select value={target} onChange={(event) => setTarget(event.target.value)} disabled={busy}>
        <option value="">{t('documents.shared')}</option>
        {snapshot.people.filter((person) => !personId || person.id === personId).map((person) =>
          <option key={person.id} value={person.id}>{formatPersonName(person, t)}</option>)}
      </select>
    </label>
    </div>
    <label className="form-field documents-file-label" htmlFor={inputId}>
      <span className="file-picker documents-file-picker">
        <span className="file-picker-button">{t('documents.upload')}</span>
        <span className="file-picker-name is-empty">.txt, .md</span>
        <input className="file-picker-input" aria-label={t('documents.upload')} id={inputId} type="file" accept=".txt,.md,text/plain,text/markdown" multiple disabled={busy}
      onChange={(event) => {
        const files = Array.from(event.target.files ?? [])
        event.target.value = ''
        if (files.length) void upload(files)
      }} />
      </span>
    </label>
    {busy && <p role="status">{t('documents.saving')}</p>}
    {results.length > 0 && <ul className="document-upload-results" aria-label={t('documents.results')}>
      {results.map((result, index) => <li key={index}>{result.name}: {t(result.error ?? 'documents.saved')}</li>)}
    </ul>}
    </div>
    {(error || loadError) && <div role="alert" className="documents-error"><p>{t(error || loadError)}</p>
      <button type="button" className="secondary-button compact-button" disabled={busy}
        onClick={() => { setError(''); void onChanged().then(() => load()).catch(() => setError('documents.errors.failed')) }}>
        {t('documents.reload')}
      </button>
    </div>}
    {loading && <p role="status">{t('documents.loading')}</p>}
    <fieldset className="documents-scope" disabled={busy}>
      <legend className="sr-only">{t('documents.filter')}</legend>
      <label><input type="radio" name={`${inputId}-scope`} checked={filter === 'all'}
        onChange={() => setFilter('all')} />{t('documents.all')}</label>
      <label><input type="radio" name={`${inputId}-scope`} checked={filter === 'person'} disabled={!personId}
        onChange={() => setFilter('person')} />{t('documents.personal')}</label>
    </fieldset>
    {!loading && !error && !loadError && items.length === 0 && <p className="documents-empty">{t('documents.empty')}</p>}
    <ul className="document-list" aria-label={t('documents.title')}>
      {items.map((item) => {
        const person = snapshot.people.find((candidate) => candidate.id === item.personId)
        const stale = ['queued', 'processing'].includes(item.status) && Date.now() - Date.parse(item.updatedAt) > 300_000
        return <li key={item.id}>
          <div className="document-row-heading"><strong>{item.originalName}</strong>
            <span role="status" className={`document-status document-status-${item.status}`}>{t(`documents.status.${item.status}`)}</span>
          </div>
          <div className="document-metadata">
            <span>{formatDocumentSize(item.originalSizeBytes, i18n.resolvedLanguage ?? 'en')}</span>
            <span>{person ? formatPersonName(person, t) : t('documents.shared')}</span>
          </div>
          {item.errorCode && <p className="document-error">{t(item.errorCode === 'DOCUMENT_QUEUE_UNAVAILABLE' ? 'documents.errors.queue' : 'documents.errors.index')}</p>}
          <div className="document-actions">
            <button type="button" className="secondary-button compact-button"
              onClick={() => { void downloadDocument(requestBlob, item.downloadUrl, item.originalName).catch((caught: unknown) => setError(errorKey(caught))) }}>
              <DownloadIcon className="document-download-icon" aria-hidden="true" />
              {t('documents.download')}
            </button>
            {(item.status === 'failed' || stale) && <button type="button" className="secondary-button compact-button" disabled={busy}
              onClick={() => { void retry(item) }}>{t('documents.retry')}</button>}
            <button type="button" className="danger-link" disabled={busy} onClick={() => setPendingDelete(item)}>
              {t('documents.delete')}
            </button>
          </div>
        </li>
      })}
    </ul>
    {cursor && <button type="button" className="secondary-button compact-button" disabled={busy}
      onClick={() => { void load(true) }}>{t('documents.more')}</button>}
    {pendingDelete && <div role="alertdialog" aria-label={t('documents.confirmTitle')} className="document-delete-confirmation">
      <p>{t('documents.confirmDelete', { name: pendingDelete.originalName })}</p>
      <button type="button" className="danger-button" disabled={busy} onClick={() => { void remove(pendingDelete) }}>{t('documents.delete')}</button>
      <button type="button" className="secondary-button" disabled={busy} onClick={() => setPendingDelete(null)}>{t('common.cancel')}</button>
    </div>}
  </section>
}
