import { ApiRequestError } from './api/client'
import type { AuthorizedBlobRequest } from './api/client'

export function formatDocumentSize(bytes: number, language: string) {
  const scale = bytes >= 1_000_000 ? 1_000_000 : bytes >= 1_000 ? 1_000 : 1
  return new Intl.NumberFormat(language, {
    style: 'unit',
    unit: scale === 1_000_000 ? 'megabyte' : scale === 1_000 ? 'kilobyte' : 'byte',
    maximumFractionDigits: 1,
  }).format(bytes / scale)
}

export async function downloadDocument(request: AuthorizedBlobRequest, url: string, name: string) {
  if (!/^\/trees\/[\da-f-]+\/documents\/[\da-f-]+\/download$/.test(url)) throw new Error('Invalid document URL')
  const blob = await request(url)
  const objectUrl = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = name
  anchor.click()
  URL.revokeObjectURL(objectUrl)
}

export function documentErrorKey(error: unknown) {
  if (error instanceof ApiRequestError) {
    if (error.body.code === 'DOCUMENT_METADATA_INVALID') return 'documents.errors.metadata'
    if (error.status === 412) return 'documents.errors.conflict'
    if (error.status === 401 || error.status === 404) return 'documents.errors.access'
    if (error.body.code === 'DOCUMENT_TOO_LARGE') return 'documents.errors.size'
    if (['DOCUMENT_CONTENT_INVALID', 'DOCUMENT_UPLOAD_INVALID', 'DOCUMENT_NAME_INVALID'].includes(error.body.code)) {
      return 'documents.errors.invalid'
    }
  }
  return 'documents.errors.failed'
}
