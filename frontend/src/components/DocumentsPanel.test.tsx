import userEvent from '@testing-library/user-event'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { Person, TreeDocument, TreeSnapshot } from '../api/generated'
import { emptySnapshot } from '../test/chatFixtures'
import i18n from '../i18n'
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
  title: null, description: null, originalName: 'letter.txt', originalSizeBytes: 1234, mimeType: 'text/plain', status: 'ready', errorCode: null,
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
  const result = render(<DocumentsDialog onClose={vi.fn()} snapshot={snapshot} request={request as AuthorizedJsonRequest}
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
    expect(within(scope).getByText('All')).toBeInTheDocument()
    expect(within(scope).getByText('Personal')).toBeInTheDocument()
    const list = screen.getByRole('list', { name: 'Documents' })
    expect(list).toHaveClass('document-list-tiles')
    expect(within(list).queryByRole('switch')).not.toBeInTheDocument()
    const view = screen.getByRole('switch', { name: 'Document view' })
    fireEvent.click(view)
    expect(view).toBeChecked()
    expect(list).toHaveClass('document-list-list')
    expect(within(list).queryByRole('switch')).not.toBeInTheDocument()
    expect(within(view).getByTitle('Tiles')).toContainHTML('<svg')
    expect(within(view).getByTitle('List')).toContainHTML('<svg')
    expect(screen.getByText('(1.2 kB)')).toBeInTheDocument()
    fireEvent.click(view)
    expect(list).toHaveClass('document-list-tiles')
  })

  it('shows original size, statuses, filters, pagination and download', async () => {
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
    expect(within(documents).queryByRole('switch')).not.toBeInTheDocument()
    expect(within(documents).queryByText('Whole tree')).not.toBeInTheDocument()
    expect(within(documents).queryByText('Ivanova Anna')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    await waitFor(() => expect(setup.request).toHaveBeenCalledWith(expect.stringContaining('scope=person&personId=' + person.id)))
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    await waitFor(() => expect(setup.request).toHaveBeenLastCalledWith(`/trees/${treeId}/documents?newestFirst=true`))
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

  it('rejects a page from a newer tree version until the user refreshes the list', async () => {
    let version = 'first'
    const setup = mount(async () => version === 'first'
      ? { items: [document], nextCursor: document.id, etag: version }
      : { items: [{ ...document, title: 'Changed elsewhere' }], nextCursor: null, etag: version })
    await screen.findByText('letter.txt')
    version = 'second'
    fireEvent.click(screen.getByRole('button', { name: 'Show more' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The tree changed')
    expect(screen.queryByText('Changed elsewhere')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('Changed elsewhere')).toBeVisible()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(setup.request).toHaveBeenCalledTimes(3)
  })

  it('uploads one file explicitly and returns the saved title to the previous list', async () => {
    const setup = mount(async (_path, init) => init?.method === 'POST'
      ? { document: { ...document, title: 'Archive', description: 'Letters' }, etag: 'next' }
      : { items: [], nextCursor: null, etag: 'etag' }, person.id)
    await screen.findByText('No documents yet.')
    fireEvent.click(screen.getByRole('switch', { name: 'Document view' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    expect(screen.getByRole('button', { name: 'Upload' })).toBeDisabled()
    expect(screen.getByRole('switch', { name: 'Personal document' })).toBeChecked()
    const file = screen.getByLabelText('File')
    expect(screen.getByLabelText('Choose file')).toBe(file)
    expect(screen.getByText('No file selected')).toBeVisible()
    expect(file).not.toHaveAttribute('multiple')
    await userEvent.upload(file as HTMLInputElement, new File(['text'], 'one.txt'))
    expect(screen.getByText('one.txt')).toBeVisible()
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Archive' } })
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Letters' } })
    expect(setup.request.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }))
    expect(await screen.findByText('Archive')).toBeVisible()
    expect(screen.getByRole('switch', { name: 'Document view' })).toBeChecked()
    const upload = setup.request.mock.calls.find(([, init]) => init?.method === 'POST')![1]!
    expect((upload.body as FormData).get('personId')).toBe(person.id)
    expect((upload.body as FormData).get('description')).toBe('Letters')
    expect((upload.body as FormData).get('title')).toBe('Archive')
  })

  it('uploads a shared document from a person without adding it to their personal list', async () => {
    const setup = mount(async (_path, init) => init?.method === 'POST'
      ? { document, etag: 'uploaded' } : { items: [], nextCursor: null, etag: 'etag' }, person.id)
    await screen.findByText('No documents yet.')
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Add' })).toBeEnabled())
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    const attachment = screen.getByRole('switch', { name: 'Personal document' })
    fireEvent.click(attachment)
    expect(attachment).not.toBeChecked()
    await userEvent.upload(screen.getByLabelText('File') as HTMLInputElement, new File(['letter'], 'letter.txt'))
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }))
    expect(await screen.findByRole('dialog', { name: 'Documents' })).toBeVisible()
    expect(screen.getByRole('switch', { name: 'Show documents' })).toBeChecked()
    expect(screen.queryByText('letter.txt')).not.toBeInTheDocument()
    const upload = setup.request.mock.calls.find(([, init]) => init?.method === 'POST')![1]!
    expect((upload.body as FormData).has('personId')).toBe(false)
  })

  it('saves only a changed attachment, retains the editor and respects the active personal filter', async () => {
    let item: TreeDocument = { ...document, personId: person.id }
    const setup = mount(async (path, init) => {
      if (init?.method === 'PATCH') {
        item = { ...item, ...JSON.parse(String(init.body)) as Partial<TreeDocument> }
        return { document: item, etag: 'changed' }
      }
      if (path.endsWith(item.id)) return { document: item, etag: 'changed' }
      const items = path.includes('scope=person') && !item.personId ? [] : [item]
      return { items, nextCursor: null, etag: 'etag' }
    }, person.id)
    await screen.findByText('letter.txt')
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    const toggle = screen.getByRole('switch', { name: 'Personal document' })
    expect(toggle).toBeChecked()
    fireEvent.click(toggle)
    expect(setup.request.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false)
    fireEvent.click(toggle)
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
    fireEvent.click(toggle)
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Saved')).toBeVisible()
    expect(toggle).not.toBeChecked()
    expect(setup.request).toHaveBeenCalledWith(expect.stringContaining(item.id), expect.objectContaining({
      body: JSON.stringify({ personId: null }), headers: { 'If-Match': 'etag' },
    }))
    fireEvent.keyDown(toggle, { key: 'Escape' })
    expect(await screen.findByText('No documents yet.')).toBeVisible()
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Show documents' })).toHaveFocus())
  })

  it('preserves the draft on conflict, rebases untouched fields and saves inside the editor', async () => {
    let item = { ...document, mimeType: 'text/markdown' as const, description: 'Old' }
    let version = 'etag'
    const setup = mount(async (path, init) => {
      if (init?.method === 'PATCH') {
        if (new Headers(init.headers).get('If-Match') !== version) throw failure(412, 'TREE_VERSION_CONFLICT')
        item = { ...item, ...JSON.parse(String(init.body)) as Partial<typeof item> }
        return { document: item, etag: 'saved' }
      }
      if (path.endsWith(item.id)) return { document: item, etag: version }
      return { items: [item], nextCursor: null, etag: version }
    }, person.id)
    setup.requestBlob.mockResolvedValue(new Blob(['# Letter\n\n| A | B |\n|---|---|\n|1|2|\n\n![alt](https://invalid.test/image)']))
    await screen.findByText('letter.txt')
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    expect(await screen.findByRole('heading', { name: 'Letter' })).toBeVisible()
    expect(screen.getByRole('table')).toBeVisible()
    for (const name of ['Save', 'Download']) {
      expect(screen.getByRole('button', { name }).querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
    }
    expect(screen.queryByRole('img')).toBeNull()
    const title = screen.getByLabelText('Name')
    const toggle = screen.getByRole('switch', { name: 'Personal document' })
    expect(toggle).not.toBeChecked()
    fireEvent.click(toggle)
    fireEvent.change(title, { target: { value: 'Draft' } })
    version = 'concurrent'
    item = { ...item, description: 'Edited elsewhere' }
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The tree changed')
    expect(title).toHaveValue('Draft')
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await waitFor(() => expect(screen.getByLabelText('Description')).toHaveValue('Edited elsewhere'))
    expect(toggle).toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Saved')).toBeVisible()
    expect(screen.getByRole('dialog', { name: 'Document details' })).toBeVisible()
    expect(item.personId).toBe(person.id)
    expect(setup.requestBlob).toHaveBeenCalledOnce()
    fireEvent.change(title, { target: { value: 'Discarded' } })
    fireEvent.keyDown(title, { key: 'Escape' })
    expect(screen.getByText('Draft')).toBeVisible()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toHaveFocus())
  })

  it('refreshes a detached document by ID while keeping an unsaved edit and its original preview', async () => {
    let item: TreeDocument = { ...document, personId: person.id, status: 'processing' }
    const setup = mount(async (path, init) => {
      if (init?.method === 'PATCH') throw failure(412, 'TREE_VERSION_CONFLICT')
      if (path.endsWith(item.id)) return { document: item, etag: 'new' }
      return { items: item.personId ? [item] : [], nextCursor: null, etag: item.personId ? 'etag' : 'new' }
    }, person.id)
    await screen.findByText('letter.txt')
    fireEvent.click(screen.getByRole('switch', { name: 'Show documents' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    const title = screen.getByLabelText('Name')
    fireEvent.change(title, { target: { value: 'Draft' } })
    item = { ...item, status: 'ready', personId: null }
    expect(screen.getByText('Indexing', { selector: 'dd' })).toBeVisible()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The tree changed')
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('Searchable')).toBeVisible()
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Personal document' })).not.toBeChecked())
    expect(title).toHaveValue('Draft')
    expect(setup.requestBlob).toHaveBeenCalledOnce()
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
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    expect(screen.getByRole('switch', { name: 'Personal document' })).toBeDisabled()
    await userEvent.upload(screen.getByLabelText(/^File/) as HTMLInputElement, new File(['a'], 'bad.txt'))
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }))
    expect((await screen.findAllByText(new RegExp(text))).length).toBeGreaterThan(0)
    expect(setup.request).toHaveBeenCalled()
  })

  it('retries a failed list on demand and displays the queue error from the upload response', async () => {
    let loads = 0
    const setup = mount(async (_path, init) => {
      if (init?.method === 'POST') return { document: { ...document, status: 'failed', errorCode: 'DOCUMENT_QUEUE_UNAVAILABLE' }, etag: 'next' }
      if (++loads === 1) throw failure(404, 'NOT_FOUND')
      return { items: [{ ...document, status: 'processing' }], nextCursor: null, etag: 'etag' }
    })
    expect(await screen.findByRole('alert')).toHaveTextContent('These documents are unavailable')
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await screen.findByRole('status', { name: 'Indexing' })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await userEvent.upload(screen.getByLabelText(/^File/) as HTMLInputElement, new File(['a'], 'one.txt'))
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }))
    expect(await screen.findByRole('status', { name: 'Processing failed' })).toHaveAttribute('title', expect.stringContaining('could not be queued'))
    expect(setup.request).toHaveBeenCalledTimes(3)
  })

  it('makes no idle requests in the list, upload form or editor, and refreshes status on demand', async () => {
    vi.useFakeTimers()
    try {
      let item: TreeDocument = { ...document, status: 'processing' }
      const setup = mount(async (path) => path.endsWith(item.id)
        ? { document: item, etag: 'etag' }
        : { items: [item], nextCursor: null, etag: 'etag' })
      const idle = () => act(async () => { await vi.advanceTimersByTimeAsync(60_000) })
      await idle()
      expect(setup.request).toHaveBeenCalledOnce()
      fireEvent.click(screen.getByRole('button', { name: 'Add' }))
      await idle()
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })
      fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
      fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Draft' } })
      item = { ...item, status: 'ready' }
      await idle()
      expect(setup.request).toHaveBeenCalledOnce()
      expect(screen.getByText('Indexing', { selector: 'dd' })).toBeVisible()
      fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
      await idle()
      expect(screen.getByText('Searchable')).toBeVisible()
      expect(screen.getByLabelText('Description')).toHaveValue('Draft')
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })
      await idle()
      expect(setup.request).toHaveBeenCalledTimes(2)
      expect(setup.request).toHaveBeenLastCalledWith(`/trees/${treeId}/documents/${item.id}`)
      expect(setup.requestBlob).toHaveBeenCalledOnce()
    } finally { vi.useRealTimers() }
  })
})
