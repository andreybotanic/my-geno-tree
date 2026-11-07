import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
    afterEach(() => vi.useRealTimers())

    it('renders assistant Markdown with nested lists, tables, code and safe links', async () => {
      const markdown = [
        '## Family',
        '',
        '**Vera** is *Anna’s* mother.',
        '',
        '- Parents',
        '  - Vera',
        '',
        '1. Review the proposal',
        '2. Confirm it',
        '',
        '> Dates are approximate.',
        '',
        'Use `unknown` for missing dates.',
        '',
        '```text',
        'Vera -> Anna',
        '```',
        '',
        '| Person | Year |',
        '| --- | --- |',
        '| Vera | 1970 |',
        '',
        '[Source](https://example.com/family)',
      ].join('\n')
      const next: ChatConversation = { ...completed, messages: [
        { ...user, content: '**Keep my text literal** <b>Vera</b>' },
        { ...completed.messages[1]!, content: markdown },
      ] }
      setup(async () => ({ conversation: next }))
      const answer = within(await screen.findByRole('article', { name: 'Assistant' }))
      expect(answer.getByRole('heading', { name: 'Family', level: 2 })).toBeInTheDocument()
      expect(answer.getByText('Vera', { selector: 'strong' })).toBeInTheDocument()
      expect(answer.getByText('Anna’s', { selector: 'em' })).toBeInTheDocument()
      expect(answer.getAllByRole('list')).toHaveLength(3)
      expect(answer.getByRole('table')).toHaveTextContent('Vera1970')
      expect(answer.getByText('Vera -> Anna', { selector: 'pre code' })).toBeInTheDocument()
      expect(answer.getByText('unknown', { selector: 'code' })).toBeInTheDocument()
      expect(answer.getByText('Dates are approximate.').closest('blockquote')).not.toBeNull()
      expect(answer.getByRole('link', { name: 'Source' })).toHaveAttribute('href', 'https://example.com/family')
      const ownMessage = screen.getByRole('article', { name: 'You' })
      expect(ownMessage).toHaveTextContent('**Keep my text literal** <b>Vera</b>')
      expect(ownMessage.querySelector('strong, b')).toBeNull()
    })

    it('escapes raw HTML and removes executable URLs from assistant Markdown', async () => {
      const html = '<img src=x onerror="alert(1)"><script>alert(1)</script>'
      const next: ChatConversation = { ...completed, messages: [
        { ...completed.messages[1]!, content: `${html}\n\n[Unsafe](javascript:alert%281%29)` },
      ] }
      setup(async () => ({ conversation: next }))
      const answer = await screen.findByRole('article', { name: 'Assistant' })
      expect(answer).toHaveTextContent(html)
      expect(answer.querySelector('img, script')).toBeNull()
      expect(within(answer).getByText('Unsafe')).toHaveAttribute('href', '')
    })

    it('shows only the last completed tool from restored and live progress', async () => {
      const running: ChatConversation = { ...conversation, status: 'processing', progress: ['get_tree_context'] }
      setup(async () => ({ conversation: running }))
      expect(await screen.findByRole('status')).toHaveTextContent('Completed: get_tree_context')
      expect(screen.queryByRole('list')).not.toBeInTheDocument()
      act(() => vi.mocked(useChatEvents).mock.lastCall?.[2]({
        ...running, version: 2, progress: ['get_tree_context', 'merge'],
      }))
      expect(screen.getByRole('status')).toHaveTextContent('Completed: merge')
      expect(screen.queryByRole('list')).not.toBeInTheDocument()
      expect(screen.queryByText('Completed: get_tree_context')).not.toBeInTheDocument()
    })

    it('shows each message time below its text, with a full localized date in the tooltip', async () => {
      const times = ['2026-11-07T05:01:00Z', '2026-11-07T05:02:00Z', '2026-11-07T05:03:00Z']
      const next: ChatConversation = { ...completed, messages: [
        { ...user, createdAt: times[0]! },
        { ...completed.messages[1]!, createdAt: times[1]! },
        { ...user, id: 'notice', role: 'system', content: '', failureCode: 'stopped', createdAt: times[2]! },
      ] }
      setup(async () => ({ conversation: next }))
      await screen.findByRole('article', { name: 'Assistant' })
      for (const [index, label] of ['You', 'Assistant', 'Notice'].entries()) {
        const time = screen.getByRole('article', { name: label }).querySelector('time')!
        const timestamp = times[index]!
        expect(time).toHaveAttribute('datetime', timestamp)
        expect(time).toHaveTextContent(new Date(timestamp).toLocaleTimeString('en',
          { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }))
        expect(time).toHaveAttribute('title', new Date(timestamp).toLocaleString('en'))
        expect(time.previousElementSibling).toHaveClass('chat-message-body')
      }
      await act(async () => i18n.changeLanguage('ru'))
      const notice = screen.getByRole('article', { name: 'Уведомление' }).querySelector('time')!
      expect(notice).toHaveAttribute('title', new Date(times[2]!).toLocaleString('ru'))
    })

    it('keeps banned history and prepared tools readable while disabling text and voice', async () => {
      const banned: ChatConversation = { ...completed, access: {
        ...conversation.access, status: 'permanent_ban', banCount: 3,
      } }
      const request = vi.fn(async () => ({ conversation: banned, tokenBudget: banned.tokenBudget, access: banned.access })) as unknown as AuthorizedJsonRequest
      const apply = vi.fn()
      render(<ChatPanel contextPath="/example/chat" createBody={{}} request={request} onClose={vi.fn()}
        renderTools={(_state, busy) => <button disabled={busy} onClick={apply}>Apply prepared proposal</button>} />)
      expect(await screen.findByText('An ordinary answer.')).toBeInTheDocument()
      expect(screen.getByRole('textbox', { name: 'Message' })).toBeDisabled()
      expect(screen.getByRole('button', { name: 'Record voice' })).toBeDisabled()
      expect(screen.getByRole('alert')).toHaveTextContent(/permanently/i)
      fireEvent.click(screen.getByRole('button', { name: 'Apply prepared proposal' }))
      expect(apply).toHaveBeenCalledOnce()
    })

    it('waits for ban expiry without polling or focus requests, then restores sending once', async () => {
      vi.useFakeTimers()
      const banned = { ...conversation.access, status: 'temporary_ban' as const,
        bannedUntil: '2026-11-06T11:00:00Z', serverTime: '2026-11-06T10:00:00Z' }
      let access = banned as ChatConversation['access']
      const { request } = setup(async () => ({ conversation: null, tokenBudget: conversation.tokenBudget, access }))
      await act(async () => {})
      const input = screen.getByRole('textbox', { name: 'Message' })
      expect(input).toBeDisabled()
      expect(screen.getByRole('alert')).toHaveTextContent(/until/i)
      fireEvent(window, new Event('focus'))
      await act(async () => { await vi.advanceTimersByTimeAsync(3600000) })
      expect(request).toHaveBeenCalledOnce()
      access = { ...banned, status: 'allowed', serverTime: '2026-11-06T11:00:01Z' }
      await act(async () => { await vi.advanceTimersByTimeAsync(100) })
      expect(input).toBeEnabled()
      expect(request).toHaveBeenCalledTimes(2)
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
      await act(async () => { await vi.advanceTimersByTimeAsync(3600000) })
      expect(request).toHaveBeenCalledTimes(2)
    })

    it('automatically refreshes only after the known AI outage deadline', async () => {
      vi.useFakeTimers()
      const unavailable = { ...conversation.access, status: 'unavailable' as const,
        serverTime: '2026-11-06T10:00:00Z', unavailableUntil: '2026-11-06T10:00:30Z' }
      let access = unavailable as ChatConversation['access']
      const { request } = setup(async () => ({ conversation: null, tokenBudget: conversation.tokenBudget, access }))
      await act(async () => {})
      expect(screen.getByRole('textbox')).toBeDisabled()
      await act(async () => { await vi.advanceTimersByTimeAsync(15000) })
      expect(request).toHaveBeenCalledOnce()
      access = { ...unavailable, status: 'allowed', serverTime: '2026-11-06T10:00:31Z' }
      await act(async () => { await vi.advanceTimersByTimeAsync(15100) })
      expect(screen.getByRole('textbox')).toBeEnabled()
    })

    it('renders warning numbers as localized notices and accepts a Mercure ban', async () => {
      setup(async () => ({ conversation, access: conversation.access, tokenBudget: conversation.tokenBudget }))
      await waitFor(() => expect(screen.getByRole('textbox')).toBeEnabled())
      const blocked: ChatConversation = { ...conversation, version: 20, access: {
        ...conversation.access, status: 'permanent_ban', banCount: 3,
        serverTime: '2099-11-06T10:00:00Z',
      }, messages: [
        { ...user, blocked: true },
        { id: 'warning', requestId: 'first', role: 'system', content: '', failureCode: 'policy_warning', warningNumber: 3, createdAt: 'now' },
      ] }
      act(() => vi.mocked(useChatEvents).mock.lastCall?.[2](blocked))
      expect(screen.getByRole('textbox')).toBeDisabled()
      expect(screen.getByText(/3.*3/)).toBeInTheDocument()
      expect(screen.getByText(user.content)).toBeInTheDocument()
    })

    it('keeps idle history and unsent text stable without polling or requests on focus', async () => {
      vi.useFakeTimers()
      const { request, changed } = setup(async () => ({
        conversation: completed, tokenBudget: completed.tokenBudget, access: completed.access,
      }))
      await act(async () => {})
      const input = screen.getByRole('textbox', { name: 'Message' })
      fireEvent.change(input, { target: { value: 'A draft which must stay here' } })
      const notifications = changed.mock.calls.length
      fireEvent(window, new Event('focus'))
      await act(async () => { await vi.advanceTimersByTimeAsync(86400000) })
      expect(request).toHaveBeenCalledOnce()
      expect(changed).toHaveBeenCalledTimes(notifications)
      expect(screen.getByRole('textbox', { name: 'Message' })).toBe(input)
      expect(input).toHaveValue('A draft which must stay here')
      expect(screen.getByText('An ordinary answer.')).toBeInTheDocument()
    })

    it('refreshes an exhausted budget once at the server-provided retry deadline', async () => {
      vi.useFakeTimers()
      let budget = { remaining: 0, limit: 1000, retryAt: '2026-11-06T10:00:10Z' }
      let access = { ...conversation.access, serverTime: '2026-11-06T10:00:00Z' }
      const { request } = setup(async () => ({ conversation: null, tokenBudget: budget, access }))
      await act(async () => {})
      await act(async () => { await vi.advanceTimersByTimeAsync(10000) })
      expect(request).toHaveBeenCalledOnce()
      budget = { ...budget, remaining: 1000 }
      access = { ...access, serverTime: '2026-11-06T10:00:11Z' }
      await act(async () => { await vi.advanceTimersByTimeAsync(1100) })
      expect(request).toHaveBeenCalledTimes(2)
      await act(async () => { await vi.advanceTimersByTimeAsync(86400000) })
      expect(request).toHaveBeenCalledTimes(2)
    })

    it('pauses updates while closed and rechecks an elapsed ban when reopened', async () => {
      vi.useFakeTimers()
      let access: ChatConversation['access'] = { ...conversation.access, status: 'temporary_ban',
        bannedUntil: '2026-11-06T10:00:10Z', serverTime: '2026-11-06T10:00:00Z' }
      const request = vi.fn(async () => ({ conversation: { ...completed, access }, tokenBudget: completed.tokenBudget, access })) as unknown as AuthorizedJsonRequest
      const props = { contextPath: '/example/chat', createBody: {}, request, onClose: vi.fn() }
      const view = render(<ChatPanel {...props} />)
      await act(async () => {})
      expect(vi.mocked(useChatEvents).mock.lastCall?.[0]).toBe('/chats/chat')
      view.rerender(<ChatPanel {...props} open={false} />)
      expect(vi.mocked(useChatEvents).mock.lastCall?.[0]).toBeNull()
      await act(async () => { await vi.advanceTimersByTimeAsync(20000) })
      expect(request).toHaveBeenCalledOnce()
      access = { ...access, status: 'allowed', serverTime: '2026-11-06T10:00:20Z' }
      view.rerender(<ChatPanel {...props} />)
      await act(async () => { await vi.advanceTimersByTimeAsync(100) })
      expect(request).toHaveBeenCalledTimes(2)
      expect(screen.getByRole('textbox', { name: 'Message' })).toBeEnabled()
      expect(screen.getByText('An ordinary answer.')).toBeInTheDocument()
    })

    it('offers an explicit retry when the one scheduled access refresh fails', async () => {
      vi.useFakeTimers()
      const unavailable = { ...conversation.access, status: 'unavailable' as const,
        serverTime: '2026-11-06T10:00:00Z', unavailableUntil: '2026-11-06T10:00:01Z' }
      let attempts = 0
      let online = false
      const { request } = setup(async () => {
        if (++attempts > 1 && !online) throw new Error('offline')
        return { conversation: null, tokenBudget: conversation.tokenBudget,
          access: online ? { ...unavailable, status: 'allowed', serverTime: '2026-11-06T10:00:02Z' } : unavailable }
      })
      await act(async () => {})
      await act(async () => { await vi.advanceTimersByTimeAsync(1100) })
      await act(async () => { await vi.advanceTimersByTimeAsync(60000) })
      expect(request).toHaveBeenCalledTimes(2)
      online = true
      await act(async () => fireEvent.click(screen.getByRole('button', { name: /refresh|reload/i })))
      expect(request).toHaveBeenCalledTimes(3)
      expect(screen.getByRole('textbox')).toBeEnabled()
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })

    it('learns about a ban from another device only when sending and keeps the unsent draft', async () => {
      const banned = { ...completed, access: { ...completed.access, status: 'temporary_ban' as const,
        bannedUntil: '2099-11-06T11:00:00Z', serverTime: '2099-11-06T10:00:00Z' } }
      const { request } = setup(async (path) => {
        if (path.endsWith('/messages')) throw new ApiRequestError(403, { code: 'CHAT_BANNED', message: null, fields: {}, details: {} })
        if (path === '/chats/chat') return banned
        return { conversation: completed, tokenBudget: completed.tokenBudget, access: completed.access }
      })
      const input = await screen.findByRole('textbox', { name: 'Message' })
      await waitFor(() => expect(input).toBeEnabled())
      fireEvent.change(input, { target: { value: 'Another device blocked AI access' } })
      fireEvent.submit(input.closest('form')!)
      expect(await screen.findByText(/blocked until/i)).toBeInTheDocument()
      await waitFor(() => expect(input).toBeDisabled())
      expect(input).toHaveValue('Another device blocked AI access')
      expect(vi.mocked(request).mock.calls.filter(([path]) => path === '/example/chat')).toHaveLength(1)
    })

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
