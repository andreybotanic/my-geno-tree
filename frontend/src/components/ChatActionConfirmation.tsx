import { useEffect, useRef, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { ToolConfirmation } from '../api/generated'
import { formatPersonName } from '../personFormatting'

type Props = {
  confirmation: ToolConfirmation
  disabled: boolean
  onDecision: (decision: 'approve' | 'reject') => void
  onClarify: () => void
  children: ReactNode
}

export function ChatActionConfirmation({ confirmation, disabled, onDecision, onClarify, children }: Props) {
  const { t } = useTranslation()
  const prompt = useRef<HTMLDivElement>(null)
  useEffect(() => { prompt.current?.focus() }, [confirmation.id])
  const { summary, tool } = confirmation
  const name = formatPersonName(summary.person ?? {
    birthSurname: summary.fields?.birthSurname ?? { state: 'unknown' },
    givenName: summary.fields?.givenName ?? { state: 'unknown' },
    patronymic: summary.fields?.patronymic ?? { state: 'unknown' },
  }, t)
  const relative = [summary.relative, summary.otherRelative].filter((person) => person !== undefined)
    .map((person) => formatPersonName(person, t)).join(' / ')
  const action = tool === 'delete_person' ? 'delete' : summary.relative
    ? `create_${summary.connection?.type ?? 'person'}` : 'create'
  const text = t(`chat.confirmation.${action}`, { name, relative })
    + (summary.createsUnknownParent ? ` ${t('chat.confirmation.unknownParent')}` : '')

  return <div className="chat-input chat-confirmation" role="group" aria-label={t('chat.confirmation.title')}
    ref={prompt} tabIndex={-1}>
    <span className="chat-confirmation-text" title={text}>{text}</span>
    <div className="chat-confirmation-actions">
      <button type="button" disabled={disabled} onClick={() => onDecision('approve')}>
        {t('chat.confirmation.allow')}
      </button>
      <button type="button" disabled={disabled} onClick={() => onDecision('reject')}>
        {t('chat.confirmation.reject')}
      </button>
      <button type="button" disabled={disabled} onClick={onClarify}>
        {t('chat.confirmation.clarify')}
      </button>
    </div>
    {children}
  </div>
}
