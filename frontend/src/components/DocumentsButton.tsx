import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import type { DocumentPage } from '../api/generated'
import DocumentsIcon from '../assets/icons/documents.svg?react'

type Props = {
  treeId: string
  etag: string
  personId?: string
  request: AuthorizedJsonRequest
  onClick: () => void
  className?: string
}

export function DocumentsButton({ treeId, etag, personId, request, onClick, className = '' }: Props) {
  const { t } = useTranslation()
  const [total, setTotal] = useState(0)
  useEffect(() => {
    let active = true
    setTotal(0)
    const query = new URLSearchParams({ limit: '1' })
    if (personId) query.set('personId', personId)
    void request<DocumentPage>(`/trees/${treeId}/documents?${query}`).then((page) => {
      if (active) setTotal(page.total)
    }).catch(() => { /* The dialog displays access and loading errors. */ })
    return () => { active = false }
  }, [treeId, etag, personId, request])

  return <button type="button" className={`secondary-button documents-open-button ${className}`} onClick={onClick}>
    <DocumentsIcon aria-hidden="true" />
    {t('documents.title')}
    {total > 0 && <>{' '}<span className="documents-count">{total}</span></>}
  </button>
}
