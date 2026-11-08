import { useTranslation } from 'react-i18next'
import type { GenealogicalDate, ToolConfirmation } from '../api/generated'
import { formatGenealogicalDate, formatPersonName } from '../personFormatting'

type Props = {
  confirmation: ToolConfirmation
  disabled: boolean
  onDecision: (decision: 'approve' | 'reject', autoApprove?: boolean) => void
}

export function ActionConfirmationCard({ confirmation, disabled, onDecision }: Props) {
  const { t, i18n } = useTranslation()
  const { summary, status, tool } = confirmation
  const pending = status === 'pending'
  const person = summary.person
  function display(value: unknown): string {
    if (value === null) return t('chat.confirmation.absent')
    if (typeof value === 'string') return t(`treeAction.values.${value}`, { defaultValue: value })
    if (typeof value !== 'object') return String(value)
    if ('state' in value && value.state === 'unknown') return t('person.unknown')
    if ('value' in value && typeof value.value === 'string') return value.value
    if ('year' in value) return formatGenealogicalDate(value as GenealogicalDate, i18n.resolvedLanguage ?? 'en', t)
    if ('name' in value && typeof value.name === 'string') return value.name
    return '—'
  }
  return <section className={`chat-confirmation${pending ? ' is-pending' : ''}`}
    aria-label={t('chat.confirmation.title')} role={pending ? 'group' : undefined}>
    <div className="chat-confirmation-heading">
      <strong>{t(`treeAction.operations.${tool}`)}</strong>
      <span role="status">{t(`chat.confirmation.status.${status}`)}</span>
    </div>
    {person && <p>{formatPersonName(person, t)}</p>}
    {tool === 'delete_person' && <p>{t('chat.confirmation.deleteConsequences')}</p>}
    {summary.fields && <dl>{Object.entries(summary.fields).map(([field, value]) =>
      <div key={field}><dt>{t(`treeAction.fields.${field}`)}</dt><dd>{display(value)}</dd></div>
    )}</dl>}
    {summary.relative && <p>{t(`chat.confirmation.connection.${summary.connection?.type ?? 'relative'}`, {
      name: formatPersonName(summary.relative, t),
      role: t(`treeAction.values.${summary.connection?.role ?? 'unknown'}`),
    })}</p>}
    {pending && <div className="chat-confirmation-actions">
      <button type="button" className={tool === 'delete_person' ? 'danger-button' : 'primary-button'}
        disabled={disabled} onClick={() => onDecision('approve')}>{t('chat.confirmation.allow')}</button>
      <button type="button" className="secondary-button" disabled={disabled}
        onClick={() => onDecision('reject')}>{t('chat.confirmation.reject')}</button>
      <button type="button" className="chat-confirmation-auto" disabled={disabled}
        onClick={() => onDecision('approve', true)}>{t('chat.confirmation.allowAll')}</button>
    </div>}
    {status === 'failed' && <p role="alert">{t('chat.confirmation.failed')}</p>}
  </section>
}
