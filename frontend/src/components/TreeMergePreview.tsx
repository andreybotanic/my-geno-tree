import { useTranslation } from 'react-i18next'
import type { GenealogicalDate, TreeMergePendingProposal } from '../api/generated'
import { formatGenealogicalDate } from '../personFormatting'

type Props = {
  proposal: TreeMergePendingProposal
  busy: boolean
  onApply: () => Promise<void>
  onDiscard: () => Promise<void>
  onRevalidate: () => Promise<void>
}

export function TreeMergePreview({ proposal, busy, onApply, onDiscard, onRevalidate }: Props) {
  const { t, i18n } = useTranslation()
  function valueText(value: unknown): string {
    if (value === null) return '—'
    if (typeof value === 'string') return value
    if (typeof value !== 'object') return ''
    if ('state' in value) {
      if (value.state === 'unknown') return t('person.unknown')
      if ('kind' in value) return formatGenealogicalDate(value as GenealogicalDate, i18n.language, t)
      if ('value' in value && typeof value.value === 'string') return value.value
    }
    if ('name' in value && typeof value.name === 'string') return value.name
    return ''
  }

  return (
    <section aria-label={t('treeMerge.preview')} className="tree-merge-preview tree-merge-graph-review">
      {proposal.proposal.operations && <ol className="tree-change-operations">
        {proposal.proposal.operations.map((operation, index) => {
          const fields = 'fields' in operation ? operation.fields : 'event' in operation ? operation.event
            : 'place' in operation ? { place: operation.place } : 'role' in operation ? { role: operation.role } : {}
          return <li key={index}>
            <strong>{t(`treeChange.operations.${operation.type}`)}</strong>
            <p>{proposal.proposal.operationSubjects?.[index]?.map((name) => name || t('person.unknownPerson')).join(' · ')}</p>
            <dl>{Object.entries(fields).map(([key, value]) => <div key={key}>
              <dt>{t(`treeChange.fields.${key}`)}</dt>
              <dd style={{ whiteSpace: 'pre-wrap' }}>{['gender', 'role', 'type'].includes(key) && typeof value === 'string'
                ? t(`treeChange.values.${value}`) : valueText(value)}</dd>
            </div>)}</dl>
            {operation.evidence.map((source, sourceIndex) => <blockquote key={sourceIndex}>{source.quote}</blockquote>)}
          </li>
        })}
      </ol>}
      <div className="tree-merge-confirm">
        {proposal.state === 'conflict' && <button type="button" className="secondary-button" disabled={busy} onClick={() => void onRevalidate()}>
          {t('treeMerge.revalidate')}
        </button>}
        {proposal.state === 'no_changes' && <p>{t('treeMerge.noChanges')}</p>}
        <button type="button" className="primary-button" disabled={busy || proposal.state !== 'ready'}
          onClick={() => void onApply()}>{t('treeMerge.apply')}</button>
        <button type="button" className="secondary-button" disabled={busy} onClick={() => void onDiscard()}>{t('treeMerge.cancel')}</button>
      </div>
    </section>
  )
}
