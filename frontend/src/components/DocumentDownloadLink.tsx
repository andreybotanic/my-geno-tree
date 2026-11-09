import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import { downloadDocument } from '../documents'
import DownloadIcon from '../assets/icons/export.svg?react'

export function DocumentDownloadLink({ url, name, children }: { url: string; name: string; children?: ReactNode }) {
  const { requestBlob } = useAuth()
  const { t } = useTranslation()
  const [failed, setFailed] = useState(false)
  return <span className="document-download">
    <button type="button" className="document-download-link" title={t('documents.download')}
      onClick={() => { void downloadDocument(requestBlob, url, name).catch(() => setFailed(true)) }}>
      <DownloadIcon className="document-download-icon" aria-hidden="true" />{children ?? name}
    </button>
    {failed && <span role="alert">{t('documents.errors.failed')}</span>}
  </span>
}
