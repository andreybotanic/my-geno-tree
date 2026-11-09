import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type AuthorizedBlobRequest, type AuthorizedJsonRequest } from '../api/client'
import type { DocumentPage, TreeDocument, TreeSnapshot } from '../api/generated'
import { downloadDocument, formatDocumentSize } from '../documents'
import DownloadIcon from '../assets/icons/export.svg?react'
import GridIcon from '../assets/icons/view-grid.svg?react'
import ListIcon from '../assets/icons/view-list.svg?react'
import { ActionIcon } from './ActionIcon'

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
  }
  return 'documents.errors.failed'
}

export function DocumentsPanel({ snapshot, personId, request, requestBlob, onChanged }: Props) {
  const { t, i18n } = useTranslation()
  const inputId = useId()
  const base = `/trees/${snapshot.tree.id}/documents`
  const [filter, setFilter] = useState<'all' | 'person'>('all')
  const [view, setView] = useState<'tiles' | 'list'>('tiles')
  const [personalUpload, setPersonalUpload] = useState(Boolean(personId))
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
        if (personalUpload && personId) form.set('personId', personId)
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

  return <section className="documents-panel" aria-label={t('documents.title')}>
    <div className="documents-upload-box">
      <p className="documents-hint">{t('documents.hint')}</p>
      <div className="documents-controls">
        <button type="button" role="switch" className="document-switch document-upload-target"
          aria-label={t('documents.attachTo')} aria-checked={personalUpload} disabled={busy || !personId}
          onClick={() => setPersonalUpload((current) => !current)}>
          <span className="document-switch-option" aria-hidden="true">{t('documents.shared')}</span>
          <span className="document-switch-option" aria-hidden="true">{t('documents.personalFile')}</span>
        </button>
        <label className="form-field documents-file-label" htmlFor={inputId}>
          <span className="file-picker documents-file-picker">
            <span className="file-picker-button">
              <span className="document-upload-label">{t('documents.upload')}</span>
              <span className="document-upload-short-label">{t('documents.files')}</span>
            </span>
            <span className="file-picker-name is-empty">.txt, .md</span>
            <input className="file-picker-input" aria-label={t('documents.upload')} id={inputId} type="file"
              accept=".txt,.md,text/plain,text/markdown" multiple disabled={busy}
              onChange={(event) => {
                const files = Array.from(event.target.files ?? [])
                event.target.value = ''
                if (files.length) void upload(files)
              }} />
          </span>
        </label>
      </div>
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
    <div className="documents-toolbar">
      <button type="button" role="switch" className="document-switch" aria-label={t('documents.filter')}
        aria-checked={filter === 'person'} disabled={busy || !personId}
        onClick={() => setFilter((current) => current === 'all' ? 'person' : 'all')}>
        <span className="document-switch-option" aria-hidden="true">{t('documents.all')}</span>
        <span className="document-switch-option" aria-hidden="true">{t('documents.personal')}</span>
      </button>
      <button type="button" role="switch" className="document-switch document-view-switch" aria-label={t('documents.view')}
        aria-checked={view === 'list'} onClick={() => setView((current) => current === 'tiles' ? 'list' : 'tiles')}>
        <span className="document-switch-option" title={t('documents.tiles')} aria-hidden="true"><GridIcon /></span>
        <span className="document-switch-option" title={t('documents.list')} aria-hidden="true"><ListIcon /></span>
      </button>
    </div>
    <div className={`documents-content${loading ? ' is-loading' : ''}`} aria-busy={loading}>
    {loading && <p role="status" className="document-list-loading">{t('documents.loading')}</p>}
    {!loading && !error && !loadError && items.length === 0 && <p className="documents-empty">{t('documents.empty')}</p>}
    <ul className={`document-list document-list-${view}`} aria-label={t('documents.title')}>
      {items.map((item) => <li key={item.id}>
          <div className="document-file-info">
          <strong className="document-title"><span className="document-name" title={item.originalName}>{item.originalName}</span>
            <span className="document-size">({formatDocumentSize(item.originalSizeBytes, i18n.resolvedLanguage ?? 'en')})</span>
          </strong>
          {(item.personId || item.status !== 'ready') && <div className="document-metadata">
            {item.personId && <span className="document-personal-badge">{t('documents.personalFile')}</span>}
            {item.status !== 'ready' && <span role="status" className={`document-status document-status-${item.status}`}
              aria-label={t(`documents.status.${item.status}`)} title={[
                t(`documents.status.${item.status}`),
                item.errorCode ? t(item.errorCode === 'DOCUMENT_QUEUE_UNAVAILABLE' ? 'documents.errors.queue' : 'documents.errors.index') : '',
              ].filter(Boolean).join(' · ')}>{t('documents.notIndexed')}</span>}
          </div>}
          </div>
          <div className="document-actions">
            <button type="button" className="secondary-button compact-button" aria-label={t('documents.download')}
              onClick={() => { void downloadDocument(requestBlob, item.downloadUrl, item.originalName).catch((caught: unknown) => setError(errorKey(caught))) }}>
              <DownloadIcon className="document-download-icon" aria-hidden="true" />
              <span className="document-action-label">{t('documents.download')}</span>
            </button>
            <button type="button" className="danger-link document-delete-button" aria-label={t('documents.delete')}
              title={t('documents.delete')} disabled={busy} onClick={() => setPendingDelete(item)}>
              <ActionIcon name="delete" />
            </button>
          </div>
        </li>
      )}
    </ul>
    {cursor && <button type="button" className="secondary-button compact-button" disabled={busy || loading}
      onClick={() => { void load(true) }}>{t('documents.more')}</button>}
    </div>
    {pendingDelete && <div role="alertdialog" aria-label={t('documents.confirmTitle')} className="document-delete-confirmation">
      <p>{t('documents.confirmDelete', { name: pendingDelete.originalName })}</p>
      <button type="button" className="danger-button" disabled={busy} onClick={() => { void remove(pendingDelete) }}>{t('documents.delete')}</button>
      <button type="button" className="secondary-button" disabled={busy} onClick={() => setPendingDelete(null)}>{t('common.cancel')}</button>
    </div>}
  </section>
}
