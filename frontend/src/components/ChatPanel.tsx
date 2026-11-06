import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import { ApiRequestError } from '../api/client'
import type { ChatConversation, ChatTokenBudget, ChatEvent } from '../api/generated'
import { ChatBudget } from './ChatBudget'
import { ChatThinking } from './ChatThinking'
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
  onConversationChange?: (conversation: ChatConversation | null) => void
  onToolEvent?: (event: Extract<ChatEvent, { kind: 'tool' }>) => void
  onStreamOpen?: () => void
  renderTools?: (conversation: ChatConversation, busy: boolean, update: (next: ChatConversation) => void) => ReactNode
}

export function ChatPanel({ contextPath, createBody, request, onClose, open = true, disabled = false, placeholder, onConversationChange, onToolEvent, onStreamOpen, renderTools }: Props) {
  const { t } = useTranslation()
  const [conversation, setConversation] = useState<ChatConversation | null>(null)
  const [budget, setBudget] = useState<ChatTokenBudget | null>(null)
  const [content, setContent] = useState('')
  const [voiceBusy, setVoiceBusy] = useState(false)
  const [busy, setBusy] = useState(true)
  const [thinkingSince, setThinkingSince] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [stopping, setStopping] = useState(false)
  const generation = useRef(0)
  const activeRequest = useRef<{ chatId: string; requestId: string; controller: AbortController } | null>(null)
  const textarea = useRef<HTMLTextAreaElement>(null)
  const messagesEnd = useRef<HTMLDivElement>(null)
  const pending = useRef<{ requestId: string; content: string } | null>(null)
  const base = contextPath
  const thinking = thinkingSince !== null || conversation?.status === 'processing'
  const stream = useChatEvents(conversation ? `/chats/${conversation.id}` : null,
    request, setConversation, (event) => onToolEvent?.(event), onStreamOpen)
  function update(next: ChatConversation) {
    setConversation((saved) => mergeChatEvent(saved, { kind: 'conversation', conversation: next }))
  }
  useEffect(() => {
    const controller = new AbortController()
    const loading = request<{ conversation: ChatConversation | null; tokenBudget: ChatTokenBudget }>(base, { signal: controller.signal })
    loading.then(({ conversation: restored, tokenBudget }) => {
        if (controller.signal.aborted) return
        setConversation(restored)
        setBudget(tokenBudget ?? null)
        setBusy(false)
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setError('chat.loadFailed')
          setBusy(false)
        }
      })
    return () => controller.abort()
  }, [base, request])

  useEffect(() => {
    if (conversation) setBudget(conversation.tokenBudget)
    onConversationChange?.(conversation)
  }, [conversation, onConversationChange])

  useLayoutEffect(() => {
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
    const observer = new ResizeObserver((entries) => {
      const next = entries[0]?.contentRect.width
      if (next !== undefined && next !== width) { width = next; resize() }
    })
    observer.observe(input)
    return () => observer.disconnect()
  }, [content, open, conversation?.status])

  useEffect(() => {
    if (!open || busy) return
    const url = new URL(window.location.href)
    if (conversation) url.searchParams.set('chat', conversation.id)
    else url.searchParams.delete('chat')
    window.history.replaceState(window.history.state, '', url)
  }, [conversation, open, busy])

  useEffect(() => { messagesEnd.current?.scrollIntoView?.({ block: 'end' }) }, [conversation?.messages.length, conversation?.status])

  useEffect(() => {
    if (open && !busy) textarea.current?.focus()
  }, [busy, conversation?.id, open])

  function showError(cause: unknown) {
    if (cause instanceof ApiRequestError && cause.status === 404 && cause.body.code === 'CHAT_NOT_FOUND') {
      setConversation(null)
      setError(null)
      return
    }
    setError(cause instanceof ApiRequestError && cause.status === 412 ? 'chat.conflict'
      : cause instanceof ApiRequestError && cause.status === 409 ? 'chat.conflict'
      : cause instanceof ApiRequestError && cause.status === 401 ? 'chat.unauthorized'
      : cause instanceof ApiRequestError && cause.status === 422 ? 'chat.invalid' : 'chat.failed')
  }

  function close() {
    const url = new URL(window.location.href)
    url.searchParams.delete('chat')
    window.history.replaceState(window.history.state, '', url)
    onClose()
  }

  async function ensureConversation() {
    if (conversation) return conversation
    const next = await request<ChatConversation>(base, {
      method: 'POST', body: JSON.stringify(createBody),
    })
    setConversation(next)
    return next
  }

  async function send() {
    if (busy || disabled || voiceBusy || thinking || stopping || !content.trim()) return
    setBusy(true)
    setError(null)
    if (pending.current?.content !== content) pending.current = { requestId: crypto.randomUUID(), content }
    const message = pending.current
    const turn = ++generation.current
    const controller = new AbortController()
    let current = conversation
    try {
      current = await ensureConversation()
      activeRequest.current = { chatId: current.id, requestId: message.requestId, controller }
      setThinkingSince(Date.now())
      const next = await request<ChatConversation>('/chats/' + current.id + '/messages', {
        method: 'POST', body: JSON.stringify({ version: current.version, ...message }), signal: controller.signal,
      })
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
              setContent('')
              pending.current = null
            }
          }
        } catch { /* Keep unsaved input and its retry identifier. */ }
      }
    } finally {
      if (turn === generation.current) { setBusy(false); setThinkingSince(null); activeRequest.current = null }
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
      setThinkingSince(null)
      setBusy(false)
      setContent('')
      pending.current = null
      setError(null)
    } catch (cause) { showError(cause) }
    finally { setStopping(false) }
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
      const next = await request<ChatConversation | undefined>(`/chats/${conversation.id}/reset`, {
        method: 'POST', body: JSON.stringify({ version: conversation.version }),
      })
      setBudget(next?.tokenBudget ?? conversation.tokenBudget ?? budget)
      ++generation.current
      activeRequest.current?.controller.abort()
      activeRequest.current = null
      setThinkingSince(null)
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
      <div className="chat-content">
        {stream.error && <div role="status"><p>{t(stream.error)}</p>
          <button type="button" onClick={stream.reconnect}>{t('chat.reconnect')}</button>
        </div>}
        {error && <div role="alert">
          <p>{t(error)}</p>
          {conversation && <button type="button" disabled={busy} onClick={() => void reload()}>{t('chat.reload')}</button>}
        </div>}
        <div aria-label={t('chat.messages')} className="chat-messages" role="log" aria-live="polite">
          {conversation?.messages.map((message) => message.role === 'system' ? (
            <p key={message.id} role="status">{message.failureCode ? t(`chat.failure.${message.failureCode}`) : message.content}</p>
          ) : (
            <article key={message.id} className={`chat-message chat-message-${message.role}`}
              aria-label={t(message.role === 'user' ? 'chat.you' : 'chat.assistant')}>
              <p>{message.content}</p>
            </article>
          ))}
          {thinking && <ChatThinking startedAt={thinkingSince ?? (conversation ? Date.parse(conversation.attemptStartedAt ?? conversation.updatedAt) : 0)} progress={conversation?.progress ?? []} />}
          {conversation?.status === 'error' && !conversation.messages.at(-1)?.failureCode && <p role="alert">{t('chat.processingFailed')}</p>}
          <div ref={messagesEnd} />
        </div>
        {open && conversation && renderTools?.(conversation, busy || disabled || voiceBusy || thinking, update)}
      </div>
      <form className="chat-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
        <ChatVoiceInput request={request} active={open}
          disabled={busy || disabled || conversation?.status === 'processing'}
          onBusy={setVoiceBusy}
          onTranscript={(text) => setContent(content ? `${content}\n${text}` : text)}>
          <textarea id="chat-message" aria-label={t('chat.message')} placeholder={placeholder ?? t('chat.placeholder')}
            ref={textarea} value={content} maxLength={10000} disabled={busy || disabled || voiceBusy}
            onChange={(event) => setContent(event.target.value)} rows={2}
            onKeyDown={(event) => {
              if (event.ctrlKey && event.key === 'Enter' && !event.nativeEvent.isComposing) {
                event.preventDefault()
                void send()
              }
            }} />
          <ChatBudget budget={conversation?.tokenBudget ?? budget} />
          {thinking ? <button className="chat-send" type="button" aria-label={t('chat.stop')}
            disabled={stopping} onClick={() => void stop()}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>
          </button> : <button className="chat-send" type="submit" aria-label={t('chat.send')}
            title={t('chat.sendShortcut')} aria-keyshortcuts="Control+Enter"
            disabled={busy || disabled || voiceBusy || !content.trim()}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
              <path d="m5 12 7-7 7 7m-7-7v15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>}
        </ChatVoiceInput>
      </form>
    </aside>
  )
}
