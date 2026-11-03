import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { TreeMergeDraft, TreeSnapshot } from '../api/generated'
import i18n from '../i18n'
import { TreeMergePanel } from './TreeMergePanel'

const draft: TreeMergeDraft = {
  id: 'merge', treeId: 'tree', referencePersonId: null, version: 1, sourceVersion: 0,
  attemptId: null, attemptSourceVersion: null, attemptStartedAt: null, proposalVersion: 0, proposal: null, questions: [], result: null,
  state: 'collecting', treeEtag: 'etag', messages: [], createdAt: '2026-11-03', updatedAt: '2026-11-03',
}
const snapshot: TreeSnapshot = {
  tree: { id: 'tree', name: 'Family', reference: null, createdAt: '2026-11-03', updatedAt: '2026-11-03' },
  people: [], parentLinks: [], marriages: [], etag: 'etag', layoutRevision: 'layout', layout: null, bloodNetworkState: null,
}

function renderPanel(handler: (path: string, init?: RequestInit) => Promise<unknown>, tree = snapshot) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const close = vi.fn()
  const applied = vi.fn(async () => {})
  const view = render(<TreeMergePanel snapshot={tree} request={request} onClose={close} onApplied={applied} />)
  return { request, close, applied, ...view }
}

const ready: TreeMergeDraft = { ...draft, proposalVersion: 1, state: 'ready', proposal: {
  people: [{ id: 'p', resolution: 'new', existingPersonId: null, lifeStatus: 'alive', evidence: [],
    fields: { givenName: { state: 'known', value: 'Vera' }, gender: 'female', deathDate: null } }],
  parents: [], marriages: [], questions: [],
} }

describe('TreeMergePanel', () => {
  beforeEach(async () => { window.history.replaceState({}, '', '/'); await i18n.changeLanguage('en') })

  it('creates an empty-tree draft, saves escaped messages and discards it', async () => {
    const sent = { ...draft, version: 2, messages: [{
      id: 'message', requestId: 'request', role: 'user' as const, content: '<script>family</script>', createdAt: '2026-11-03',
    }] }
    const { request, close } = renderPanel(async (_path, init) => {
      if (init?.method !== 'POST') return { items: [] }
      if (_path.endsWith('/process')) return sent
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

  it('restores a running attempt and polls until the answer is saved without stealing input focus', async () => {
    const processing = { ...draft, state: 'processing' as const, attemptId: 'attempt', version: 3 }
    const answered = { ...draft, version: 4, messages: [{ id: 'answer', requestId: 'request', role: 'assistant' as const,
      content: 'Confirmed family facts', createdAt: '2026-11-03' }] }
    const { request } = renderPanel(async (path) => path.endsWith('/merge') ? answered : { items: [processing] })
    const input = await screen.findByRole('textbox')
    fireEvent.change(input, { target: { value: 'Next clarification' } })
    expect(await screen.findByText('Confirmed family facts', {}, { timeout: 3500 })).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge', expect.objectContaining({ signal: expect.any(AbortSignal) }))
    expect(input).toHaveValue('Next clarification')
  })

  it('recovers the saved attempt after a transport error and retries without resending the message', async () => {
    const saved: TreeMergeDraft = { ...draft, version: 2, messages: [{ id: 'source', requestId: 'request', role: 'user',
      content: 'Vera is alive', createdAt: '2026-11-03' }] }
    let attempts = 0
    const { request } = renderPanel(async (path) => {
      if (path.endsWith('/process')) {
        if (++attempts === 1) throw new Error('connection interrupted')
        return { ...saved, state: 'ready', version: 5, messages: [...saved.messages,
          { id: 'answer', requestId: 'request', role: 'assistant', content: 'Review Vera', createdAt: '2026-11-03' }] }
      }
      return path.endsWith('/merge') ? { ...saved, state: 'error', version: 4 } : { items: [saved] }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Analyze description' }))
    expect(await screen.findByText(/The description could not be processed/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Analyze description' }))
    expect(await screen.findByText('Review Vera')).toBeInTheDocument()
    expect(request).toHaveBeenLastCalledWith('/trees/tree/merges/merge/process', expect.objectContaining({
      body: JSON.stringify({ version: 4, requestId: 'request' }),
    }))
    expect(screen.getAllByText('Vera is alive')).toHaveLength(1)
  })

  it('offers retry for an abandoned processing attempt after restoring the chat', async () => {
    const stale: TreeMergeDraft = { ...draft, state: 'processing', version: 3, attemptId: 'attempt',
      attemptStartedAt: new Date(Date.now() - 360000).toISOString(), messages: [{ id: 'source', requestId: 'request',
        role: 'user', content: 'My mother is Vera', createdAt: '2026-11-03' }] }
    const { request } = renderPanel(async (path) => path.endsWith('/process') ? { ...stale, state: 'questions' } : { items: [stale] })
    fireEvent.click(await screen.findByRole('button', { name: 'Analyze description' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/process', expect.objectContaining({
      body: JSON.stringify({ version: 3, requestId: 'request' }),
    })))
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

  it('opens a preview link, checks corrections and applies only the reviewed version', async () => {
    window.history.replaceState({}, '', '/?merge=merge')
    const result = { personIds: { p: 'person' }, affectedPersonIds: ['person'], etag: 'new-etag' }
    const { request, applied } = renderPanel(async (path, init) => {
      if (path.endsWith('/proposal')) return { ...ready, version: 2, proposalVersion: 2 }
      if (path.endsWith('/apply')) return { ...ready, state: 'applied', result }
      if (init?.method === 'POST') return draft
      return ready
    })
    fireEvent.change(await screen.findByRole('textbox', { name: 'Given name' }), { target: { value: 'Veronika' } })
    fireEvent.click(screen.getByRole('button', { name: 'Check corrections' }))
    const confirm = await screen.findByRole('checkbox', { name: 'I have reviewed proposal version 2' })
    expect(confirm).not.toBeChecked()
    fireEvent.click(confirm)
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and apply changes' }))
    await waitFor(() => expect(applied).toHaveBeenCalledWith(result))
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/apply', expect.objectContaining({
      headers: { 'If-Match': 'etag' }, body: JSON.stringify({ version: 2, proposalVersion: 2 }),
    }))
    expect(screen.queryByRole('textbox', { name: 'Family description or clarification' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Start another conversation' }))
    expect(window.location.search).toBe('')
    expect(screen.getByRole('button', { name: 'Start conversation' })).toBeEnabled()
  })

  it('fetches the revalidated version after a tree conflict and requires confirmation again', async () => {
    renderPanel(async (path, init) => {
      if (init?.method === 'POST') throw new ApiRequestError(412, { code: 'VERSION_CONFLICT', message: null, fields: {}, details: {} })
      return path.endsWith('/merge') ? { ...ready, version: 3, proposalVersion: 2 } : { items: [ready] }
    })
    fireEvent.click(await screen.findByRole('checkbox', { name: 'I have reviewed proposal version 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and apply changes' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('tree has changed')
    expect(await screen.findByRole('checkbox', { name: 'I have reviewed proposal version 2' })).not.toBeChecked()
    expect(screen.getByRole('button', { name: 'Confirm and apply changes' })).toBeDisabled()
  })

  it('revalidates a conflict and retains corrections on a validation error', async () => {
    renderPanel(async (_path, init) => {
      if (init?.method === 'POST') return ready
      if (init?.method === 'PUT') throw new ApiRequestError(422, { code: 'INVALID', message: null, fields: {}, details: {} })
      return { items: [{ ...ready, state: 'conflict' }] }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Check against the current tree' }))
    await screen.findByRole('checkbox', { name: 'I have reviewed proposal version 1' })
    fireEvent.change(screen.getByRole('textbox', { name: 'Given name' }), { target: { value: 'Veronika' } })
    fireEvent.click(screen.getByRole('button', { name: 'Check corrections' }))
    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Given name' })).toHaveValue('Veronika')
  })
})
