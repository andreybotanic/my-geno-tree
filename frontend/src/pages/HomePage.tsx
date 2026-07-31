import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Statistics } from '../api/generated'

export function HomePage() {
  const { t } = useTranslation()
  const [statistics, setStatistics] = useState<Statistics | null>(null)
  const [unavailable, setUnavailable] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    fetch('/api/v1/statistics', { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) {
          throw new Error('Statistics request failed')
        }
        setStatistics(await response.json() as Statistics)
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return
        }
        setUnavailable(true)
      })

    return () => controller.abort()
  }, [])

  return (
    <main className="welcome">
      <p className="eyebrow">{t('home.eyebrow')}</p>
      <h1>{t('home.title')}</h1>
      <p className="welcome-copy">{t('home.copy')}</p>
      <section className="statistics" aria-label={t('home.statistics')}>
        <article>
          <strong>{statistics?.accountCount ?? '—'}</strong>
          <span>{t('home.accounts')}</span>
        </article>
        <article>
          <strong>{statistics?.treeCount ?? '—'}</strong>
          <span>{t('home.trees')}</span>
        </article>
      </section>
      {unavailable && (
        <p className="inline-error" role="alert">
          {t('home.apiUnavailable')}
        </p>
      )}
    </main>
  )
}
