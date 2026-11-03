import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { TreeMergeDraft, TreeSnapshot } from '../api/generated'
import i18n from '../i18n'
import { TreeMergePanel } from './TreeMergePanel'

const draft: TreeMergeDraft = {
  id: 'merge', treeId: 'tree', referencePersonId: null, version: 1, sourceVersion: 0,
  proposalVersion: 0, proposal: null, questions: [], result: null,
  state: 'collecting', treeEtag: 'etag', messages: [], createdAt: '2026-11-03', updatedAt: '2026-11-03',
}
const snapshot: TreeSnapshot = {
  tree: { id: 'tree', name: 'Family', reference: null, createdAt: '2026-11-03', updatedAt: '2026-11-03' },
  people: [], parentLinks: [], marriages: [], etag: 'etag', layoutRevision: 'layout', layout: null, bloodNetworkState: null,
}

function renderPanel(handler: (path: string, init?: RequestInit) => Promise<unknown>, tree = snapshot) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const close = vi.fn()
  const view = render(<TreeMergePanel snapshot={tree} request={request} onClose={close} />)
  return { request, close, ...view }
}

describe('TreeMergePanel', () => {
  beforeEach(async () => { await i18n.changeLanguage('en') })

  it('creates an empty-tree draft, saves escaped messages and discards it', async () => {
    const sent = { ...draft, version: 2, messages: [{
      id: 'message', requestId: 'request', role: 'user' as const, content: '<script>family</script>', createdAt: '2026-11-03',
    }] }
    const { request, close } = renderPanel(async (_path, init) => {
      if (init?.method !== 'POST') return { items: [] }
      return JSON.parse(String(init.body)).content ? sent : draft
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Start conversation' }))
    const input = await screen.findByRole('textbox', { name: 'Family description or clarification' })
    expect(input).toHaveFocus()
    fireEvent.change(input, { target: { value: '<script>family</script>' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByText('<script>family</script>')).toBeInTheDocument()
    expect(input).toHaveValue('')
    expect(document.querySelector('script')).toBeNull()
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/messages', expect.objectContaining({ method: 'POST' }))
    fireEvent.keyDown(input, { key: 'Escape' })
    expect(close).toHaveBeenCalledOnce()
    fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }))
    expect(await screen.findByRole('button', { name: 'Start conversation' })).toBeEnabled()
  })

  it('keeps failed input and reuses the request id on retry', async () => {
    const requests: string[] = []
    let fail = true
    renderPanel(async (_path, init) => {
      if (init?.method !== 'POST') return { items: [draft] }
      requests.push(String(init.body))
      if (fail) { fail = false; throw new Error('offline') }
      return { ...draft, version: 2 }
    })
    const input = await screen.findByRole('textbox')
    fireEvent.change(input, { target: { value: 'My mother is Vera' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save')
    expect(input).toHaveValue('My mother is Vera')
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(input).toHaveValue(''))
    expect(requests[0]).toBe(requests[1])
  })

  it('requires an explicit reference in an existing tree', async () => {
    const person = {
      id: 'person', birthSurname: { state: 'unknown' as const }, givenName: { state: 'known' as const, value: 'Vera' },
      patronymic: { state: 'unknown' as const }, gender: 'female' as const, birthDate: { state: 'unknown' as const },
      deathDate: null, birthPlace: null, deathPlace: null, photoThumbnailUrl: null, createdAt: '2026-11-03', updatedAt: '2026-11-03',
    }
    const { request } = renderPanel(async (_path, init) => init?.method === 'POST' ? draft : { items: [] }, { ...snapshot, people: [person] })
    const start = await screen.findByRole('button', { name: 'Start conversation' })
    expect(start).toBeDisabled()
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'person' } })
    fireEvent.click(start)
    await screen.findByRole('textbox')
    expect(request).toHaveBeenCalledWith('/trees/tree/merges', expect.objectContaining({ body: JSON.stringify({ referencePersonId: 'person' }) }))
  })

  it('reloads a conflicting draft without losing the unsaved message', async () => {
    let reads = 0
    renderPanel(async (_path, init) => {
      if (init?.method === 'POST') throw new ApiRequestError(409, { code: 'TREE_MERGE_VERSION_CONFLICT', message: null, fields: {}, details: {} })
      return reads++ === 0 ? { items: [draft] } : { ...draft, version: 3 }
    })
    const input = await screen.findByRole('textbox')
    fireEvent.change(input, { target: { value: 'Correction' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('another tab')
    fireEvent.click(screen.getByRole('button', { name: 'Reload conversation' }))
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
    expect(input).toHaveValue('Correction')
  })

  it('reports loading failures and failed draft creation', async () => {
    renderPanel(async () => { throw new Error('offline') })
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded')
    fireEvent.click(screen.getByRole('button', { name: 'Start conversation' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save')
  })

  it('reports expired authorization and failed cancellation without dropping the draft', async () => {
    renderPanel(async (_path, init) => {
      if (init?.method === 'POST') throw new ApiRequestError(401, { code: 'UNAUTHORIZED', message: null, fields: {}, details: {} })
      return { items: [draft] }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Discard draft' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('session has expired')
    expect(screen.getByRole('textbox')).toBeInTheDocument()
  })
})
