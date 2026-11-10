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
    expect(within(dialog).queryByText('Ivanova Anna')).not.toBeInTheDocument()
    expect(await within(dialog).findByText('letter.txt')).toBeInTheDocument()
    expect(within(dialog).queryByRole('switch', { name: 'Attach uploads to' })).not.toBeInTheDocument()
    const target = within(dialog).getByRole('switch', { name: 'Attachment for letter.txt' })
    expect(target).not.toBeChecked()
    expect(within(target).getByText('Shared')).toBeInTheDocument()
    expect(within(target).getByText('Personal')).toBeInTheDocument()
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
  it('switches tiles and compact rows independently of the scope and disables personal scope without a person', async () => {
    mount(async () => ({ items: [document], total: 1, nextCursor: null, etag: 'etag' }))
    await screen.findByText('letter.txt')
    const scope = screen.getByRole('switch', { name: 'Show documents' })
    expect(scope).toBeDisabled()
    expect(screen.getByRole('switch', { name: 'Attachment for letter.txt' })).toBeDisabled()
    expect(within(scope).getByText('All')).toBeInTheDocument()
    expect(within(scope).getByText('Personal')).toBeInTheDocument()
    const list = screen.getByRole('list', { name: 'Documents' })
    expect(list).toHaveClass('document-list-tiles')
    const view = screen.getByRole('switch', { name: 'Document view' })
    fireEvent.click(view)
    expect(view).toBeChecked()
    expect(list).toHaveClass('document-list-list')
    expect(within(view).getByTitle('Tiles')).toContainHTML('<svg')
    expect(within(view).getByTitle('List')).toContainHTML('<svg')
    expect(screen.getByText('(1.2 kB)')).toBeInTheDocument()
    fireEvent.click(view)
    expect(list).toHaveClass('document-list-tiles')
  })

  it('shows original size, attachment, statuses, filters, pagination and download', async () => {
    const setup = mount(async (path) => ({
      items: path.includes('cursor=') ? [{ ...document, id: 'second', originalName: 'second.md', personId: person.id }] : [document],
      nextCursor: path.includes('cursor=') ? null : 'next',
      etag: 'etag',
    }), person.id)
    expect(await screen.findByText('letter.txt')).toBeInTheDocument()
    expect(screen.getByText('(1.2 kB)')).toBeInTheDocument()
    expect(screen.queryByText('Searchable')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Download' }))
    await waitFor(() => expect(setup.requestBlob).toHaveBeenCalledWith(document.downloadUrl))
    fireEvent.click(screen.getByRole('button', { name: 'Show more' }))
    expect(await screen.findByText('second.md')).toBeInTheDocument()
    const documents = screen.getByRole('list', { name: 'Documents' })
    expect(within(documents).getByRole('switch', { name: 'Attachment for letter.txt' })).not.toBeChecked()
    expect(within(documents).getByRole('switch', { name: 'Attachment for second.md' })).toBeChecked()
    expect(within(documents).queryByText('Whole tree')).not.toBeInTheDocument()
    expect(within(documents).queryByText('Ivanova Anna')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    await waitFor(() => expect(setup.request).toHaveBeenCalledWith(expect.stringContaining('scope=person&personId=' + person.id)))
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    await waitFor(() => expect(setup.request).toHaveBeenLastCalledWith(`/trees/${treeId}/documents?`))
  })

  it('keeps loading feedback inside the list and the view switch usable during a delayed scope change', async () => {
    let finishLoading: (() => void) | undefined
    mount(async (path) => {
      const page = { items: [document], total: 1, nextCursor: null, etag: 'etag' }
      if (!path.includes('scope=person')) return page
      return new Promise((resolve) => { finishLoading = () => resolve(page) })
    }, person.id)
    await screen.findByText('letter.txt')
    const list = screen.getByRole('list', { name: 'Documents' })
    const view = screen.getByRole('switch', { name: 'Document view' })
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    const loading = await screen.findByText('Loading documents…')
    expect(loading.parentElement).toContainElement(list)
    expect(loading.parentElement).not.toContainElement(view)
    expect(view).toBeEnabled()
    fireEvent.click(view)
    expect(view).toBeChecked()
    expect(list).toHaveClass('document-list-list')
    finishLoading?.()
    await waitFor(() => expect(screen.queryByText('Loading documents…')).not.toBeInTheDocument())
    expect(screen.getByRole('switch', { name: 'Document view' })).toBe(view)
    expect(view).toBeChecked()
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
    expect(screen.getByRole('switch', { name: 'Show documents' })).not.toBeChecked()
    expect(screen.getByRole('switch', { name: 'Show documents' })).toBeEnabled()
  })

  it('changes each document attachment with consecutive ETags and retains the list view', async () => {
    let item = document
    let changes = 0
    const setup = mount(async (_path, init) => {
      if (init?.method === 'PATCH') {
        item = { ...item, ...JSON.parse(String(init.body)) as { personId: string | null } }
        return { document: item, etag: `changed-${++changes}` }
      }
      return { items: [item], nextCursor: null, etag: 'etag' }
    }, person.id)
    await screen.findByText('letter.txt')
    fireEvent.click(screen.getByRole('switch', { name: 'Document view' }))
    const binding = screen.getByRole('switch', { name: 'Attachment for letter.txt' })
    expect(binding).not.toBeChecked()
    fireEvent.click(binding)
    await waitFor(() => expect(setup.onChanged).toHaveBeenCalledOnce())
    await waitFor(() => expect(binding).toBeEnabled())
    expect(binding).toBeChecked()
    fireEvent.click(binding)
    await waitFor(() => expect(setup.onChanged).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(binding).not.toBeChecked())
    const changesSent = setup.request.mock.calls.filter(([, init]) => init?.method === 'PATCH')
    expect(changesSent.map(([, init]) => JSON.parse(String(init?.body)))).toEqual([{ personId: person.id }, { personId: null }])
    expect(changesSent.map(([, init]) => new Headers(init?.headers).get('If-Match'))).toEqual(['etag', 'changed-1'])
    expect(screen.getByRole('switch', { name: 'Document view' })).toBeChecked()
    expect(screen.queryByText('Ivanova Anna')).not.toBeInTheDocument()
  })

  it('keeps the attachment unchanged after a version conflict and allows refresh', async () => {
    const setup = mount(async (_path, init) => {
      if (init?.method === 'PATCH') throw failure(412, 'TREE_VERSION_CONFLICT')
      return { items: [document], nextCursor: null, etag: 'etag' }
    }, person.id)
    await screen.findByText('letter.txt')
    const binding = screen.getByRole('switch', { name: 'Attachment for letter.txt' })
    fireEvent.click(binding)
    expect(await screen.findByRole('alert')).toHaveTextContent('The tree changed')
    expect(binding).not.toBeChecked()
    expect(binding).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
    expect(setup.onChanged).toHaveBeenCalledOnce()
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

  it('marks unindexed documents and confirms icon deletion without a custom retry', async () => {
    let deleted = false
    const setup = mount(async (_path, init) => {
      if (init?.method === 'DELETE') { deleted = true; return { etag: 'deleted-etag' } }
      return { items: deleted ? [] : [{ ...document, status: 'failed', errorCode: 'DOCUMENT_INDEX_FAILED' }], nextCursor: null, etag: 'etag' }
    })
    expect(await screen.findByText('Not indexed')).toHaveAccessibleName('Processing failed')
    expect(screen.queryByRole('button', { name: 'Retry indexing' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete' }).querySelector('svg')).not.toBeNull()
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
    await screen.findByRole('status', { name: 'Indexing' })
    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [new File(['a'], 'one.txt')] } })
    expect(await screen.findByText(/one.txt: The file is saved, but indexing could not be queued/)).toBeInTheDocument()
    vi.useFakeTimers()
    await act(async () => { await vi.advanceTimersByTimeAsync(3001) })
    vi.useRealTimers()
    expect(setup.request.mock.calls.length).toBeGreaterThan(3)
  })
})
