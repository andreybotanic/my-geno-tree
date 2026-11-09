import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import type { AuthorizedJsonRequest } from '../api/client'
import { ApiRequestError } from '../api/client'
import type { ChatAccess, ChatConversation, ChatTokenBudget, ChatEvent, TreeChatCurrent } from '../api/generated'
import { ChatActionConfirmation } from './ChatActionConfirmation'
import { ChatPermissions } from './ChatPermissions'
import { ChatBudget } from './ChatBudget'
import { ChatThinking } from './ChatThinking'
import { DocumentSourceLinks } from './DocumentSourceLinks'
import { DocumentDownloadLink } from './DocumentDownloadLink'
import { ChatVoiceInput } from './ChatVoiceInput'
import { mergeChatEvent, useChatEvents } from '../hooks/useChatEvents'

type Props = {
  contextPath: string
  createBody: unknown
  request: AuthorizedJsonRequest
  onClose: () => void
  open?: boolean
  disabled?: boolean
  placeholder?: string
  personActions?: boolean
  onConversationChange?: (conversation: ChatConversation | null) => void
  onToolEvent?: (event: Extract<ChatEvent, { kind: 'tool' }>) => void
  onStreamOpen?: () => void
  renderTools?: (conversation: ChatConversation, busy: boolean, update: (next: ChatConversation) => void) => ReactNode
}

export function ChatPanel({ contextPath, createBody, request, onClose, open = true, disabled = false, placeholder, personActions = false, onConversationChange, onToolEvent, onStreamOpen, renderTools }: Props) {
  const { t, i18n } = useTranslation()
  const [conversation, setConversation] = useState<ChatConversation | null>(null)
  const [budget, setBudget] = useState<ChatTokenBudget | null>(null)
  const [accessState, setAccess] = useState<{ value: ChatAccess; receivedAt: number } | null>(null)
  const access = accessState?.value ?? null
  const acceptAccess = useCallback((next: ChatAccess | undefined) => {
    if (next) setAccess((saved) => saved?.value === next
      || (saved && Date.parse(next.serverTime) < Date.parse(saved.value.serverTime))
      ? saved : { value: next, receivedAt: Date.now() })
  }, [])
  const [content, setContent] = useState('')
  const [clarifyingConfirmationId, setClarifyingConfirmationId] = useState<string | null>(null)
  const [voiceBusy, setVoiceBusy] = useState(false)
  const [busy, setBusy] = useState(true)
  const [thinkingTurn, setThinkingTurn] = useState<{ startedAt: number; requestId: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [stopping, setStopping] = useState(false)
  const generation = useRef(0)
  const activeRequest = useRef<{ chatId: string; requestId: string; controller: AbortController } | null>(null)
  const textarea = useRef<HTMLTextAreaElement>(null)
  const messagesEnd = useRef<HTMLDivElement>(null)
  const scrollContainer = useRef<HTMLDivElement>(null)
  const [scrollEdges, setScrollEdges] = useState({ top: false, bottom: false })
  const pending = useRef<{ requestId: string; content: string; accepted: boolean } | null>(null)
  const base = contextPath
  const thinking = conversation?.status === 'processing' || (thinkingTurn !== null && conversation?.status !== 'awaiting_confirmation'
    && !conversation?.messages.some((message) => message.role !== 'user' && message.requestId === thinkingTurn.requestId))
  const blocked = access !== null && access.status !== 'allowed'
  const confirmation = conversation?.status === 'awaiting_confirmation'
    ? conversation.confirmations.find((item) => item.status === 'pending') : undefined
  const showStop = thinking || (confirmation !== undefined && !content.trim())
  const showConfirmation = confirmation && clarifyingConfirmationId !== confirmation.id
  const stream = useChatEvents(
    open && conversation ? `/chats/${conversation.id}` : null,
    request,
    setConversation,
    (event) => onToolEvent?.(event),
    onStreamOpen
  )
  function update(next: ChatConversation) {
    setConversation((saved) => mergeChatEvent(saved, { kind: 'conversation', conversation: next }))
  }
  useEffect(
    () => {
      const controller = new AbortController()
      const loading = request<TreeChatCurrent>(base, { signal: controller.signal })
      loading
        .then(
          ({ conversation: restored, tokenBudget, access }) => {
            if (controller.signal.aborted) return
            setConversation(restored)
            setBudget(tokenBudget ?? null)
            acceptAccess(access)
            setBusy(false)
          }
        )
        .catch(
          () => {
            if (!controller.signal.aborted) {
              setError('chat.loadFailed')
              setBusy(false)
            }
          }
        )
      return () => controller.abort()
    },
    [base, request, acceptAccess]
  )

  useEffect(
    () => {
      if (conversation) setBudget(conversation.tokenBudget)
      acceptAccess(conversation?.access)
      onConversationChange?.(conversation)
    },
    [conversation, onConversationChange, acceptAccess]
  )

  const refreshAccess = useCallback(async (signal?: AbortSignal) => {
    try {
      const next = await request<TreeChatCurrent>(base, signal ? { signal } : {})
      if (signal?.aborted) return
      acceptAccess(next.access)
      setBudget(next.tokenBudget)
      setConversation((saved) => next.conversation
        ? saved?.id === next.conversation.id
          ? mergeChatEvent(saved, { kind: 'conversation', conversation: next.conversation })
          : next.conversation
        : activeRequest.current ? saved : null)
      setError(null)
    } catch {
      if (!signal?.aborted) setError('chat.loadFailed')
    }
  }, [base, request, acceptAccess])

  useEffect(() => {
    if (!open || !accessState) return
    const controller = new AbortController()
    const refresh = () => { void refreshAccess(controller.signal) }
    const { value: state, receivedAt } = accessState
    const accessDeadline = state.status === 'temporary_ban' ? state.bannedUntil
      : state.status === 'unavailable' ? state.unavailableUntil : null
    const deadlines: number[] = []
    if (accessDeadline) deadlines.push(Date.parse(accessDeadline))
    // RateLimiter's retry timestamp is rounded down to whole seconds.
    if (budget?.remaining === 0 && budget.retryAt) deadlines.push(Date.parse(budget.retryAt) + 1000)
    if (!deadlines.length) return () => controller.abort()
    const dueAt = Math.min(...deadlines) - Date.parse(state.serverTime) + receivedAt
    const expiry = window.setTimeout(refresh,
      Math.min(2147483647, Math.max(0, dueAt - Date.now()) + 100))
    return () => {
      controller.abort()
      window.clearTimeout(expiry)
    }
  }, [open, accessState, budget, refreshAccess])

  useEffect(
    () => {
      const message = pending.current
      const requestId = message?.requestId ?? activeRequest.current?.requestId
      if (!requestId || !conversation) return
      if (message && conversation.messages.some((item) => item.role === 'user' && item.requestId === requestId)) {
        message.accepted = true
        setContent((value) => value === message.content ? '' : value)
      }
      if (conversation.status === 'processing'
        || !(conversation.messages.some((item) => item.role !== 'user' && item.requestId === requestId)
          || (conversation.status === 'awaiting_confirmation'
            && conversation.confirmations.some((item) => item.requestId === requestId && item.status === 'pending')))) return
      // SSE can finish the turn before its HTTP response. Ignore that response's later completion.
      ++generation.current
      activeRequest.current = null
      pending.current = null
      setBusy(false)
      setThinkingTurn(null)
      setError(null)
    },
    [conversation]
  )

  useLayoutEffect(
    () => {
      const input = textarea.current
      if (!input) return
      let width = -1
      function resize() {
        if (!input) return
        const style = getComputedStyle(input)
        const padding = (parseFloat(style.paddingTop) || 12) + (parseFloat(style.paddingBottom) || 0)
        const lineHeight = parseFloat(style.lineHeight) || 24
        // A temporary scrollbar narrows the text and falsely adds a wrapped line.
        input.style.overflowY = 'hidden'
        input.style.height = '0px'
        const measured = input.scrollHeight
        const maximum = 7 * lineHeight + padding
        input.style.height = `${Math.min(Math.max(measured, 2 * lineHeight + padding), maximum)}px`
        input.style.overflowY = measured > maximum ? 'auto' : 'hidden'
      }
      resize()
      const observer = new ResizeObserver(
        (entries) => {
          const next = entries[0]?.contentRect.width
          if (next !== undefined && next !== width) { width = next; resize() }
        }
      )
      observer.observe(input)
      return () => observer.disconnect()
    },
    [content, open, conversation?.status]
  )

  useEffect(
    () => {
      if (!open || busy) return
      const url = new URL(window.location.href)
      if (conversation) url.searchParams.set('chat', conversation.id)
      else url.searchParams.delete('chat')
      window.history.replaceState(window.history.state, '', url)
    },
    [conversation, open, busy]
  )

  useEffect(() => { messagesEnd.current?.scrollIntoView?.({ block: 'end' }) }, [conversation?.messages.length, conversation?.status])

  useLayoutEffect(() => {
    const container = scrollContainer.current
    if (!open || !container) return
    const updateEdges = () => {
      const top = container.scrollTop > 1
      const bottom = container.scrollHeight - container.clientHeight - container.scrollTop > 1
      setScrollEdges((saved) => saved.top === top && saved.bottom === bottom ? saved : { top, bottom })
    }
    updateEdges()
    container.addEventListener('scroll', updateEdges, { passive: true })
    const observer = new ResizeObserver(updateEdges)
    observer.observe(container)
    for (const child of Array.from(container.children)) observer.observe(child)
    return () => {
      container.removeEventListener('scroll', updateEdges)
      observer.disconnect()
    }
  }, [open, conversation?.messages, conversation?.status, conversation?.tools, conversation?.confirmations, blocked, error, stream.error, thinking])

  useEffect(
    () => {
      if (open && !busy) textarea.current?.focus()
    },
    [busy, conversation?.id, open, showConfirmation]
  )

  function showError(cause: unknown) {
    if (cause instanceof ApiRequestError && cause.status === 404 && cause.body.code === 'CHAT_NOT_FOUND') {
      setConversation(null)
      setError(null)
      return
    }
    setError(
      cause instanceof ApiRequestError && cause.status === 412 ? 'chat.conflict'
        : cause instanceof ApiRequestError && cause.status === 409 ? 'chat.conflict'
          : cause instanceof ApiRequestError && cause.status === 401 ? 'chat.unauthorized'
            : cause instanceof ApiRequestError && cause.status === 422 ? 'chat.invalid' : 'chat.failed'
    )
  }

  function close() {
    const url = new URL(window.location.href)
    url.searchParams.delete('chat')
    window.history.replaceState(window.history.state, '', url)
    onClose()
  }

  async function ensureConversation() {
    if (conversation) return conversation
    const next = await request<ChatConversation>(
      base,
      {
        method: 'POST', body: JSON.stringify(createBody),
      }
    )
    setConversation(next)
    return next
  }

  async function send() {
    if (busy || disabled || blocked || voiceBusy || thinking || stopping || !content.trim()) return
    setBusy(true)
    setError(null)
    if (pending.current?.content !== content) pending.current = { requestId: crypto.randomUUID(), content, accepted: false }
    const message = pending.current
    setContent('')
    const turn = ++generation.current
    const controller = new AbortController()
    let current = conversation
    try {
      current = await ensureConversation()
      activeRequest.current = { chatId: current.id, requestId: message.requestId, controller }
      setThinkingTurn({ startedAt: Date.now(), requestId: message.requestId })
      const next = await request<ChatConversation>(
        '/chats/' + current.id + '/messages',
        {
          method: 'POST', body: JSON.stringify({ version: current.version, requestId: message.requestId, content: message.content }), signal: controller.signal,
        }
      )
      if (turn !== generation.current) return
      setConversation((saved) => mergeChatEvent(saved, { kind: 'conversation', conversation: next }))
      setContent('')
      pending.current = null
    } catch (cause) {
      if (turn !== generation.current) return
      showError(cause)
      if (current) {
        try {
          const next = await request<ChatConversation>('/chats/' + current.id)
          if (turn === generation.current) {
            setConversation((saved) => mergeChatEvent(saved, { kind: 'conversation', conversation: next }))
            if (next.messages.some((item) => item.role === 'user' && item.requestId === message.requestId)) {
              message.accepted = true
            }
          }
        } catch { /* Retain the retry identifier when delivery is unconfirmed. */ }
      }
      if (turn === generation.current && !message.accepted) setContent(message.content)
    } finally {
      if (turn === generation.current) { setBusy(false); setThinkingTurn(null); activeRequest.current = null }
    }
  }


  async function stop() {
    const running = activeRequest.current
    const requestId = running?.requestId ?? conversation?.messages.findLast((message) => message.role === 'user')?.requestId
    const chatId = running?.chatId ?? conversation?.id
    if (!chatId || !requestId || stopping) return
    setStopping(true)
    try {
      const next = await request<ChatConversation>(`/chats/${chatId}/stop`, { method: 'POST', body: JSON.stringify({ requestId }) })
      ++generation.current
      running?.controller.abort()
      activeRequest.current = null
      setConversation((saved) => mergeChatEvent(saved, { kind: 'conversation', conversation: next }))
      setThinkingTurn(null)
      setBusy(false)
      setContent('')
      pending.current = null
      setError(null)
    } catch (cause) { showError(cause) }
    finally { setStopping(false) }
  }

  async function confirmationDecision(id: string, decision: 'approve' | 'reject') {
    if (!conversation || busy) return
    const confirmation = conversation.confirmations.find((item) => item.id === id)
    if (!confirmation) return
    const turn = ++generation.current
    const controller = new AbortController()
    activeRequest.current = { chatId: conversation.id, requestId: confirmation.requestId, controller }
    setBusy(true)
    setError(null)
    try {
      const next = await request<ChatConversation>(`/chats/${conversation.id}/confirmations/${id}/decision`, {
        method: 'POST',
        signal: controller.signal,
        body: JSON.stringify({ version: conversation.version, decision }),
      })
      if (turn === generation.current) update(next)
    } catch (cause) {
      if (turn !== generation.current) return
      showError(cause)
      try { update(await request<ChatConversation>(`/chats/${conversation.id}`)) } catch { /* Reconnect can recover state. */ }
    } finally {
      if (turn === generation.current) {
        setBusy(false)
        setThinkingTurn(null)
        activeRequest.current = null
      }
    }
  }

  async function setAutoApprove(value: boolean) {
    if (busy || disabled || blocked || thinking || voiceBusy) return
    setBusy(true)
    try {
      const current = await ensureConversation()
      update(await request<ChatConversation>(`/chats/${current.id}/permissions`, {
        method: 'PATCH', body: JSON.stringify({ version: current.version, autoApprovePersonActions: value }),
      }))
    } catch (cause) { showError(cause) }
    finally { setBusy(false) }
  }

  async function reload() {
    if (!conversation) return
    setBusy(true)
    try {
      const next = await request<ChatConversation>(`/chats/${conversation.id}`)
      setConversation((saved) => mergeChatEvent(saved, { kind: 'conversation', conversation: next }))
      setError(null)
    }
    catch (cause) { showError(cause) }
    finally { setBusy(false) }
  }

  async function reset() {
    if (!conversation) return
    setBusy(true)
    try {
      const next = await request<ChatConversation | undefined>(
        `/chats/${conversation.id}/reset`,
        {
          method: 'POST', body: JSON.stringify({ version: conversation.version }),
        }
      )
      setBudget(next?.tokenBudget ?? conversation.tokenBudget ?? budget)
      ++generation.current
      activeRequest.current?.controller.abort()
      activeRequest.current = null
      setThinkingTurn(null)
      setConversation((saved) => next ? mergeChatEvent(saved, { kind: 'conversation', conversation: next }) : null)
      setError(null)
      setContent('')
      pending.current = null
      const url = new URL(window.location.href)
      url.searchParams.delete('chat')
      window.history.replaceState(window.history.state, '', url)
    } catch (cause) { showError(cause) }
    finally { setBusy(false) }
  }

  const stopControl = <button className="chat-send" type="button" aria-label={t('chat.stop')}
    disabled={stopping} onClick={() => void stop()}>
    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>
  </button>

  return (
    <aside aria-label={t('chat.title')} aria-busy={busy || disabled} className="chat-panel"
      onKeyDown={(event) => { if (event.key === 'Escape') close() }}>
      <header className="chat-heading">
        <h2>{t('chat.title')}</h2>
        {conversation && <div className="chat-actions">
          <button type="button" className="chat-reset" disabled={busy || disabled || voiceBusy}
            onClick={() => void reset()}>{t('chat.newConversation')}</button>
        </div>}
      </header>
      <div ref={scrollContainer}
        className={`chat-content${scrollEdges.top ? ' is-faded-top' : ''}${scrollEdges.bottom ? ' is-faded-bottom' : ''}`}>
        {blocked && <p role="alert" className="chat-access-notice">
          {access?.status === 'temporary_ban' && access.bannedUntil
            ? t('chat.access.temporary', { until: new Date(access.bannedUntil).toLocaleString() })
            : t(access?.status === 'permanent_ban' ? 'chat.access.permanent' : 'chat.access.unavailable')}
        </p>}
        {stream.error && <div role="status"><p>{t(stream.error)}</p>
          <button type="button" onClick={stream.reconnect}>{t('chat.reconnect')}</button>
        </div>}
        {error && <div role="alert">
          <p>{t(error)}</p>
          <button type="button" disabled={busy}
            onClick={() => { if (conversation) void reload(); else void refreshAccess() }}>{t('chat.reload')}</button>
        </div>}
        <div aria-label={t('chat.messages')} className="chat-messages" role="log" aria-live="polite">
          {conversation?.messages.map(
            (message) => (
              <article key={message.id} className={`chat-message chat-message-${message.role}`}
                aria-label={t(message.role === 'user' ? 'chat.you' : message.role === 'assistant' ? 'chat.assistant' : 'chat.notice')}>
                <div className="chat-message-body">
                  {message.role === 'system'
                    ? <p role="status">{message.failureCode ? t(`chat.failure.${message.failureCode}`, { number: message.warningNumber }) : message.content}</p>
                    : message.role === 'assistant'
                      ? <Markdown remarkPlugins={[remarkGfm]} components={{
                        a: ({ href, children }) => {
                          const source = message.documentSources?.find((item) => item.downloadUrl === href)
                          if (source) return <DocumentDownloadLink url={source.downloadUrl} name={source.name}>{children}</DocumentDownloadLink>
                          if (href?.startsWith('/trees/') && href.includes('/documents/')) return <span>{children}</span>
                          return <a href={href}>{children}</a>
                        },
                      }}>{message.content}</Markdown>
                      : <p>{message.content}</p>}
                </div>
                {message.documentSources && <DocumentSourceLinks sources={message.documentSources.filter(
                  (source) => message.content.includes(source.downloadUrl),
                )} />}
                <div className="chat-message-footer">
                  {message.role === 'assistant' && message.responseMetrics && <span className="chat-message-metrics">
                    <span title={t('chat.elapsed')}>{t('chat.responseDuration', {
                      seconds: (message.responseMetrics.durationMs / 1000).toLocaleString(i18n.resolvedLanguage,
                        { maximumFractionDigits: 0 }),
                    })}</span>
                    <span aria-hidden="true">·</span>
                    <span>{t('chat.responseTokens', {
                      value: message.responseMetrics.tokenUsage.total.toLocaleString(i18n.resolvedLanguage),
                    })}</span>
                  </span>}
                  <time className="chat-message-time" dateTime={message.createdAt}
                    title={new Date(message.createdAt).toLocaleString(i18n.resolvedLanguage)}>
                    {new Date(message.createdAt).toLocaleTimeString(i18n.resolvedLanguage,
                      { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })}
                  </time>
                </div>
              </article>
            )
          )}
          {thinking && <ChatThinking startedAt={thinkingTurn?.startedAt ?? (conversation ? Date.parse(conversation.attemptStartedAt ?? conversation.updatedAt) : 0)} commentary={conversation?.commentary ?? null} />}
          {conversation?.status === 'error' && !conversation.messages.at(-1)?.failureCode && <p role="alert">{t('chat.processingFailed')}</p>}
          <div ref={messagesEnd} />
        </div>
        {open && conversation && renderTools?.(conversation, busy || disabled || voiceBusy || thinking, update)}
      </div>
      <form className="chat-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
        {showConfirmation ? <ChatActionConfirmation confirmation={confirmation}
          disabled={busy || disabled || blocked || thinking || voiceBusy}
          onClarify={() => setClarifyingConfirmationId(confirmation.id)}
          onDecision={(decision) => { void confirmationDecision(confirmation.id, decision) }}>
          {stopControl}
        </ChatActionConfirmation> : <ChatVoiceInput request={request} active={open}
          blocked={blocked}
          disabled={busy || disabled || blocked || conversation?.status === 'processing'}
          onAccessError={() => { void refreshAccess() }}
          onBusy={setVoiceBusy}
          onTranscript={(text) => setContent((saved) => [saved.trimEnd(), text.trim()].filter(Boolean).join(' '))}>
          {(recording) => <>
          <textarea id="chat-message" aria-label={t('chat.message')} placeholder={placeholder ?? t('chat.placeholder')}
            ref={textarea} value={content} maxLength={10000} disabled={busy || disabled || blocked || voiceBusy}
            onChange={(event) => setContent(event.target.value)} rows={2}
            onKeyDown={(event) => {
              if (event.ctrlKey && event.key === 'Enter' && !event.nativeEvent.isComposing) {
                event.preventDefault()
                void send()
              }
            }} />
          {!recording && (confirmation ? <button type="button" className="chat-confirmation-back"
            onClick={() => setClarifyingConfirmationId(null)}>{t('chat.confirmation.back')}</button>
            : (personActions || conversation?.profile === 'tree') && <ChatPermissions
            automatic={conversation?.autoApprovePersonActions ?? false}
            disabled={busy || disabled || blocked || thinking || voiceBusy}
            onChange={(value) => { void setAutoApprove(value) }} />)}
          <ChatBudget budget={conversation?.tokenBudget ?? budget} />
          {showStop ? stopControl : <button className="chat-send" type="submit" aria-label={t('chat.send')}
            title={t('chat.sendShortcut')} aria-keyshortcuts="Control+Enter"
            disabled={busy || disabled || blocked || voiceBusy || !content.trim()}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
              <path d="m5 12 7-7 7 7m-7-7v15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>}
          </>}
        </ChatVoiceInput>}
      </form>
    </aside>
  )
}
