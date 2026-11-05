import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import type { ChatTokenBudget } from '../api/generated'

export function ChatBudget({ budget }: { budget: ChatTokenBudget | null }) {
  const { t, i18n } = useTranslation()
  const tooltip = useId()
  const percent = budget ? Math.max(0, Math.min(100, budget.remaining / budget.limit * 100)) : null
  const format = new Intl.NumberFormat(i18n.language, { maximumFractionDigits: 1 })
  const label = percent === null ? t('chat.budgetUnavailable') : t('chat.budgetPercent', { percent: format.format(percent) })
  return <div className="chat-budget" tabIndex={0} role="img" aria-label={label} aria-describedby={tooltip}>
    <svg viewBox="0 0 36 36" width="20" height="20" aria-hidden="true">
      <circle className="chat-budget-track" cx="18" cy="18" r="14" fill="none" strokeWidth="3" />
      <circle className="chat-budget-value" cx="18" cy="18" r="14" fill="none" strokeWidth="3"
        pathLength="100" strokeDasharray={`${percent ?? 0} 100`} transform="rotate(-90 18 18)" />
    </svg>
    <span>{percent === null ? '—' : `${format.format(percent)}%`}</span>
    <span className="chat-budget-tooltip" id={tooltip} role="tooltip">{budget
      ? t('chat.budgetDetails', { remaining: format.format(budget.remaining), limit: format.format(budget.limit) }) : label}</span>
  </div>
}
