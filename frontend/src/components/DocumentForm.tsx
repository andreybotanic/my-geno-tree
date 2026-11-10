import { memo, useEffect, useId, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import type { AuthorizedBlobRequest, AuthorizedJsonRequest } from '../api/client'
import type { TreeDocument, TreeSnapshot, UpdateTreeDocumentData, VersionedTreeDocument } from '../api/generated'
import DownloadIcon from '../assets/icons/export.svg?react'
import SaveIcon from '../assets/icons/save.svg?react'
import { documentErrorKey, downloadDocument, formatDocumentSize } from '../documents'
import { formatPersonName } from '../personFormatting'
import { DocumentModal } from './DocumentModal'

type Props = {
  document: TreeDocument | null
  snapshot: TreeSnapshot
  personId: string | undefined
  version: string
  loadError: string
  request: AuthorizedJsonRequest
  requestBlob: AuthorizedBlobRequest
  onClose: () => void
  onRefresh: () => Promise<{ etag: string; document: TreeDocument | undefined }>
  onSaved: (item: TreeDocument, version: string) => void
}

const DocumentContents = memo(function DocumentContents({ url, markdown, requestBlob }: {
  url: string; markdown: boolean; requestBlob: AuthorizedBlobRequest
}) {
  const { t } = useTranslation()
  const [text, setText] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [attempt, setAttempt] = useState(0)
  const pending = useRef<{ url: string; attempt: number; text: Promise<string> } | null>(null)
  useEffect(() => {
    let active = true
    if (!pending.current || pending.current.url !== url || pending.current.attempt !== attempt) {
      pending.current = { url, attempt, text: requestBlob(url).then((blob) => blob.text()) }
    }
    void pending.current.text.then((value) => {
      if (active) setText(value)
    }).catch((caught: unknown) => { if (active) setError(documentErrorKey(caught)) })
    return () => { active = false }
  }, [url, requestBlob, attempt])
  return <section className="document-preview" aria-label={t('documents.contents')}>
    <h3>{t('documents.contents')}</h3>
    {error ? <div role="alert">{t(error)} <button type="button" className="secondary-button compact-button"
      onClick={() => { setError(''); setAttempt(attempt + 1) }}>{t('documents.reload')}</button></div>
      : text === null ? <p role="status">{t('common.loading')}</p>
      : <div className="document-preview-text" tabIndex={0}>
        {markdown ? <Markdown remarkPlugins={[remarkGfm]} skipHtml components={{ img: ({ alt }) => alt ?? null }}>
          {text}
        </Markdown> : <pre>{text}</pre>}
      </div>}
  </section>
})

function formValues(document: TreeDocument | null, personId?: string) {
  return {
    title: document?.title ?? '',
    description: document?.description ?? '',
    personId: document ? document.personId : personId ?? null,
  }
}

export function DocumentForm({ document: item, snapshot, personId, version, loadError, request, requestBlob,
  onClose, onRefresh, onSaved }: Props) {
  const { t, i18n } = useTranslation()
  const fileInputId = useId()
  const attachmentNameId = useId()
  const [file, setFile] = useState<File | null>(null)
  const [baseline, setBaseline] = useState(() => formValues(item, personId))
  const [title, setTitle] = useState(baseline.title)
  const [description, setDescription] = useState(baseline.description)
  const [attachedPersonId, setAttachedPersonId] = useState(baseline.personId)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')
  const etag = useRef(version)
  const [conflict, setConflict] = useState(false)
  const changed = title.trim() !== baseline.title || description.trim() !== baseline.description
    || attachedPersonId !== baseline.personId
  const personalTarget = baseline.personId ?? personId ?? null
  const language = i18n.resolvedLanguage ?? 'en'
  const person = snapshot.people.find((entry) => entry.id === attachedPersonId)
  const date = new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short' })

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (busy || conflict || (item ? !changed : !file)) return
    setError('')
    setSaved(false)
    setBusy(true)
    try {
      const base = `/trees/${snapshot.tree.id}/documents`
      let body: FormData | string
      if (item) {
        const patch: UpdateTreeDocumentData['body'] = {}
        if (attachedPersonId !== baseline.personId) patch.personId = attachedPersonId
        if (title.trim() !== baseline.title) patch.title = title.trim() || null
        if (description.trim() !== baseline.description) patch.description = description.trim() || null
        body = JSON.stringify(patch)
      } else {
        const form = new FormData()
        form.set('file', file!)
        if (title.trim()) form.set('title', title.trim())
        if (description.trim()) form.set('description', description.trim())
        if (attachedPersonId) form.set('personId', attachedPersonId)
        body = form
      }
      const result = await request<VersionedTreeDocument>(item ? base + '/' + item.id : base, {
        method: item ? 'PATCH' : 'POST', headers: { 'If-Match': etag.current }, body,
      })
      etag.current = result.etag
      const savedValues = formValues(result.document)
      setTitle(savedValues.title)
      setDescription(savedValues.description)
      setAttachedPersonId(savedValues.personId)
      setBaseline(savedValues)
      setSaved(true)
      onSaved(result.document, result.etag)
    } catch (caught) {
      const key = documentErrorKey(caught)
      setError(key)
      setConflict(key === 'documents.errors.conflict')
    }
    finally { setBusy(false) }
  }

  return <DocumentModal title={t(item ? 'documents.details' : 'documents.addDocument')}
    className={item ? 'document-detail-dialog' : 'document-upload-dialog'} onClose={() => { if (!busy) onClose() }}>
    <form className="document-form" onSubmit={(event) => { void submit(event) }}>
      {!item && <div className="form-field">
        <label htmlFor={fileInputId}>{t('documents.file')}</label>
        <div className="file-picker">
          <input type="file" id={fileInputId} className="file-picker-input" aria-required="true"
            aria-describedby={fileInputId + '-hint'} accept=".txt,.md,text/plain,text/markdown" disabled={busy}
            onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
          <label className="file-picker-button" htmlFor={fileInputId}>{t('documents.chooseFile')}</label>
          <span className={`file-picker-name${file ? '' : ' is-empty'}`} title={file?.name}>
            {file?.name ?? t('documents.noFile')}
          </span>
        </div>
        <small id={fileInputId + '-hint'}>{t('documents.hint')}</small>
      </div>}
      <label className="form-field">{t('documents.name')}
        <input value={title} maxLength={255} disabled={busy}
          onChange={(event) => { setTitle(event.target.value); setSaved(false) }} />
      </label>
      <label className="form-field">{t('documents.description')}
        <textarea value={description} maxLength={2000} rows={3} disabled={busy}
          onChange={(event) => { setDescription(event.target.value); setSaved(false) }} />
      </label>
      <div className="form-field">
        <span>{t('documents.attachedTo')}</span>
        <div className="document-attachment-field">
          <button type="button" role="switch" className="document-switch" aria-label={t('documents.personalDocument')}
            aria-describedby={person ? attachmentNameId : undefined}
            aria-checked={attachedPersonId !== null} disabled={busy || (!attachedPersonId && !personalTarget)}
            onClick={() => { setAttachedPersonId(attachedPersonId ? null : personalTarget); setSaved(false) }}>
            <span className="document-switch-option" aria-hidden="true">{t('documents.shared')}</span>
            <span className="document-switch-option" aria-hidden="true">{t('documents.personalOption')}</span>
          </button>
          {person && <span id={attachmentNameId}>{formatPersonName(person, t)}</span>}
        </div>
      </div>
      <div className="document-form-actions">
        <button type="submit" className="primary-button" disabled={busy || conflict || (item ? !changed : !file)}>
          {item && <SaveIcon className="action-icon" aria-hidden="true" />}
          {t(item ? 'common.save' : 'documents.submitUpload')}
        </button>
        {item && <button type="button" className="secondary-button" onClick={() => {
          void downloadDocument(requestBlob, item.downloadUrl, item.originalName)
            .catch((caught: unknown) => setError(documentErrorKey(caught)))
        }}><DownloadIcon className="action-icon" aria-hidden="true" />{t('documents.download')}</button>}
        {saved && <span role="status">{t('documents.saved')}</span>}
      </div>
      {error && <div role="alert" className="documents-error">{t(error)} {conflict &&
        <button type="button" className="secondary-button compact-button" disabled={busy} onClick={async () => {
          setBusy(true)
          try {
            const fresh = await onRefresh()
            if (item && !fresh.document) { setError('documents.errors.access'); return }
            etag.current = fresh.etag
            if (fresh.document) {
              const next = formValues(fresh.document)
              if (title.trim() === baseline.title) setTitle(next.title)
              if (description.trim() === baseline.description) setDescription(next.description)
              if (attachedPersonId === baseline.personId) setAttachedPersonId(next.personId)
              setBaseline(next)
            }
            setConflict(false)
            setError('')
          }
          catch (caught) { setError(documentErrorKey(caught)) }
          finally { setBusy(false) }
        }}>{t('documents.reload')}</button>}</div>}
    </form>
    {item && <>
      {loadError && !error && <p className="documents-error" role="alert">{t(loadError)}</p>}
      <dl className="document-details">
        <div><dt>{t('documents.file')}</dt><dd>{item.originalName}</dd></div>
        <div><dt>{t('documents.type')}</dt><dd>{item.mimeType === 'text/markdown' ? 'Markdown' : 'TXT'}</dd></div>
        <div><dt>{t('documents.size')}</dt><dd>{formatDocumentSize(item.originalSizeBytes, language)}</dd></div>
        <div><dt>{t('documents.created')}</dt><dd>{date.format(new Date(item.createdAt))}</dd></div>
        <div><dt>{t('documents.updated')}</dt><dd>{date.format(new Date(item.updatedAt))}</dd></div>
        <div><dt>{t('documents.indexing')}</dt><dd role="status">{t(`documents.status.${item.status}`)}</dd></div>
      </dl>
      {item.errorCode && <p className="documents-error">{t(item.errorCode === 'DOCUMENT_QUEUE_UNAVAILABLE'
        ? 'documents.errors.queue' : 'documents.errors.index')}</p>}
      <DocumentContents url={item.downloadUrl} markdown={item.mimeType === 'text/markdown'} requestBlob={requestBlob} />
    </>}
  </DocumentModal>
}
