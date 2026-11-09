import { useTranslation } from 'react-i18next'
import type { DocumentSource } from '../api/generated'
import { formatDocumentSize } from '../documents'
import { DocumentDownloadLink } from './DocumentDownloadLink'
import DocumentsIcon from '../assets/icons/documents.svg?react'

export function DocumentSourceLinks({ sources }: { sources: DocumentSource[] }) {
  const { t, i18n } = useTranslation()
  const files = Array.from(new Map(sources.map((source) => [source.downloadUrl, source])).values())
  if (files.length === 0) return null
  return <details className="document-sources">
    <summary><DocumentsIcon className="document-sources-icon" aria-hidden="true" />{t('documents.sources', { count: files.length })}</summary>
    {files.map((source) => <div key={source.downloadUrl}>
      <DocumentDownloadLink url={source.downloadUrl} name={source.name} />
      {source.originalSizeBytes != null && <span className="document-source-size">
        {formatDocumentSize(source.originalSizeBytes, i18n.resolvedLanguage ?? 'en')}
      </span>}
    </div>)}
  </details>
}
