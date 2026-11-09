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
