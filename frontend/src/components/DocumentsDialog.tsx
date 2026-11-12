import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type AuthorizedBlobRequest, type AuthorizedJsonRequest } from '../api/client'
import type { DocumentPage, TreeDocument, TreeSnapshot, VersionedTreeDocument } from '../api/generated'
import { documentErrorKey, downloadDocument, formatDocumentSize } from '../documents'
import DownloadIcon from '../assets/icons/export.svg?react'
import GridIcon from '../assets/icons/view-grid.svg?react'
import ListIcon from '../assets/icons/view-list.svg?react'
import { DocumentModal } from './DocumentModal'
import { DocumentForm } from './DocumentForm'
import { AddButton } from './AddButton'
import { ActionIcon } from './ActionIcon'

type Props = {
  snapshot: TreeSnapshot
  personId?: string
  request: AuthorizedJsonRequest
  requestBlob: AuthorizedBlobRequest
  onClose: () => void
  onChanged: () => Promise<void>
}

export function DocumentsDialog({ snapshot, personId, request, requestBlob, onChanged, onClose }: Props) {
  const { t, i18n } = useTranslation()
  const [editor, setEditor] = useState<'upload' | TreeDocument | null>(null)
  const [editorVersion, setEditorVersion] = useState(snapshot.etag)
  const opener = useRef<HTMLButtonElement | null>(null)
  const filterButton = useRef<HTMLButtonElement | null>(null)
  const base = `/trees/${snapshot.tree.id}/documents`
  const selected = editor && editor !== 'upload' ? editor : null
  const [filter, setFilter] = useState<'all' | 'person'>('all')
  const [view, setView] = useState<'tiles' | 'list'>('tiles')
  const [items, setItems] = useState<TreeDocument[]>([])
  const [cursor, setCursor] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [loadError, setLoadError] = useState('')
  const [pendingDelete, setPendingDelete] = useState<TreeDocument | null>(null)
  const etag = useRef(snapshot.etag)
  const sequence = useRef(0)
  const nextCursor = useRef<string | null>(null)
  const pageCount = useRef(1)

  const load = useCallback(async (append = false, reportFailure = false) => {
    const version = ++sequence.current
    const query = new URLSearchParams({ newestFirst: 'true' })
    if (filter === 'person' && personId) {
      query.set('scope', 'person')
    }
    if (personId) query.set('personId', personId)
    const firstPageUrl = base + '?' + query.toString()
    if (append && nextCursor.current) query.set('cursor', nextCursor.current)
    try {
      let page = await request<DocumentPage>(base + '?' + query.toString())
      if (!Array.isArray(page.items)) throw new Error('Invalid document page')
      const listVersion = append ? etag.current : page.etag
      let loaded = [...page.items]
      let loadedPages = append ? pageCount.current + 1 : 1
      if (!append) {
        for (let index = 1; index < pageCount.current && page.nextCursor; ++index) {
          query.set('cursor', page.nextCursor)
          page = await request<DocumentPage>(base + '?' + query.toString())
          if (version !== sequence.current || page.etag !== listVersion) break
          loaded.push(...page.items)
          ++loadedPages
        }
      }
      if (version !== sequence.current) return
      const restart = page.etag !== listVersion
      if (restart) {
        // Restart at a single coherent page if the tree changed between page requests.
        page = await request<DocumentPage>(firstPageUrl)
        if (version !== sequence.current) return
        loaded = page.items
        loadedPages = 1
      }
      etag.current = page.etag
      pageCount.current = loadedPages
      setItems((current) => append && !restart ? [...current, ...loaded] : loaded)
      nextCursor.current = page.nextCursor
      setCursor(page.nextCursor)
      setLoadError('')
      return { items: loaded, etag: page.etag }
    } catch (caught) {
      if (version === sequence.current) setLoadError(documentErrorKey(caught))
      if (reportFailure) throw caught
    } finally {
      if (version === sequence.current) setLoading(false)
    }
  }, [base, filter, personId, request])

  useEffect(() => {
    const requests = sequence
    const version = requests.current
    pageCount.current = 1
    setLoading(true)
    // Let cleanup invalidate a discarded effect before it sends a request.
    queueMicrotask(() => {
      if (requests.current === version) void load()
    })
    return () => { ++requests.current }
  }, [load])

  const reloadAfterConflict = async () => {
    const page = await load(false, true)
    if (page) await onChanged()
    return page
  }

  const updateList = (document: TreeDocument) => {
    setItems((current) => {
      if (personId && document.personId !== personId && (filter === 'person' || document.personId !== null)) {
        return current.filter((item) => item.id !== document.id)
      }
      return current.some((item) => item.id === document.id)
        ? current.map((item) => item.id === document.id ? document : item)
        : [document, ...current]
    })
  }

  const openEditor = (target: 'upload' | TreeDocument, button: HTMLButtonElement) => {
    opener.current = button
    setEditorVersion(etag.current)
    setEditor(target)
  }

  const closeEditor = () => {
    setEditor(null)
    requestAnimationFrame(() => (opener.current?.isConnected ? opener.current : filterButton.current)?.focus())
  }

  const acceptDocument = (document: TreeDocument, version: string) => {
    ++sequence.current
    etag.current = version
    updateList(document)
    setLoadError('')
    if (editor === 'upload') closeEditor()
    else setEditor(document)
    void onChanged().catch(() => setError('documents.errors.failed'))
  }

  const remove = async (item: TreeDocument) => {
    setBusy(true)
    setError('')
    try {
      const result = await request<{ etag: string }>(base + '/' + item.id, {
        method: 'DELETE', headers: { 'If-Match': etag.current },
      })
      etag.current = result.etag
      setPendingDelete(null)
      ++sequence.current
      setItems((current) => current.filter((entry) => entry.id !== item.id))
      await onChanged()
    } catch (caught) {
      setPendingDelete(null)
      if (caught instanceof ApiRequestError && caught.status === 412) {
        try {
          await reloadAfterConflict()
          setError('documents.deleteConflict')
        } catch (loadFailure) { setError(documentErrorKey(loadFailure)) }
      } else {
        setError(documentErrorKey(caught))
      }
    } finally { setBusy(false) }
  }

  return <>
    <DocumentModal title={t('documents.title')} active={editor === null} className="documents-dialog" onClose={onClose}>
      <section className="documents-panel" aria-label={t('documents.title')}>
        {(error || loadError) && <p role="alert" className="documents-error">{t(error || loadError)}</p>}
        <div className="documents-toolbar">
          <AddButton disabled={busy || loading} onClick={(event) => openEditor('upload', event.currentTarget)} />
          <button ref={filterButton} type="button" role="switch" className="document-switch" aria-label={t('documents.filter')}
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
              <strong className="document-title"><span className="document-name" title={item.title ?? item.originalName}>{item.title ?? item.originalName}</span>
                <span className="document-size">({formatDocumentSize(item.originalSizeBytes, i18n.resolvedLanguage ?? 'en')})</span>
              </strong>
              {item.status !== 'ready' && <div className="document-metadata">
                <span role="status" className={`document-status document-status-${item.status}`}
                  aria-label={t(`documents.status.${item.status}`)} title={[
                    t(`documents.status.${item.status}`),
                    item.errorCode ? t(item.errorCode === 'DOCUMENT_QUEUE_UNAVAILABLE' ? 'documents.errors.queue' : 'documents.errors.index') : '',
                  ].filter(Boolean).join(' · ')}>{t('documents.notIndexed')}</span>
              </div>}
              </div>
              <div className="document-actions">
                <button type="button" className="secondary-button compact-button" aria-label={t('documents.download')}
                  onClick={() => { void downloadDocument(requestBlob, item.downloadUrl, item.originalName).catch((caught: unknown) => setError(documentErrorKey(caught))) }}>
                  <DownloadIcon className="document-download-icon" aria-hidden="true" />
                  <span className="document-action-label">{t('documents.download')}</span>
                </button>
                <button type="button" className="secondary-button compact-button" aria-label={t('documents.edit')}
                  title={t('documents.edit')} disabled={busy}
                  onClick={(event) => openEditor(item, event.currentTarget)}>
                  <ActionIcon name="edit" />
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
          <p>{t('documents.confirmDelete', { name: pendingDelete.title ?? pendingDelete.originalName })}</p>
          <button type="button" className="danger-button" disabled={busy} onClick={() => { void remove(pendingDelete) }}>{t('documents.delete')}</button>
          <button type="button" className="secondary-button" disabled={busy} onClick={() => setPendingDelete(null)}>{t('common.cancel')}</button>
        </div>}
      </section>
    </DocumentModal>
    {editor && <DocumentForm key={selected?.id ?? 'upload'} document={selected} snapshot={snapshot}
      personId={personId} request={request} requestBlob={requestBlob} version={editorVersion}
      onSaved={acceptDocument} onClose={closeEditor} onConflict={async () => {
        const page = await reloadAfterConflict()
        if (!page) return
        let document = page.items.find((item) => item.id === selected?.id)
        if (selected && !document) {
          // A change of attachment can move the open document outside the current filter.
          const fresh = await request<VersionedTreeDocument>(base + '/' + selected.id)
          if (fresh.etag !== page.etag) return
          document = fresh.document
        }
        if (document) setEditor(document)
        return { etag: page.etag, document }
      }} />}
  </>
}
