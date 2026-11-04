import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

export function TreeMergeThinking({ startedAt, progress }: { startedAt: number; progress: string[] }) {
  const { t } = useTranslation()
  const [now, setNow] = useState(Date.now)
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  const seconds = Math.max(0, Math.floor((now - startedAt) / 1000))
  return <div className="tree-merge-thinking">
    <p role="status"><span className="tree-merge-thinking-dot" aria-hidden="true" />{t('treeMerge.processing')}
      <span className="tree-merge-thinking-time" aria-label={t('treeMerge.elapsed')}>{Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}</span>
    </p>
    {progress.length > 0 && <ul aria-live="polite">{progress.map((step) => <li key={step}>{t(`treeMerge.progress.${step}`)}</li>)}</ul>}
  </div>
}
