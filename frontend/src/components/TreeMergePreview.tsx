import { useTranslation } from 'react-i18next'
import type { TreeMergeDraft } from '../api/generated'

type Props = {
  draft: TreeMergeDraft
  busy: boolean
  onApply: () => Promise<void>
  onRevalidate: () => Promise<void>
}

export function TreeMergePreview({ draft, busy, onApply, onRevalidate }: Props) {
  const { t } = useTranslation()

  return (
    <section aria-label={t('treeMerge.preview')} className="tree-merge-preview tree-merge-graph-review">
      <p className="tree-merge-graph-legend">{t('treeMerge.graphLegend')}</p>
      {draft.state === 'applied' ? <p role="status">{t('treeMerge.applied')}</p> : (
        <div className="tree-merge-confirm">
          {draft.state === 'conflict' && <button type="button" disabled={busy || draft.chatState === 'processing'} onClick={() => void onRevalidate()}>
            {t('treeMerge.revalidate')}
          </button>}
          {draft.state === 'no_changes' && <p>{t('treeMerge.noChanges')}</p>}
          <button type="button" className="primary-button" disabled={busy || draft.chatState === 'processing' || draft.state !== 'ready'}
            onClick={() => void onApply()}>{t('treeMerge.apply')}</button>
        </div>
      )}
    </section>
  )
}
