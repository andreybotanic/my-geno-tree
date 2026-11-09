import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { DocumentSource } from '../api/generated'
import { useAuth } from '../auth/AuthContext'
import { downloadDocument, formatDocumentSize } from '../documents'
import DownloadIcon from '../assets/icons/export.svg?react'
import DocumentsIcon from '../assets/icons/documents.svg?react'

export function DocumentSourceLinks({ sources }: { sources: DocumentSource[] }) {
  const { t, i18n } = useTranslation()
  const { requestBlob } = useAuth()
  const [failed, setFailed] = useState(false)
  const files = Array.from(new Map(sources.map((source) => [source.downloadUrl, source])).values())
  return <details className="document-sources">
    <summary><DocumentsIcon className="document-sources-icon" aria-hidden="true" />{t('documents.sources', { count: files.length })}</summary>
    {files.map((source) => <div key={source.downloadUrl}>
      <button type="button" className="document-download-link"
        onClick={() => { void downloadDocument(requestBlob, source.downloadUrl, source.name).catch(() => setFailed(true)) }}>
        <DownloadIcon className="document-download-icon" aria-hidden="true" />
        {source.name}
      </button>
      {source.originalSizeBytes != null && <span className="document-source-size">
        {formatDocumentSize(source.originalSizeBytes, i18n.resolvedLanguage ?? 'en')}
      </span>}
    </div>)}
    {failed && <p role="alert">{t('documents.errors.failed')}</p>}
  </details>
}
