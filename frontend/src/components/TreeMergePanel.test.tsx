import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { TreeMergeDraft, TreeSnapshot } from '../api/generated'
import i18n from '../i18n'
import { TreeMergePanel } from './TreeMergePanel'
import { useTreeMergeEvents } from '../hooks/useTreeMergeEvents'

vi.mock('../hooks/useTreeMergeEvents', async (original) => ({
  ...await original<typeof import('../hooks/useTreeMergeEvents')>(),
  useTreeMergeEvents: vi.fn(() => ({ error: null, reconnect: vi.fn() })),
}))

const draft: TreeMergeDraft = {
  id: 'merge', treeId: 'tree', referencePersonId: null, version: 1, chatState: 'idle',
  progress: [], tokenBudget: { remaining: 10000000, limit: 10000000 }, tokenUsage: { input: 0, output: 0, total: 0 }, attemptId: null, attemptStartedAt: null, proposalVersion: 0, proposal: null, questions: [], result: null,
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
  const refreshTree = vi.fn(async () => {})
  const view = render(<TreeMergePanel snapshot={tree} request={request} onClose={close} onApplied={applied} onRefreshTree={refreshTree} />)
  return { request, close, applied, refreshTree, ...view }
}

const ready: TreeMergeDraft = { ...draft, proposalVersion: 1, state: 'ready', proposal: {
  people: [{ id: 'p', resolution: 'new', existingPersonId: null, lifeStatus: 'alive', evidence: [],
    fields: { givenName: { state: 'known', value: 'Vera' }, gender: 'female', deathDate: null } }],
  parents: [], marriages: [], questions: [],
} }

describe('TreeMergePanel', () => {
  beforeEach(async () => { localStorage.clear(); window.history.replaceState({}, '', '/'); await i18n.changeLanguage('en') })

  it('renders the agent sentence verbatim and system failures outside assistant messages', async () => {
    const sentence = 'Ready to add 1 person; Boris was omitted at your request.'
    const saved: TreeMergeDraft = { ...ready, messages: [
      { id: 'answer', requestId: 'first', role: 'assistant', content: sentence, createdAt: '2026-11-04' },
      { id: 'notice', requestId: 'second', role: 'system', content: '', failureCode: 'provider_unavailable', createdAt: '2026-11-04' },
    ] }
    renderPanel(async () => ({ draft: saved }))
    expect(await screen.findByRole('article', { name: 'Assistant' })).toHaveTextContent(sentence)
    expect(screen.getByRole('article', { name: 'Assistant' }).textContent).toBe(sentence)
    expect(screen.getByRole('status')).toHaveTextContent('The AI service is unavailable; please try again shortly.')
    expect(screen.getAllByRole('article', { name: 'Assistant' })).toHaveLength(1)
  })

  it('shows a merge preview before the ordinary answer and applies it only after clicking confirm', async () => {
    let finish: ((value: TreeMergeDraft) => void) | undefined
    const userMessage = { id: 'user', requestId: 'request', role: 'user' as const, content: 'Add Vera.', createdAt: 'now' }
    const sent = { ...draft, version: 2, messages: [userMessage] }
    const preview: TreeMergeDraft = { ...ready, version: 4, chatState: 'processing', messages: [userMessage] }
    const answer = `Vera is ready for review.\n\n${'Here are her details. '.repeat(20)}<b>Plain text</b>`
    const completed: TreeMergeDraft = { ...preview, version: 5, chatState: 'idle', state: 'ready', messages: [userMessage,
      { id: 'answer', requestId: 'request', role: 'assistant', content: answer, createdAt: 'now' }] }
    const { request } = renderPanel(async (path, init) => {
      if (path.endsWith('/messages')) return sent
      if (path.endsWith('/process')) return new Promise<TreeMergeDraft>((resolve) => { finish = resolve })
      if (path.endsWith('/apply')) return { ...completed, version: 6, state: 'applied' }
      return init?.method === 'POST' ? draft : { draft: draft }
    })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: userMessage.content } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await screen.findByRole('button', { name: 'Stop response' })
    const receive = vi.mocked(useTreeMergeEvents).mock.lastCall?.[2]
    await act(async () => { receive?.(preview) })
    const confirm = screen.getByRole('button', { name: 'Confirm and apply changes' })
    expect(confirm).toBeDisabled()
    expect(screen.queryByRole('article', { name: 'Assistant' })).not.toBeInTheDocument()
    expect(vi.mocked(request).mock.calls.some(([path]) => String(path).endsWith('/apply'))).toBe(false)
    await act(async () => { finish?.(completed) })
    expect(screen.getByRole('article', { name: 'Assistant' }).textContent).toBe(answer)
    expect(document.querySelector('.tree-merge-message b')).toBeNull()
    expect(confirm).toBeEnabled()
    fireEvent.click(confirm)
    await screen.findByText(i18n.t('treeMerge.applied'))
    expect(vi.mocked(request).mock.calls.filter(([path]) => String(path).endsWith('/apply'))).toHaveLength(1)
  })

  it('grows the composer to seven lines, shrinks it after editing and shows the allowance before a draft exists', async () => {
    const { request } = renderPanel(async () => ({ draft: null, tokenBudget: { remaining: 250, limit: 1000 } }))
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    expect(screen.getByRole('img', { name: '25% of tokens remaining' })).toBeInTheDocument()
    expect(input).toHaveStyle({ height: '60px', overflowY: 'hidden' })
    Object.defineProperty(input, 'scrollHeight', { configurable: true, value: 500 })
    fireEvent.change(input, { target: { value: 'line\n'.repeat(10) } })
    expect(input).toHaveStyle({ height: '180px' })
    expect(input).toHaveStyle({ overflowY: 'auto' })
    Object.defineProperty(input, 'scrollHeight', { configurable: true, get: () => {
      expect(input.style.overflowY).toBe('hidden')
      return 84
    } })
    fireEvent.change(input, { target: { value: 'short' } })
    expect(input).toHaveStyle({ height: '84px', overflowY: 'hidden' })
    expect(request).toHaveBeenCalledOnce()
  })

  it('creates an empty-tree draft, saves escaped messages and discards it', async () => {
    const sent = { ...draft, version: 2, messages: [{
      id: 'message', requestId: 'request', role: 'user' as const, content: '<script>family</script>', createdAt: '2026-11-03',
    }] }
    const { request, close } = renderPanel(async (_path, init) => {
      if (init?.method !== 'POST') return { draft: null }
      if (_path.endsWith('/process')) return sent
      return JSON.parse(String(init.body)).content ? sent : draft
    })
    const input = await screen.findByRole('textbox', { name: 'Family description or clarification' })
    await waitFor(() => expect(input).toHaveFocus())
    fireEvent.change(input, { target: { value: '<script>family</script>' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByText('<script>family</script>')).toBeInTheDocument()
    expect(input).toHaveValue('')
    expect(document.querySelector('script')).toBeNull()
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/messages', expect.objectContaining({ method: 'POST' }))
    fireEvent.keyDown(input, { key: 'Escape' })
    expect(close).toHaveBeenCalledOnce()
    fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }))
    expect(await screen.findByRole('textbox', { name: 'Family description or clarification' })).toBeEnabled()
  })

  it('sends with Ctrl+Enter, keeps Enter for newlines and prevents duplicate sends while busy', async () => {
    let finish!: (value: TreeMergeDraft) => void
    const { request } = renderPanel(async (path) => {
      if (path.endsWith('/messages')) return new Promise<TreeMergeDraft>((resolve) => { finish = resolve })
      if (path.endsWith('/process')) return draft
      return { draft: draft }
    })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    expect(screen.getByRole('button', { name: 'Send' })).toHaveAttribute('title', 'Send (Ctrl+Enter)')
    fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true })
    fireEvent.change(input, { target: { value: 'Vera is alive\nHer son is Ivan' } })
    expect(fireEvent.keyDown(input, { key: 'Enter' })).toBe(true)
    fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true, isComposing: true })
    expect(request).toHaveBeenCalledOnce()
    expect(fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true })).toBe(false)
    await waitFor(() => expect(request).toHaveBeenCalledTimes(2))
    expect(input).toBeDisabled()
    fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true })
    expect(request).toHaveBeenCalledTimes(2)
    expect(request).toHaveBeenLastCalledWith('/trees/tree/merges/merge/messages', expect.objectContaining({
      body: expect.stringContaining('Vera is alive\\nHer son is Ivan'),
    }))
    await act(async () => { finish({ ...draft, version: 2 }) })
    expect(input).toHaveValue('')
    expect(input).toBeEnabled()
  })

  it('shows only message content while keeping authors accessible and localizes the send hint', async () => {
    await i18n.changeLanguage('ru')
    renderPanel(async () => ({ draft: { ...draft, messages: [
      { id: 'user', requestId: 'request', role: 'user', content: 'Мама Ивана — Вера', createdAt: 'now' },
      { id: 'assistant', requestId: 'request', role: 'assistant', content: 'Ветка готова к проверке.', createdAt: 'now' },
    ] } }))
    const message = await screen.findByText('Мама Ивана — Вера')
    expect(message.closest('article')).toHaveAccessibleName(i18n.t('treeMerge.you'))
    expect(screen.getByText('Ветка готова к проверке.').closest('article')).toHaveAccessibleName(i18n.t('treeMerge.assistant'))
    expect(screen.queryByText(i18n.t('treeMerge.you'))).not.toBeInTheDocument()
    expect(screen.queryByText(i18n.t('treeMerge.assistant'))).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Отправить' })).toHaveAttribute('title', 'Отправить (Ctrl+Enter)')
  })

  it('keeps failed input and reuses the request id on retry', async () => {
    const requests: string[] = []
    let fail = true
    renderPanel(async (_path, init) => {
      if (init?.method !== 'POST') return { draft: draft }
      requests.push(String(init.body))
      if (fail) { fail = false; throw new Error('offline') }
      return { ...draft, version: 2 }
    })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: 'My mother is Vera' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save')
    expect(input).toHaveValue('My mother is Vera')
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(input).toHaveValue(''))
    expect(requests[0]).toBe(requests[1])
  })

  it('keeps unsent text only while the panel is mounted and removes legacy stored input', async () => {
    localStorage.setItem('tree-merge-input:merge', 'legacy transcript')
    const first = renderPanel(async (path) => path.endsWith('/merge') ? draft : { draft: draft })
    await waitFor(() => expect(screen.getByRole('textbox')).toBeEnabled())
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Unsaved clarification' } })
    expect(localStorage.getItem('tree-merge-input:merge')).toBeNull()
    first.unmount()
    renderPanel(async (path) => path.endsWith('/merge') ? draft : { draft: draft })
    await waitFor(() => expect(screen.getByRole('textbox')).toHaveValue(''))
  })

  it('accepts a named person in an existing tree without asking who I am', async () => {
    const person = {
      id: 'person', birthSurname: { state: 'unknown' as const }, givenName: { state: 'known' as const, value: 'Vera' },
      patronymic: { state: 'unknown' as const }, gender: 'female' as const, birthDate: { state: 'unknown' as const },
      deathDate: null, birthPlace: null, deathPlace: null, photoThumbnailUrl: null, createdAt: '2026-11-03', updatedAt: '2026-11-03',
    }
    const { request } = renderPanel(async (_path, init) => init?.method === 'POST' ? draft : { draft: null }, { ...snapshot, people: [person] })
    const start = screen.getByRole('button', { name: 'Send' })
    await waitFor(() => expect(screen.getByRole('textbox')).toBeEnabled())
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'My mother is Vera' } })
    expect(start).toBeEnabled()
    expect(screen.queryByRole('combobox')).toBeNull()
    fireEvent.click(start)
    await screen.findByRole('textbox')
    expect(request).toHaveBeenCalledWith('/trees/tree/merges', expect.objectContaining({ body: JSON.stringify({ referencePersonId: null }) }))
  })

  it('receives a saved answer through the stream without stealing input focus', async () => {
    const processing = { ...draft, chatState: 'processing' as const, attemptId: 'attempt', version: 3 }
    const answered = { ...draft, version: 4, messages: [{ id: 'answer', requestId: 'request', role: 'assistant' as const,
      content: 'Confirmed family facts', createdAt: '2026-11-03' }] }
    const { request } = renderPanel(async (path) => path.endsWith('/merge') ? answered : { draft: processing })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: 'Next clarification' } })
    act(() => vi.mocked(useTreeMergeEvents).mock.lastCall![2](answered))
    expect(await screen.findByText('Confirmed family facts')).toBeInTheDocument()
    expect(request).toHaveBeenCalledOnce()
    expect(input).toHaveValue('Next clarification')
  })

  it('shows a recoverable stream error and applies a streamed receipt only once', async () => {
    const reconnect = vi.fn()
    vi.mocked(useTreeMergeEvents).mockReturnValue({ error: 'treeMerge.streamDisconnected', reconnect })
    const { applied } = renderPanel(async () => ({ draft: draft }))
    await screen.findByRole('textbox')
    await waitFor(() => expect(vi.mocked(useTreeMergeEvents).mock.lastCall?.[0]).toBe('/trees/tree/merges/merge'))
    expect(screen.getByRole('status')).toHaveTextContent('Live updates are temporarily unavailable')
    fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }))
    expect(reconnect).toHaveBeenCalledOnce()
    const receive = vi.mocked(useTreeMergeEvents).mock.lastCall![3]
    const result = { personIds: {}, affectedPersonIds: [], etag: 'new', proposalVersion: 1 }
    await act(async () => {
      receive({ ...draft, id: 'other', result, state: 'applied' })
      receive({ ...draft, version: 0, result, state: 'applied' })
    })
    expect(applied).not.toHaveBeenCalled()
    await act(async () => {
      receive({ ...draft, version: 3, result, state: 'applied' })
      receive({ ...draft, version: 3, result, state: 'applied' })
    })
    expect(applied).toHaveBeenCalledOnce()
    vi.mocked(useTreeMergeEvents).mockReturnValue({ error: null, reconnect: vi.fn() })
  })

  it('does not replace a newer streamed answer with a delayed process response', async () => {
    let finish!: (value: TreeMergeDraft) => void
    renderPanel(async (path) => {
      if (path.endsWith('/messages')) return { ...draft, version: 2 }
      if (path.endsWith('/process')) return new Promise<TreeMergeDraft>((resolve) => { finish = resolve })
      return { draft: draft }
    })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: 'My family' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await screen.findByRole('button', { name: 'Stop response' })
    const latest: TreeMergeDraft = { ...draft, version: 5, messages: [{ id: 'answer', requestId: 'request', role: 'assistant',
      content: 'Newer streamed answer', createdAt: '2026-11-04' }] }
    act(() => vi.mocked(useTreeMergeEvents).mock.lastCall![2](latest))
    await act(async () => finish({ ...draft, version: 4 }))
    expect(screen.getByText('Newer streamed answer')).toBeInTheDocument()
  })

  it('replaces Send with Stop, keeps the chat and ignores a late answer after the next turn', async () => {
    const saved: TreeMergeDraft = { ...draft, version: 2, messages: [{ id: 'source', requestId: 'request', role: 'user',
      content: 'Vera is alive', createdAt: '2026-11-03' }] }
    const stopped: TreeMergeDraft = { ...saved, version: 4, messages: [...saved.messages,
      { id: 'stop', requestId: 'request', role: 'assistant', content: 'Stopped.', createdAt: '2026-11-03' }] }
    let finish!: (value: TreeMergeDraft) => void
    let signal: AbortSignal | null | undefined
    let requestId = ''
    let attempts = 0
    const { request } = renderPanel(async (path, init) => {
      if (path.endsWith('/messages')) return saved
      if (path.endsWith('/stop')) return stopped
      if (path.endsWith('/process')) {
        requestId = (JSON.parse(String(init?.body)) as { requestId: string }).requestId
        if (++attempts === 1) { signal = init?.signal; return new Promise<TreeMergeDraft>((resolve) => { finish = resolve }) }
        return { ...stopped, version: 8, messages: [...stopped.messages,
          { id: 'answer', requestId: 'second', role: 'assistant', content: 'Second response', createdAt: '2026-11-03' }] }
      }
      return { draft: draft }
    })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: 'Vera is alive' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Stop response' }))
    expect(await screen.findByText('Stopped.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Analyze description' })).not.toBeInTheDocument()
    expect(signal?.aborted).toBe(true)
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/stop', expect.objectContaining({ method: 'POST', body: JSON.stringify({ requestId }) }))
    fireEvent.change(input, { target: { value: 'Continue with Vera' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await screen.findByText('Second response')
    await act(async () => { finish({ ...saved, version: 3, state: 'ready' }) })
    expect(screen.getByText('Second response')).toBeInTheDocument()
    expect(input).toBeEnabled()
  })

  it('shows concrete assistant questions and sends the next reply without a separate analysis control', async () => {
    const questions: TreeMergeDraft = { ...draft, version: 4, state: 'questions', messages: [
      { id: 'source', requestId: 'request', role: 'user', content: 'My parents and four siblings', createdAt: '2026-11-03' },
      { id: 'question', requestId: 'request', role: 'assistant', content: 'Only ten new people fit. Shall we start with your parents?', createdAt: '2026-11-03' },
    ] }
    const { request } = renderPanel(async (path) => {
      if (path.endsWith('/messages')) return { ...questions, version: 5 }
      if (path.endsWith('/process')) throw new Error('offline')
      if (path.endsWith('/merge')) return { ...questions, chatState: 'error', version: 6 }
      return { draft: questions }
    })
    expect(await screen.findByText('Only ten new people fit. Shall we start with your parents?')).toBeInTheDocument()
    const input = screen.getByRole('textbox')
    fireEvent.change(input, { target: { value: 'Yes' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await screen.findByText('Could not save. Your message has been kept; try again.')
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/messages', expect.objectContaining({ body: expect.stringContaining('Yes') }))
    expect(screen.queryByRole('button', { name: 'Analyze description' })).not.toBeInTheDocument()
    expect(screen.getByText('Only ten new people fit. Shall we start with your parents?')).toBeInTheDocument()
  })

  it('can stop a restored attempt and preserves it when the stop request fails', async () => {
    const running: TreeMergeDraft = { ...draft, chatState: 'processing', version: 3, attemptId: 'attempt',
      attemptStartedAt: new Date().toISOString(), messages: [{ id: 'source', requestId: 'request',
        role: 'user', content: 'My mother is Vera', createdAt: '2026-11-03' }] }
    let stops = 0
    renderPanel(async (path) => {
      if (path.endsWith('/stop')) {
        if (++stops === 1) throw new Error('offline')
        return { ...running, version: 4, chatState: 'idle', state: 'collecting' }
      }
      return { draft: running }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Stop response' }))
    await screen.findByRole('alert')
    expect(screen.getByText('My mother is Vera')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Stop response' }))
    await screen.findByRole('button', { name: 'Send' })
    expect(screen.getByRole('textbox')).toBeEnabled()
  })
  it('reloads a conflicting draft without losing the unsaved message', async () => {
    let reads = 0
    renderPanel(async (_path, init) => {
      if (init?.method === 'POST') throw new ApiRequestError(409, { code: 'TREE_MERGE_VERSION_CONFLICT', message: null, fields: {}, details: {} })
      return reads++ === 0 ? { draft: draft } : { ...draft, version: 3 }
    })
    const input = await screen.findByRole('textbox')
    await waitFor(() => expect(input).toBeEnabled())
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
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Family' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save')
  })

  it('reports expired authorization and failed cancellation without dropping the draft', async () => {
    renderPanel(async (_path, init) => {
      if (init?.method === 'POST') throw new ApiRequestError(401, { code: 'UNAUTHORIZED', message: null, fields: {}, details: {} })
      return { draft: draft }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Discard draft' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('session has expired')
    expect(screen.getByRole('textbox')).toBeInTheDocument()
  })

  it('opens a preview link and applies the reviewed version with one explicit click', async () => {
    window.history.replaceState({}, '', '/?merge=merge')
    const result = { personIds: { p: 'person' }, affectedPersonIds: ['person'], etag: 'new-etag', proposalVersion: 2 }
    const { request, applied } = renderPanel(async (path) => {
      if (path.endsWith('/apply')) return { ...ready, version: 3, proposalVersion: 2, state: 'applied', result }
      if (path.endsWith('/cancel')) return { ...draft, version: 4, proposalVersion: 3 }
      return { ...ready, version: 2, proposalVersion: 2 }
    })
    const apply = await screen.findByRole('button', { name: 'Confirm and apply changes' })
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(applied).not.toHaveBeenCalled()
    fireEvent.click(apply)
    await waitFor(() => expect(applied).toHaveBeenCalledWith(result))
    expect(request).toHaveBeenCalledWith('/trees/tree/merges/merge/apply', expect.objectContaining({
      headers: { 'If-Match': 'etag' }, body: JSON.stringify({ version: 2, proposalVersion: 2 }),
    }))
    expect(screen.getByRole('textbox', { name: 'Family description or clarification' })).toBeEnabled()
    expect(vi.mocked(useTreeMergeEvents).mock.lastCall?.[0]).toBe('/trees/tree/merges/merge')
    act(() => vi.mocked(useTreeMergeEvents).mock.lastCall![2]({ ...ready, version: 4,
      state: 'applied', result, messages: [{ id: 'followup', requestId: 'next', role: 'assistant',
        content: 'The conversation continues in another tab.', createdAt: 'now' }] }))
    expect(await screen.findByText('The conversation continues in another tab.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Start another conversation' }))
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Family description or clarification' })).toBeEnabled())
    expect(window.location.search).toBe('?merge=merge')
  })

  it('fetches the updated proposal after a tree conflict and waits for another explicit apply', async () => {
    let attempts = 0
    const result = { personIds: { p: 'person' }, affectedPersonIds: ['person'], etag: 'applied-etag' }
    const { request, refreshTree, applied } = renderPanel(async (path, init) => {
      if (init?.method === 'POST') {
        if (++attempts === 1) throw new ApiRequestError(412, { code: 'VERSION_CONFLICT', message: null, fields: {}, details: {} })
        return { ...ready, state: 'applied', result }
      }
      return path.endsWith('/merge') ? { ...ready, version: 3, proposalVersion: 2, treeEtag: 'fresh-etag' } : { draft: ready }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm and apply changes' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('tree has changed')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirm and apply changes' })).toBeEnabled())
    expect(refreshTree).toHaveBeenCalledOnce()
    expect(attempts).toBe(1)
    expect(applied).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and apply changes' }))
    await waitFor(() => expect(applied).toHaveBeenCalledWith(result))
    expect(request).toHaveBeenLastCalledWith('/trees/tree/merges/merge/apply', expect.objectContaining({
      headers: { 'If-Match': 'fresh-etag' }, body: JSON.stringify({ version: 3, proposalVersion: 2 }),
    }))
  })

  it('retries the tree refresh after an applied receipt when the first snapshot request fails', async () => {
    const result = { personIds: { p: 'person' }, affectedPersonIds: ['person'], etag: 'new-etag', proposalVersion: 1 }
    const receipt = { ...ready, version: 3, state: 'applied' as const, result }
    const { applied } = renderPanel(async (path, init) => {
      if (path.endsWith('/apply') || (path.endsWith('/merge') && init?.method !== 'POST')) return receipt
      return { draft: ready }
    })
    applied.mockRejectedValueOnce(new Error('snapshot offline'))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm and apply changes' }))
    await screen.findByRole('alert')
    expect(applied).toHaveBeenCalledTimes(1)
    fireEvent.click(screen.getByRole('button', { name: 'Reload conversation' }))
    await waitFor(() => expect(applied).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
  })

  it('revalidates a conflict without applying and keeps chat corrections on validation failure', async () => {
    const { applied } = renderPanel(async (path, init) => {
      if (path.endsWith('/revalidate')) return ready
      if (init?.method === 'POST') throw new ApiRequestError(422, { code: 'INVALID', message: null, fields: {}, details: {} })
      return { draft: { ...ready, state: 'conflict' } }
    })
    expect(await screen.findByRole('button', { name: 'Confirm and apply changes' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Check against the current tree' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirm and apply changes' })).toBeEnabled())
    expect(applied).not.toHaveBeenCalled()
    const input = screen.getByRole('textbox', { name: 'Family description or clarification' })
    fireEvent.change(input, { target: { value: 'Her name is Veronika' } })
    fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true })
    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(input).toHaveValue('Her name is Veronika')
  })
})
