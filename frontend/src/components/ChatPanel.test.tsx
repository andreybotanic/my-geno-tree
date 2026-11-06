import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestError, type AuthorizedJsonRequest } from '../api/client'
import type { ChatConversation } from '../api/generated'
import { conversation } from '../test/chatFixtures'
import i18n from '../i18n'
import { ChatPanel } from './ChatPanel'
import { useChatEvents } from '../hooks/useChatEvents'

vi.mock(
  '../hooks/useChatEvents',
  async (original) => ({
    ...await original<typeof import('../hooks/useChatEvents')>(),
    useChatEvents: vi.fn(() => ({ error: null, reconnect: vi.fn() })),
  })
)

function setup(handler: (path: string, init?: RequestInit) => Promise<unknown>) {
  const request = vi.fn(handler) as unknown as AuthorizedJsonRequest
  const close = vi.fn()
  const changed = vi.fn()
  const view = render(<ChatPanel contextPath="/example/chat" createBody={{ context: 'example' }} request={request} onClose={close} onConversationChange={changed} />)
  return { request, close, changed, ...view }
}
const user = { id: 'user', requestId: 'first', role: 'user' as const, content: '<b>Hello</b>', createdAt: 'now' }
const sent: ChatConversation = { ...conversation, version: 2, messages: [user] }
const completed: ChatConversation = { ...sent, version: 4, messages: [
    user,
    { id: 'answer', requestId: 'first', role: 'assistant', content: 'An ordinary answer.', createdAt: 'now' },
  ] }

describe(
  'reusable ChatPanel',
  () => {
    beforeEach(async () => { window.history.replaceState({}, '', '/'); vi.mocked(useChatEvents).mockClear(); await i18n.changeLanguage('en') })

    it(
      'creates the selected context, saves plain text, renders a normal answer and resets the chat',
      async () => {
        const { request, changed, close } = setup(
          async (path, init) => {
            if (path === '/example/chat') return init?.method ? conversation : { conversation: null }
            if (path.endsWith('/messages')) return completed
            if (path.endsWith('/reset')) return undefined
            return completed
          }
        )
        const input = await screen.findByRole('textbox', { name: 'Message' })
        await waitFor(() => expect(input).toBeEnabled())
        fireEvent.change(input, { target: { value: user.content } })
        fireEvent.submit(input.closest('form')!)
        expect(await screen.findByText('An ordinary answer.')).toBeInTheDocument()
        expect(screen.getByText(user.content).querySelector('b')).toBeNull()
        expect(request).toHaveBeenCalledWith('/example/chat', expect.objectContaining({ body: JSON.stringify({ context: 'example' }) }))
        expect(request).toHaveBeenCalledWith('/chats/chat/messages', expect.objectContaining({ method: 'POST' }))
        expect(window.location.search).toBe('?chat=chat')
        fireEvent.click(screen.getByRole('button', { name: 'Start another conversation' }))
        await waitFor(() => expect(changed).toHaveBeenLastCalledWith(null))
        expect(window.location.search).toBe('')
        fireEvent.keyDown(screen.getByRole('complementary'), { key: 'Escape' })
        expect(close).toHaveBeenCalledOnce()
      }
    )

    it(
      'keeps failed text and reuses its request identifier on retry, with Ctrl+Enter and duplicate protection',
      async () => {
        let attempts = 0
        let finish!: (value: ChatConversation) => void
        const { request } = setup(
          async (path) => {
            if (path.endsWith('/messages')) {
              if (++attempts === 1) throw new Error('offline')
              return new Promise<ChatConversation>((resolve) => { finish = resolve })
            }
            if (path.endsWith('/messages')) return completed
            return { conversation }
          }
        )
        const input = await screen.findByRole('textbox', { name: 'Message' })
        await waitFor(() => expect(input).toBeEnabled())
        fireEvent.change(input, { target: { value: 'Hello' } })
        fireEvent.keyDown(input, { key: 'Enter' })
        expect(request).toHaveBeenCalledOnce()
        fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true })
        await screen.findByRole('alert')
        expect(input).toHaveValue('Hello')
        fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true })
        fireEvent.submit(input.closest('form')!)
        await waitFor(() => expect(attempts).toBe(2))
        const calls = vi.mocked(request).mock.calls.filter(([path]) => path.endsWith('/messages'))
        expect(calls[0]?.[1]?.body).toBe(calls[1]?.[1]?.body)
        await act(async () => finish(completed))
        await screen.findByText('An ordinary answer.')
      }
    )

    it(
      'keeps acknowledged input cleared when HTTP delivery fails during a streamed turn',
      async () => {
        let fail!: () => void
        let requestId = ''
        setup(
          async (path, init) => {
            if (path.endsWith('/messages')) {
              requestId = (JSON.parse(init!.body as string) as { requestId: string }).requestId
              return new Promise<ChatConversation>((_resolve, reject) => { fail = () => reject(new Error('connection interrupted')) })
            }
            if (path === '/chats/chat') throw new Error('state temporarily unavailable')
            return { conversation }
          }
        )
        const input = await screen.findByRole('textbox', { name: 'Message' })
        await waitFor(() => expect(input).toBeEnabled())
        fireEvent.change(input, { target: { value: 'Hello' } })
        fireEvent.submit(input.closest('form')!)
        expect(input).toHaveValue('')
        await waitFor(() => expect(fail).toBeDefined())
        const running = { ...sent, status: 'processing' as const, messages: [{ ...user, requestId, content: 'Hello' }] }
        act(() => vi.mocked(useChatEvents).mock.lastCall?.[2](running))
        await act(async () => fail())
        expect(input).toHaveValue('')
        expect(screen.getByRole('button', { name: 'Stop response' })).toBeEnabled()
        const finished = {
          ...running,
          version: 4,
          status: 'idle' as const,
          messages: [
            ...running.messages,
            { id: 'answer', requestId, role: 'assistant' as const, content: 'Done.', createdAt: 'now' },
          ],
        }
        act(() => vi.mocked(useChatEvents).mock.lastCall?.[2](finished))
        expect(input).toHaveValue('')
        expect(input).toBeEnabled()
        expect(screen.queryByRole('alert')).not.toBeInTheDocument()
        expect(screen.queryByRole('button', { name: 'Stop response' })).not.toBeInTheDocument()
      }
    )

    it(
      'stops processing, aborts the browser request and ignores its late answer',
      async () => {
        let finish!: (value: ChatConversation) => void
        const stopped: ChatConversation = { ...sent, version: 4, messages: [
            user,
            { id: 'notice', requestId: 'first', role: 'system', content: '', failureCode: 'stopped', createdAt: 'now' },
          ] }
        const { request } = setup(
          async (path) => {
            if (path.endsWith('/messages')) return new Promise<ChatConversation>((resolve) => { finish = resolve })
            if (path.endsWith('/stop')) return stopped
            return { conversation }
          }
        )
        const input = await screen.findByRole('textbox', { name: 'Message' })
        await waitFor(() => expect(input).toBeEnabled())
        fireEvent.change(input, { target: { value: 'Hello' } })
        fireEvent.submit(input.closest('form')!)
        fireEvent.click(await screen.findByRole('button', { name: 'Stop response' }))
        await screen.findByText('Processing stopped.')
        expect(vi.mocked(request).mock.calls.find(([path]) => path.endsWith('/messages'))?.[1]?.signal?.aborted).toBe(true)
        await act(async () => finish(completed))
        expect(screen.queryByText('An ordinary answer.')).not.toBeInTheDocument()
      }
    )

    it(
      'restores a running turn and stops it without a local send request',
      async () => {
        const running = { ...sent, status: 'processing' as const, attemptStartedAt: '2026-11-05T10:00:00Z' }
        const { request } = setup(async (path) => path.endsWith('/stop') ? sent : { conversation: running })
        fireEvent.click(await screen.findByRole('button', { name: 'Stop response' }))
        await waitFor(() => expect(request).toHaveBeenCalledWith('/chats/chat/stop', expect.objectContaining({ body: JSON.stringify({ requestId: 'first' }) })))
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Stop response' })).not.toBeInTheDocument())
      }
    )

    it
      .each(
        [401, 409, 422, 500, 404]
      )(
        'recovers an HTTP %s send failure by reading current state',
        async (status) => {
          const { request } = setup(
            async (path) => {
              if (path.endsWith('/messages')) throw new ApiRequestError(status, { code: status === 404 ? 'CHAT_NOT_FOUND' : 'ERROR', message: null, fields: {}, details: {} })
              if (path === '/chats/chat') return sent
              return { conversation }
            }
          )
          const input = await screen.findByRole('textbox', { name: 'Message' })
          await waitFor(() => expect(input).toBeEnabled())
          fireEvent.change(input, { target: { value: 'Hello' } })
          fireEvent.submit(input.closest('form')!)
          await waitFor(() => expect(request).toHaveBeenCalledWith('/chats/chat'))
          if (status !== 404) {
            await screen.findByRole('alert')
            fireEvent.click(screen.getByRole('button', { name: 'Reload conversation' }))
            await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
          }
        }
      )

    it(
      'shows load failure, streamed disconnect and system notices separately from assistant content',
      async () => {
        const reconnect = vi.fn()
        vi.mocked(useChatEvents).mockReturnValueOnce({ error: 'chat.streamDisconnected', reconnect })
        const view = setup(async () => { throw new Error('offline') })
        await screen.findByRole('alert')
        view.unmount()
        vi.mocked(useChatEvents).mockReturnValue({ error: 'chat.streamDisconnected', reconnect })
        setup(
          async () => ({ conversation: { ...completed, messages: [
                ...completed.messages,
                { id: 'failure', requestId: 'second', role: 'system', content: '', failureCode: 'provider_unavailable', createdAt: 'now' },
              ] } })
        )
        expect(await screen.findByRole('article', { name: 'Assistant' })).toHaveTextContent('An ordinary answer.')
        expect(screen.getByText('The AI service is unavailable; please try again shortly.')).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }))
        expect(reconnect).toHaveBeenCalledOnce()
        vi.mocked(useChatEvents).mockReturnValue({ error: null, reconnect: vi.fn() })
      }
    )

    it(
      'resizes the composer and shows the allowance before a conversation exists',
      async () => {
        setup(async () => ({ conversation: null, tokenBudget: { remaining: 250, limit: 1000 } }))
        const input = await screen.findByRole('textbox', { name: 'Message' })
        await waitFor(() => expect(input).toBeEnabled())
        Object.defineProperty(input, 'scrollHeight', { configurable: true, value: 800 })
        fireEvent.change(input, { target: { value: 'Many\nlines' } })
        expect(input.style.overflowY).toBe('auto')
        Object.defineProperty(input, 'scrollHeight', { configurable: true, value: 40 })
        fireEvent.change(input, { target: { value: 'Short' } })
        expect(input.style.overflowY).toBe('hidden')
        expect(screen.getByRole('img')).toHaveAccessibleName('25% of tokens remaining')
      }
    )
  }
)
