import { describe, expect, it, vi } from 'vitest'
import { downloadDocument, formatDocumentSize } from './documents'

describe('document originals', () => {
  it('formats stored byte sizes without truncating small files', () => {
    expect(formatDocumentSize(83, 'en')).toBe('83 byte')
    expect(formatDocumentSize(1_234, 'en')).toBe('1.2 kB')
    expect(formatDocumentSize(1_234_567, 'en')).toBe('1.2 MB')
    expect(formatDocumentSize(1_234, 'ru')).toContain('1,2')
  })
  it('downloads through the authenticated client and rejects arbitrary source URLs', async () => {
    const request = vi.fn(async () => new Blob(['original']))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const revoke = vi.spyOn(URL, 'revokeObjectURL')
    const path = '/trees/019861c4-6a93-7a2e-8ca8-51813740d4a5/documents/019861c4-6a93-7a2e-8ca8-51813740d4a7/download'
    await downloadDocument(request, path, 'letter.txt')
    expect(request).toHaveBeenCalledWith(path)
    expect(click).toHaveBeenCalled()
    expect(revoke).toHaveBeenCalled()
    await expect(downloadDocument(request, 'https://example.test/file', 'letter.txt')).rejects.toThrow('Invalid document URL')
  })
})
