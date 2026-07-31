import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

type HealthState = 'checking' | 'ready' | 'unavailable'

export function HomePage() {
  const { t } = useTranslation()
  const [health, setHealth] = useState<HealthState>('checking')

  useEffect(() => {
    const controller = new AbortController()
    fetch('/api/v1/health', { signal: controller.signal })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Backend health check failed')
        }
        setHealth('ready')
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return
        }
        setHealth('unavailable')
      })

    return () => controller.abort()
  }, [])

  return (
    <main className="welcome">
      <p className="eyebrow">{t('home.eyebrow')}</p>
      <h1>{t('home.title')}</h1>
      <p className="welcome-copy">{t('home.copy')}</p>
      <output className={`health health-${health}`} aria-live="polite">
        <span aria-hidden="true" />
        {health === 'checking' && t('home.apiChecking')}
        {health === 'ready' && t('home.apiReady')}
        {health === 'unavailable' && t('home.apiUnavailable')}
      </output>
    </main>
  )
}
