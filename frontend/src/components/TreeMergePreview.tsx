import { useTranslation } from 'react-i18next'
import type { TreeMergePendingProposal } from '../api/generated'

type Props = {
  proposal: TreeMergePendingProposal
  busy: boolean
  onApply: () => Promise<void>
  onRevalidate: () => Promise<void>
}

export function TreeMergePreview({ proposal, busy, onApply, onRevalidate }: Props) {
  const { t } = useTranslation()

  return (
    <section aria-label={t('treeMerge.preview')} className="tree-merge-preview tree-merge-graph-review">
      <p className="tree-merge-graph-legend">{t('treeMerge.graphLegend')}</p>
        <div className="tree-merge-confirm">
          {proposal.state === 'conflict' && <button type="button" disabled={busy} onClick={() => void onRevalidate()}>
            {t('treeMerge.revalidate')}
          </button>}
          {proposal.state === 'no_changes' && <p>{t('treeMerge.noChanges')}</p>}
          <button type="button" className="primary-button" disabled={busy || proposal.state !== 'ready'}
            onClick={() => void onApply()}>{t('treeMerge.apply')}</button>
        </div>
    </section>
  )
}
