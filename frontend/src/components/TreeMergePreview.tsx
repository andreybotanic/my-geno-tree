import { useTranslation } from 'react-i18next'
import type { TreeMergePendingProposal } from '../api/generated'

type Props = {
  proposal: TreeMergePendingProposal
  busy: boolean
  onApply: () => Promise<void>
  onDiscard: () => Promise<void>
  onRevalidate: () => Promise<void>
}

export function TreeMergePreview({ proposal, busy, onApply, onDiscard, onRevalidate }: Props) {
  const { t } = useTranslation()

  return (
    <section aria-label={t('treeMerge.preview')} className="tree-merge-preview tree-merge-graph-review">
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
