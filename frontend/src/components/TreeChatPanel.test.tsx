import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
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
    expect(await screen.findByText('Vera')).toBeVisible()
    expect(screen.getByText('Awaiting confirmation')).toBeVisible()
    fireEvent.click(screen.getByRole('button', { name: decision === 'approve' ? 'Allow' : 'Reject' }))
    await waitFor(() => expect(screen.getByText(decision === 'approve' ? 'Applied' : 'Rejected')).toBeVisible())
    expect(request).toHaveBeenCalledWith('/chats/chat/confirmations/confirmation/decision', {
      method: 'POST', signal: expect.any(AbortSignal), body: JSON.stringify({ version: 1, decision }),
    })
    expect(screen.queryByRole('button', { name: 'Allow' })).not.toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Message' })).toBeEnabled()
  })

  it('can approve the exact action and enable per-chat automatic confirmation', async () => {
    const { request } = setup(async (path) => path.endsWith('/decision')
      ? { ...conversation, version: 2, autoApprovePersonActions: true }
      : { conversation: readyConversation })
    fireEvent.click(await screen.findByRole('button', { name: 'Allow and auto-confirm in this chat' }))
    await waitFor(() => expect(screen.getByRole('checkbox')).toBeChecked())
    expect(request).toHaveBeenCalledWith('/chats/chat/confirmations/confirmation/decision', {
      method: 'POST', signal: expect.any(AbortSignal), body: JSON.stringify({ version: 1, decision: 'approve', autoApprovePersonActions: true }),
    })
    fireEvent.click(screen.getByRole('checkbox'))
    await waitFor(() => expect(request).toHaveBeenCalledWith('/chats/chat/permissions', {
      method: 'PATCH', body: JSON.stringify({ version: 2, autoApprovePersonActions: false }),
    }))
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
    expect(await screen.findByText('Vera')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Разрешить' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Отклонить' })).toBeVisible()
  })

  it.each([
    ['parent', 'New parent (Father) of: Élodie'],
    ['child', 'New child of: Élodie (Father)'],
    ['spouse', 'New spouse of: Élodie'],
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
