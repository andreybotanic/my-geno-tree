import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { ChatConversation, TreeMergeResult } from '../api/generated'
import { conversation, emptySnapshot, mergeProposal, readyConversation } from '../test/chatFixtures'
import i18n from '../i18n'
import { TreeChatPanel } from './TreeChatPanel'
import { mergeChatEvent, useChatEvents } from '../hooks/useChatEvents'

vi.mock(
  '../hooks/useChatEvents',
  async (original) => ({
    ...await original<typeof import('../hooks/useChatEvents')>(),
    useChatEvents: vi.fn(() => ({ error: null, reconnect: vi.fn() })),
  })
)
const result: TreeMergeResult = { personIds: { v: 'vera' }, affectedPersonIds: ['vera'], etag: 'new-etag' }
function setup(handler: (path: string, init?: RequestInit) => Promise<unknown>) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const applied = vi.fn(async () => {})
  const refresh = vi.fn(async () => {})
  const changed = vi.fn()
  render(<TreeChatPanel snapshot={emptySnapshot} request={request} onClose={vi.fn()} onApplied={applied} onRefreshTree={refresh} onProposalChange={changed} />)
  return { request, applied, refresh, changed }
}

describe(
  'tree profile and merge tool UI',
  () => {
    beforeEach(async () => { window.history.replaceState({}, '', '/'); vi.mocked(useChatEvents).mockClear(); await i18n.changeLanguage('en') })

    it.each([true, false])('refreshes direct changes with notification delivered=%s while retaining the draft and history', async (delivered) => {
      const { applied, refresh } = setup(async () => ({ conversation }))
      const input = await screen.findByRole('textbox', { name: 'Message' })
      await waitFor(() => expect(input).toBeEnabled())
      fireEvent.change(input, { target: { value: 'Keep my correction.' } })
      act(() => vi.mocked(useChatEvents).mock.lastCall?.[2](() => ({ ...conversation, version: 3, status: 'processing' })))
      if (delivered) {
        const event = { kind: 'tool' as const, id: conversation.id, version: 3, tool: 'change_tree', action: 'applied' as const, operationId: 'direct', result: { ...result, newPersonIds: [] } }
        await act(async () => vi.mocked(useChatEvents).mock.lastCall?.[3](event))
        await act(async () => vi.mocked(useChatEvents).mock.lastCall?.[3](event))
      }
      act(() => vi.mocked(useChatEvents).mock.lastCall?.[2](() => ({ ...conversation, version: 4, status: 'idle' })))
      await waitFor(() => expect(refresh).toHaveBeenCalledOnce())
      expect(applied).not.toHaveBeenCalled()
      expect(input).toHaveValue('Keep my correction.')
      expect(screen.queryByRole('button', { name: 'Apply' })).not.toBeInTheDocument()
    })

    it
      .each(
        ['success', 'failure'] as const
      )(
        'shows only the finished proposal, enables actions via SSE and ignores a late HTTP %s',
        async (lateResponse) => {
          let finish!: () => void
          const sent = { ...conversation, version: 2, messages: [{ id: 'user', requestId: 'first', role: 'user' as const, content: 'Add Vera.', createdAt: 'now' }] }
          const preview = { ...readyConversation, version: 4, status: 'processing' as const, messages: sent.messages }
          const { request, applied, changed } = setup(
            async (path, init) => {
              if (path.endsWith('/messages')) {
                sent.messages[0]!.requestId = (JSON.parse(init!.body as string) as { requestId: string }).requestId
                return new Promise<ChatConversation>(
                  (resolve, reject) => {
                    finish = () => lateResponse === 'success' ? resolve(preview) : reject(new Error('late network failure'))
                  }
                )
              }
              if (path.endsWith('/apply')) return { conversation: { ...sent, version: 7 }, operationId: mergeProposal.id, result }
              return init?.method ? conversation : { conversation }
            }
          )
          const input = await screen.findByRole('textbox', { name: 'Message' })
          await waitFor(() => expect(input).toBeEnabled())
          fireEvent.change(input, { target: { value: 'Add Vera.' } })
          fireEvent.submit(input.closest('form')!)
          expect(input).toHaveValue('')
          await waitFor(() => expect(finish).toBeDefined())
          act(() => vi.mocked(useChatEvents).mock.lastCall?.[2]((current) => mergeChatEvent(current, { kind: 'conversation', conversation: preview })))
          expect(input).toHaveValue('')
          expect(screen.queryByRole('region', { name: 'Review changes' })).not.toBeInTheDocument()
          expect(changed).toHaveBeenLastCalledWith(null)
          const completeProposal = {
            ...mergeProposal,
            version: 2,
            proposal: {
              ...mergeProposal.proposal,
              people: [
                ...mergeProposal.proposal.people,
                { ...mergeProposal.proposal.people[0]!, id: 'sofia', fields: { givenName: { state: 'known' as const, value: 'Sofia' }, gender: 'female' as const } },
              ],
            },
          }
          const answer = {
            ...preview,
            version: 6,
            status: 'idle' as const,
            tools: { merge: completeProposal },
            messages: [
              ...sent.messages,
              { id: 'answer', requestId: sent.messages[0]!.requestId, role: 'assistant' as const, content: '<b>Prepared.</b>', createdAt: 'now' },
            ],
          }
          act(() => vi.mocked(useChatEvents).mock.lastCall?.[2]((current) => mergeChatEvent(current, { kind: 'conversation', conversation: answer })))
          expect(await screen.findByRole('button', { name: 'Apply' })).toBeEnabled()
          expect(screen.getByRole('button', { name: 'Discard' }).parentElement).toBe(screen.getByRole('button', { name: 'Apply' }).parentElement)
          expect(changed).toHaveBeenLastCalledWith(completeProposal)
          expect(screen.queryByRole('button', { name: 'Stop response' })).not.toBeInTheDocument()
          expect(input).toBeEnabled()
          fireEvent.change(input, { target: { value: 'Keep this next message.' } })
          await act(async () => finish())
          expect(input).toHaveValue('Keep this next message.')
          expect(screen.queryByRole('alert')).not.toBeInTheDocument()
          expect(changed).toHaveBeenLastCalledWith(completeProposal)
          expect(screen.getByText('<b>Prepared.</b>').querySelector('b')).toBeNull()
          expect(applied).not.toHaveBeenCalled()
          fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
          await waitFor(() => expect(applied).toHaveBeenCalledWith(result))
          expect(request).toHaveBeenCalledWith('/trees/tree/merge-proposals/proposal/apply', expect.objectContaining({ body: JSON.stringify({ version: 2 }), headers: { 'If-Match': 'etag' } }))
          expect(screen.queryByRole('button', { name: 'Discard' })).not.toBeInTheDocument()
          expect(screen.queryByRole('button', { name: 'Apply' })).not.toBeInTheDocument()
          await waitFor(() => expect(changed).toHaveBeenLastCalledWith(null))
        }
      )

    it(
      'discards just the proposal and preserves the chat and unsent message',
      async () => {
        const messages = [{ id: 'user', requestId: 'first', role: 'user' as const, content: 'Vera.', createdAt: 'now' }]
        const { request } = setup(async (_path, init) => init?.method === 'DELETE' ? { ...conversation, version: 2, messages } : { conversation: { ...readyConversation, messages } })
        const input = await screen.findByRole('textbox', { name: 'Message' })
        await waitFor(() => expect(input).toBeEnabled())
        fireEvent.change(input, { target: { value: 'Keep this correction.' } })
        fireEvent.click(screen.getByRole('button', { name: 'Discard' }))
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Discard' })).not.toBeInTheDocument())
        expect(input).toHaveValue('Keep this correction.')
        expect(screen.getByText('Vera.')).toBeInTheDocument()
        expect(window.location.search).toBe('?chat=chat')
        expect(request).toHaveBeenCalledWith('/trees/tree/merge-proposals/proposal', expect.objectContaining({ method: 'DELETE' }))
      }
    )

    it(
      'deduplicates the transient SSE apply event and the HTTP response',
      async () => {
        let finish!: (value: unknown) => void
        const { applied } = setup(async (path) => path.endsWith('/apply') ? new Promise((resolve) => { finish = resolve }) : { conversation: readyConversation })
        fireEvent.click(await screen.findByRole('button', { name: 'Apply' }))
        await act(async () => vi.mocked(useChatEvents).mock.lastCall?.[3]({ kind: 'tool', id: conversation.id, version: 3, tool: 'merge', action: 'applied', operationId: mergeProposal.id, result }))
        await act(async () => finish({ conversation: { ...conversation, version: 3 }, operationId: mergeProposal.id, result }))
        expect(applied).toHaveBeenCalledOnce()
      }
    )

    it(
      'refreshes an ambiguous apply response without replaying the consumed proposal',
      async () => {
        const { request, refresh } = setup(
          async (path) => {
            if (path.endsWith('/apply')) throw new Error('lost response')
            if (path === '/chats/chat') return { ...conversation, version: 3 }
            return { conversation: readyConversation }
          }
        )
        fireEvent.click(await screen.findByRole('button', { name: 'Apply' }))
        await screen.findByRole('alert')
        await waitFor(() => expect(refresh).toHaveBeenCalledOnce())
        expect(screen.queryByRole('button', { name: 'Apply' })).not.toBeInTheDocument()
        expect(vi.mocked(request).mock.calls.filter(([path]) => path.endsWith('/apply'))).toHaveLength(1)
      }
    )

    it(
      'retries a failed local tree refresh using only the transient result in this tab',
      async () => {
        const { applied } = setup(
          async (path) => path.endsWith('/apply')
            ? { conversation: { ...conversation, version: 3 }, operationId: mergeProposal.id, result }
            : path === '/chats/chat' ? { ...conversation, version: 3 } : { conversation: readyConversation }
        )
        applied.mockRejectedValueOnce(new Error('snapshot offline'))
        fireEvent.click(await screen.findByRole('button', { name: 'Apply' }))
        await screen.findByRole('alert')
        fireEvent.click(screen.getByRole('button', { name: 'Reload conversation' }))
        await waitFor(() => expect(applied).toHaveBeenCalledTimes(2))
        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
      }
    )

    it(
      'revalidates a changed tree and requires another explicit confirmation',
      async () => {
        const conflict = { ...readyConversation, tools: { merge: { ...mergeProposal, state: 'conflict' } } }
        const { request, applied, refresh } = setup(
          async (path) => {
            if (path.endsWith('/revalidate')) return { ...readyConversation, version: 2, tools: { merge: { ...mergeProposal, version: 2 } } }
            return { conversation: conflict }
          }
        )
        fireEvent.click(await screen.findByRole('button', { name: 'Recheck' }))
        await waitFor(() => expect(refresh).toHaveBeenCalledOnce())
        expect(screen.getByRole('button', { name: 'Apply' })).toBeEnabled()
        expect(applied).not.toHaveBeenCalled()
        expect(request).toHaveBeenCalledWith('/trees/tree/merge-proposals/proposal/revalidate', expect.objectContaining({ method: 'POST' }))
      }
    )

    it
      .each(
        [409, 412]
      )(
        'reloads the preview after an HTTP %s conflict',
        async (status) => {
          const { refresh, applied } = setup(
            async (path) => {
              if (path.endsWith('/apply')) throw new ApiRequestError(status, { code: 'CONFLICT', message: null, fields: {}, details: {} })
              if (path === '/chats/chat') return { ...readyConversation, version: 3 }
              return { conversation: readyConversation }
            }
          )
          fireEvent.click(await screen.findByRole('button', { name: 'Apply' }))
          await screen.findByRole('alert')
          await waitFor(() => expect(refresh).toHaveBeenCalledOnce())
          expect(applied).not.toHaveBeenCalled()
          expect(screen.getByRole('button', { name: 'Apply' })).toBeEnabled()
        }
      )
  }
)
