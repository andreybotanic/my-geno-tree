import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import type { TreeMergeTokenBudget } from '../api/generated'

export function TreeMergeBudget({ budget }: { budget: TreeMergeTokenBudget | null }) {
  const { t, i18n } = useTranslation()
  const tooltip = useId()
  const percent = budget ? Math.max(0, Math.min(100, budget.remaining / budget.limit * 100)) : null
  const format = new Intl.NumberFormat(i18n.language, { maximumFractionDigits: 1 })
  const label = percent === null ? t('treeMerge.budgetUnavailable') : t('treeMerge.budgetPercent', { percent: format.format(percent) })
  return <div className="tree-merge-budget" tabIndex={0} role="img" aria-label={label} aria-describedby={tooltip}>
    <svg viewBox="0 0 36 36" width="32" height="32" aria-hidden="true">
      <circle className="tree-merge-budget-track" cx="18" cy="18" r="14" fill="none" strokeWidth="3" />
      <circle className="tree-merge-budget-value" cx="18" cy="18" r="14" fill="none" strokeWidth="3"
        pathLength="100" strokeDasharray={`${percent ?? 0} 100`} transform="rotate(-90 18 18)" />
    </svg>
    <span>{percent === null ? '—' : `${format.format(percent)}%`}</span>
    <span className="tree-merge-budget-tooltip" id={tooltip} role="tooltip">{budget
      ? t('treeMerge.budgetDetails', { remaining: format.format(budget.remaining), limit: format.format(budget.limit) }) : label}</span>
  </div>
}
