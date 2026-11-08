import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import { conversation, emptySnapshot, readyConversation } from '../test/chatFixtures'
import i18n from '../i18n'
import { TreeChatPanel } from './TreeChatPanel'
import { useChatEvents } from '../hooks/useChatEvents'

vi.mock('../hooks/useChatEvents', async (original) => ({
  ...await original<typeof import('../hooks/useChatEvents')>(),
  useChatEvents: vi.fn(() => ({ error: null, reconnect: vi.fn() })),
}))

function setup(handler: (path: string, init?: RequestInit) => Promise<unknown>) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const applied = vi.fn(async () => {})
  const refresh = vi.fn(async () => {})
  render(<TreeChatPanel snapshot={emptySnapshot} request={request} onClose={vi.fn()}
    onApplied={applied} onRefreshTree={refresh} />)
  return { request, applied, refresh }
}

describe('tree action confirmations', () => {
  beforeEach(async () => {
    window.history.replaceState({}, '', '/')
    vi.mocked(useChatEvents).mockClear()
    await i18n.changeLanguage('en')
  })

  it.each(['approve', 'reject'] as const)('restores a pending action and submits %s without changing its arguments', async (decision) => {
    const { request } = setup(async (path) => path.endsWith('/decision')
      ? { ...readyConversation, version: 2, status: 'idle',
          confirmations: [{ ...readyConversation.confirmations[0]!, status: decision === 'approve' ? 'applied' : 'rejected' }] }
      : { conversation: readyConversation })
    expect(await screen.findByText('Create “Vera”?')).toBeVisible()
    const prompt = screen.getByRole('group', { name: 'Action confirmation' })
    expect(screen.getByRole('log')).not.toContainElement(prompt)
    expect(prompt.nextElementSibling).toHaveClass('chat-composer')
    fireEvent.click(screen.getByRole('button', { name: decision === 'approve' ? 'Allow' : 'Reject' }))
    await waitFor(() => expect(screen.queryByRole('group', { name: 'Action confirmation' })).not.toBeInTheDocument())
    expect(request).toHaveBeenCalledWith('/chats/chat/confirmations/confirmation/decision', {
      method: 'POST', signal: expect.any(AbortSignal), body: JSON.stringify({ version: 1, decision }),
    })
    expect(screen.queryByRole('button', { name: 'Allow' })).not.toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Message' })).toBeEnabled()
  })

  it('selects per-chat permissions from an icon menu below the composer', async () => {
    const { request } = setup(async (path, init) => path.endsWith('/permissions')
      ? { ...readyConversation, version: 2, autoApprovePersonActions: JSON.parse(String(init?.body)).autoApprovePersonActions }
      : { conversation: readyConversation })
    const trigger = await screen.findByRole('button', { name: 'Ask for permission' })
    expect(trigger.closest('form')?.querySelector('.chat-input')?.compareDocumentPosition(trigger))
      .toBe(Node.DOCUMENT_POSITION_FOLLOWING)
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    fireEvent.click(trigger)
    const menu = screen.getByRole('menu', { name: 'Person actions' })
    const option = within(menu).getByRole('menuitemradio', { name: 'Approve for me' })
    expect(option.querySelector('svg')).toBeInTheDocument()
    expect(within(menu).getByRole('menuitemradio', { name: 'Ask for permission' })).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(option)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Approve for me' })).toBeEnabled())
    expect(request).toHaveBeenCalledWith('/chats/chat/permissions', {
      method: 'PATCH', body: JSON.stringify({ version: 1, autoApprovePersonActions: true }),
    })
    fireEvent.click(screen.getByRole('button', { name: 'Approve for me' }))
    fireEvent.click(screen.getByRole('menuitemradio', { name: 'Ask for permission' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('/chats/chat/permissions', {
      method: 'PATCH', body: JSON.stringify({ version: 2, autoApprovePersonActions: false }),
    }))
  })

  it('can set permissions before sending the first message', async () => {
    const { request } = setup(async (path, init) => path.endsWith('/permissions')
      ? { ...conversation, version: 2, autoApprovePersonActions: true }
      : init?.method === 'POST' ? conversation : { conversation: null })
    const trigger = await screen.findByRole('button', { name: 'Ask for permission' })
    await waitFor(() => expect(trigger).toBeEnabled())
    fireEvent.click(trigger)
    fireEvent.click(screen.getByRole('menuitemradio', { name: 'Approve for me' }))
    await screen.findByRole('button', { name: 'Approve for me' })
    expect(request).toHaveBeenCalledWith('/trees/tree/chat', { method: 'POST', body: '{"referencePersonId":null}' })
  })

  it('supports keyboard navigation, Escape and closing the permission menu outside', async () => {
    setup(async () => ({ conversation }))
    const trigger = await screen.findByRole('button', { name: 'Ask for permission' })
    fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    const ask = screen.getByRole('menuitemradio', { name: 'Ask for permission' })
    const auto = screen.getByRole('menuitemradio', { name: 'Approve for me' })
    expect(ask).toHaveFocus()
    fireEvent.keyDown(ask, { key: 'ArrowDown' })
    expect(auto).toHaveFocus()
    fireEvent.keyDown(auto, { key: 'Home' })
    expect(ask).toHaveFocus()
    fireEvent.keyDown(ask, { key: 'End' })
    expect(auto).toHaveFocus()
    fireEvent.keyDown(auto, { key: 'Escape' })
    expect(trigger).toHaveFocus()
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    fireEvent.click(trigger)
    fireEvent.pointerDown(document.body)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('uses the existing circular Stop button to cancel a pending action', async () => {
    const { request } = setup(async (path) => path.endsWith('/stop') ? conversation : { conversation: readyConversation })
    const stop = await screen.findByRole('button', { name: 'Stop' })
    expect(screen.getAllByRole('button', { name: 'Stop' })).toHaveLength(1)
    expect(stop).toHaveClass('chat-send')
    fireEvent.click(stop)
    await waitFor(() => expect(screen.queryByRole('group', { name: 'Action confirmation' })).not.toBeInTheDocument())
    expect(request).toHaveBeenCalledWith('/chats/chat/stop', { method: 'POST', body: '{"requestId":"request"}' })
  })

  it('allows a new clarification while waiting and removes the old permission request', async () => {
    const { request } = setup(async (path) => path.endsWith('/messages')
      ? { ...conversation, confirmations: [{ ...readyConversation.confirmations[0]!, status: 'cancelled' }] }
      : { conversation: readyConversation })
    const input = await screen.findByRole('textbox', { name: 'Message' })
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: 'Use the original spelling.' } })
    expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(screen.queryByRole('group', { name: 'Action confirmation' })).not.toBeInTheDocument())
    const call = vi.mocked(request).mock.calls.find(([path]) => String(path).endsWith('/messages'))
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ content: 'Use the original spelling.', version: 1 })
  })

  it('describes the unnamed parent and hides resolved confirmations from the conversation', async () => {
    setup(async () => ({ conversation: { ...readyConversation, confirmations: [
      { ...readyConversation.confirmations[0]!, id: 'old', status: 'applied' },
      { ...readyConversation.confirmations[0]!, summary: { ...readyConversation.confirmations[0]!.summary, createsUnknownParent: true } },
    ] } }))
    const prompt = await screen.findByRole('group', { name: 'Action confirmation' })
    expect(prompt).toHaveTextContent('Also create an unnamed parent.')
    expect(screen.getAllByRole('group', { name: 'Action confirmation' })).toHaveLength(1)
    expect(screen.getByRole('log')).not.toHaveTextContent('Create “Vera”?')
  })

  it('recovers a lost decision response by reading the chat without replaying the mutation', async () => {
    let loaded = false
    const { request } = setup(async (path) => {
      if (path.endsWith('/decision')) throw new ApiRequestError(409, { code: 'CHAT_VERSION_CONFLICT', fields: {}, details: {}, message: null })
      if (path === '/chats/chat') { loaded = true; return conversation }
      return { conversation: readyConversation }
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Allow' }))
    await waitFor(() => expect(loaded).toBe(true))
    expect(vi.mocked(request).mock.calls.filter(([path]) => String(path).endsWith('/decision'))).toHaveLength(1)
  })

  it('refreshes each committed action once while preserving the composer draft', async () => {
    const { applied } = setup(async () => ({ conversation }))
    const input = await screen.findByRole('textbox', { name: 'Message' })
    await waitFor(() => expect(input).toBeEnabled())
    fireEvent.change(input, { target: { value: 'Keep this correction.' } })
    const event = { kind: 'tool' as const, id: conversation.id, version: 2, tool: 'create_person',
      action: 'applied', operationId: 'call_create', result: { personId: 'vera', etag: 'new' } }
    await act(async () => vi.mocked(useChatEvents).mock.lastCall?.[3](event))
    await act(async () => vi.mocked(useChatEvents).mock.lastCall?.[3](event))
    expect(applied).toHaveBeenCalledOnce()
    expect(input).toHaveValue('Keep this correction.')
    expect(screen.queryByRole('button', { name: 'Apply' })).not.toBeInTheDocument()
  })


  it('refreshes after a missed mutation notification and offers a retry when refresh fails', async () => {
    const { refresh } = setup(async () => ({ conversation }))
    await screen.findByRole('textbox', { name: 'Message' })
    act(() => vi.mocked(useChatEvents).mock.lastCall?.[2]({ ...conversation, version: 2, status: 'processing' }))
    refresh.mockRejectedValueOnce(new Error('offline'))
    await act(async () => vi.mocked(useChatEvents).mock.lastCall?.[2]({ ...conversation, version: 3, status: 'idle' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded')
    fireEvent.click(screen.getByRole('button', { name: 'Reload conversation' }))
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('localizes confirmation controls in Russian without translating a name', async () => {
    await i18n.changeLanguage('ru')
    setup(async () => ({ conversation: readyConversation }))
    expect(await screen.findByText('Создать персону «Vera»?')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Запрашивать разрешение' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Разрешить' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Отклонить' })).toBeVisible()
  })

  it.each([
    ['parent', 'Create “Unknown person” as a parent of “Élodie”?'],
    ['child', 'Create “Unknown person” as a child of “Élodie”?'],
    ['spouse', 'Create “Unknown person” as a spouse of “Élodie”?'],
  ])('shows the %s connection before approving creation', async (type, label) => {
    const relative = { id: 'relative', birthSurname: { state: 'unknown' as const },
      givenName: { state: 'known' as const, value: 'Élodie' }, patronymic: { state: 'unknown' as const },
      gender: 'female' as const, birthDate: { state: 'unknown' as const }, deathDate: null,
      birthPlace: null, deathPlace: null, photoThumbnailUrl: null, createdAt: 'now', updatedAt: 'now' }
    setup(async () => ({ conversation: { ...readyConversation, confirmations: [{ ...readyConversation.confirmations[0]!,
      summary: { relative, connection: { type, role: 'father', personId: 'relative' } } }] } }))
    expect(await screen.findByText(label)).toBeVisible()
  })
})
