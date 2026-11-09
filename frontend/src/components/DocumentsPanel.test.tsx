import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { Person, TreeDocument, TreeSnapshot } from '../api/generated'
import { emptySnapshot } from '../test/chatFixtures'
import i18n from '../i18n'
import { DocumentsPanel } from './DocumentsPanel'
import { DocumentsButton } from './DocumentsButton'
import { DocumentsDialog } from './DocumentsDialog'

const treeId = '019861c4-6a93-7a2e-8ca8-51813740d4a5'
const person: Person = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a6', birthSurname: { state: 'known', value: 'Ivanova' },
  givenName: { state: 'known', value: 'Anna' }, patronymic: { state: 'unknown' }, gender: 'female',
  birthDate: { state: 'unknown' }, deathDate: null, birthPlace: null, deathPlace: null,
  photoThumbnailUrl: null, createdAt: '2026-11-09T00:00:00Z', updatedAt: '2026-11-09T00:00:00Z',
}
const snapshot: TreeSnapshot = { ...emptySnapshot, tree: { ...emptySnapshot.tree, id: treeId }, people: [person] }
const document: TreeDocument = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a7', treeId, personId: null,
  originalName: 'letter.txt', originalSizeBytes: 1234, mimeType: 'text/plain', status: 'ready', errorCode: null,
  createdAt: '2026-11-09T00:00:00Z', updatedAt: new Date().toISOString(),
  downloadUrl: `/trees/${treeId}/documents/019861c4-6a93-7a2e-8ca8-51813740d4a7/download`,
}
function failure(status: number, code: string) {
  return new ApiRequestError(status, { code, message: code, fields: {}, details: {} })
}
function mount(handler: (path: string, init?: RequestInit) => Promise<unknown>, personId?: string) {
  const request = vi.fn(handler)
  const requestBlob = vi.fn(async () => new Blob(['original']))
  const onChanged = vi.fn(async () => {})
  const result = render(<DocumentsPanel snapshot={snapshot} request={request as AuthorizedJsonRequest}
    requestBlob={requestBlob} onChanged={onChanged} {...(personId ? { personId } : {})} />)
  return { ...result, request, requestBlob, onChanged }
}

beforeEach(async () => {
  await i18n.changeLanguage('en')
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

describe('document modal and counters', () => {
  it('shows the total beyond the first page, refreshes it and keeps files inside the dialog', async () => {
    const request = vi.fn(async () => ({ items: [document], total: 23, nextCursor: null, etag: 'etag' })) as unknown as AuthorizedJsonRequest
    const onClick = vi.fn()
    const { rerender } = render(<DocumentsButton treeId={treeId} personId={person.id} etag="one"
      request={request} onClick={onClick} />)
    expect(await screen.findByText('23')).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(`/trees/${treeId}/documents?limit=1&personId=${person.id}`)
    expect(screen.queryByText(document.originalName)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Documents 23' }))
    expect(onClick).toHaveBeenCalledOnce()
    rerender(<DocumentsButton treeId={treeId} personId={person.id} etag="two" request={request} onClick={onClick} />)
    await waitFor(() => expect(request).toHaveBeenCalledTimes(2))
  })

  it('omits an empty counter and leaves the dialog available after a counter request fails', async () => {
    const request = vi.fn(async () => { throw failure(404, 'TREE_NOT_FOUND') }) as unknown as AuthorizedJsonRequest
    const onClick = vi.fn()
    render(<DocumentsButton treeId={treeId} etag="one" request={request} onClick={onClick} />)
    await waitFor(() => expect(request).toHaveBeenCalledOnce())
    fireEvent.click(screen.getByRole('button', { name: 'Documents' }))
    expect(onClick).toHaveBeenCalledOnce()
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })

  it('opens a styled person dialog, contains keyboard focus and restores focus on close', async () => {
    const request = vi.fn(async () => ({ items: [document], total: 1, nextCursor: null, etag: 'etag' })) as unknown as AuthorizedJsonRequest
    const onClose = vi.fn()
    const launcher = window.document.createElement('button')
    window.document.body.append(launcher)
    launcher.focus()
    const { unmount } = render(<DocumentsDialog snapshot={snapshot} personId={person.id} request={request}
      requestBlob={vi.fn()} onChanged={vi.fn()} onClose={onClose} />)
    const dialog = screen.getByRole('dialog', { name: 'Documents' })
    expect(within(dialog).getByRole('heading', { name: 'Documents' })).toBeInTheDocument()
    expect(within(dialog).getByText('Ivanova Anna', { selector: 'p' })).toBeInTheDocument()
    expect(await within(dialog).findByText('letter.txt')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Attach uploads to')).toHaveValue(person.id)
    const close = within(dialog).getByRole('button', { name: 'Close' })
    const remove = within(dialog).getByRole('button', { name: 'Delete' })
    expect(close).toHaveFocus()
    fireEvent.keyDown(close, { key: 'Tab', shiftKey: true })
    expect(remove).toHaveFocus()
    fireEvent.keyDown(remove, { key: 'Tab' })
    expect(close).toHaveFocus()
    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledOnce()
    fireEvent.click(close)
    expect(onClose).toHaveBeenCalledTimes(2)
    unmount()
    expect(launcher).toHaveFocus()
    launcher.remove()
  })
})

describe('document lists and upload lifecycle', () => {
  it('shows original size, attachment, statuses, filters, pagination and download', async () => {
    const setup = mount(async (path) => ({
      items: path.includes('cursor=') ? [{ ...document, id: 'second', originalName: 'second.md', personId: person.id }] : [document],
      nextCursor: path.includes('cursor=') ? null : 'next',
      etag: 'etag',
    }), person.id)
    expect(await screen.findByText('letter.txt')).toBeInTheDocument()
    expect(screen.getByText('1.2 kB')).toBeInTheDocument()
    expect(screen.getByText('Searchable')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Download' }))
    await waitFor(() => expect(setup.requestBlob).toHaveBeenCalledWith(document.downloadUrl))
    fireEvent.click(screen.getByRole('button', { name: 'Show more' }))
    expect(await screen.findByText('second.md')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('radio', { name: 'Personal' }))
    await waitFor(() => expect(setup.request).toHaveBeenCalledWith(expect.stringContaining('scope=person&personId=' + person.id)))
    fireEvent.click(screen.getByRole('radio', { name: 'All' }))
    await waitFor(() => expect(setup.request).toHaveBeenLastCalledWith(`/trees/${treeId}/documents?`))
  })

  it('uploads several files independently with current ETags and a person target', async () => {
    let count = 0
    const setup = mount(async (_path, init) => {
      if (init?.method === 'POST') {
        ++count
        if (count === 2) throw failure(422, 'DOCUMENT_CONTENT_INVALID')
        return { document, etag: count === 1 ? 'second-etag' : 'third-etag' }
      }
      return { items: [], nextCursor: null, etag: 'etag' }
    }, person.id)
    await screen.findByText('No documents yet.')
    fireEvent.change(screen.getByLabelText('Choose files'), {
      target: { files: [new File(['a'], 'one.txt'), new File(['b'], 'bad.pdf'), new File(['c'], 'three.md')] },
    })
    await waitFor(() => expect(setup.onChanged).toHaveBeenCalledOnce())
    expect(screen.getByText(/bad.pdf: Use a nonempty/)).toBeInTheDocument()
    expect(screen.getByText('one.txt: Saved')).toBeInTheDocument()
    expect(screen.getByText('three.md: Saved')).toBeInTheDocument()
    const uploads = setup.request.mock.calls.filter(([, init]) => init?.method === 'POST')
    expect(uploads.map(([, init]) => new Headers(init?.headers).get('If-Match'))).toEqual(['etag', 'second-etag', 'second-etag'])
    expect((uploads[0]![1]!.body as FormData).get('personId')).toBe(person.id)
    expect(screen.getByRole('radio', { name: 'All' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'Personal' })).toBeEnabled()
  })

  it('stops the remaining uploads after a version conflict and offers refresh', async () => {
    let count = 0
    const setup = mount(async (_path, init) => {
      if (init?.method === 'POST') {
        if (++count === 2) throw failure(412, 'TREE_VERSION_CONFLICT')
        return { document, etag: 'next' }
      }
      return { items: [], nextCursor: null, etag: 'etag' }
    })
    await screen.findByText('No documents yet.')
    fireEvent.change(screen.getByLabelText('Choose files'), {
      target: { files: [new File(['a'], 'one.txt'), new File(['b'], 'two.txt'), new File(['c'], 'three.txt')] },
    })
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('The tree changed'))
    expect(count).toBe(2)
    expect(screen.queryByText('three.txt: Saved')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
    expect(setup.onChanged).toHaveBeenCalledTimes(2)
  })

  it('confirms deletion, supports cancellation, and retries failed indexing', async () => {
    let deleted = false
    let status: TreeDocument['status'] = 'failed'
    const setup = mount(async (path, init) => {
      if (init?.method === 'DELETE') { deleted = true; return { etag: 'deleted-etag' } }
      if (path.endsWith('/retry')) { status = 'queued'; return { document } }
      return { items: deleted ? [] : [{ ...document, status, errorCode: 'DOCUMENT_INDEX_FAILED' }], nextCursor: null, etag: 'etag' }
    })
    await screen.findByText('Processing failed')
    fireEvent.click(screen.getByRole('button', { name: 'Retry indexing' }))
    expect(await screen.findByText('Queued')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Delete' }))
    expect(await screen.findByText('No documents yet.')).toBeInTheDocument()
    expect(setup.onChanged).toHaveBeenCalledOnce()
    expect(setup.request).toHaveBeenCalledWith(expect.stringContaining(document.id), expect.objectContaining({ method: 'DELETE' }))
  })

  it.each([
    [401, 'INVALID_SESSION', 'These documents are unavailable'],
    [413, 'DOCUMENT_TOO_LARGE', 'The file exceeds 10 MiB.'],
    [409, 'DOCUMENT_NOT_RETRYABLE', 'already being processed'],
    [503, 'UNKNOWN', 'Could not complete the operation'],
  ])('shows localized failures (%s)', async (status, code, text) => {
    const setup = mount(async (_path, init) => {
      if (init?.method === 'POST') throw failure(status, code)
      return { items: [document], nextCursor: null, etag: 'etag' }
    })
    await screen.findByText('letter.txt')
    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [new File(['a'], 'bad.txt')] } })
    expect((await screen.findAllByText(new RegExp(text))).length).toBeGreaterThan(0)
    expect(setup.request).toHaveBeenCalled()
  })

  it('polls statuses, reports a failed list load and a failed queue publish', async () => {
    let loads = 0
    const setup = mount(async (_path, init) => {
      if (init?.method === 'POST') return { document: { ...document, status: 'failed' }, etag: 'next' }
      if (++loads === 1) throw failure(404, 'NOT_FOUND')
      return { items: [{ ...document, status: 'processing' }], nextCursor: null, etag: 'etag' }
    })
    expect(await screen.findByRole('alert')).toHaveTextContent('These documents are unavailable')
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await screen.findByText('Indexing')
    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [new File(['a'], 'one.txt')] } })
    expect(await screen.findByText(/one.txt: The file is saved, but indexing could not be queued/)).toBeInTheDocument()
    vi.useFakeTimers()
    await act(async () => { await vi.advanceTimersByTimeAsync(3001) })
    vi.useRealTimers()
    expect(setup.request.mock.calls.length).toBeGreaterThan(3)
  })
})
