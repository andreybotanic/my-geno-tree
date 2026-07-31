import { useEffect, useState } from 'react'
import './App.css'

type HealthState = 'checking' | 'ready' | 'unavailable'

export default function App() {
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
    <div className="app-shell">
      <header className="app-header">
        <a className="brand" href="/" aria-label="MyGenoTree home">
          <span className="brand-mark" aria-hidden="true">
            ✣
          </span>
          <span>MyGenoTree</span>
        </a>
      </header>

      <main className="welcome">
        <p className="eyebrow">Local genealogy workspace</p>
        <h1>Keep every family story connected.</h1>
        <p className="welcome-copy">
          The application workspace is ready. Account and tree tools are being
          added as complete, tested feature slices.
        </p>
        <output className={`health health-${health}`} aria-live="polite">
          <span aria-hidden="true" />
          {health === 'checking' && 'Checking the local API…'}
          {health === 'ready' && 'Local API connected'}
          {health === 'unavailable' && 'Local API is unavailable'}
        </output>
      </main>
    </div>
  )
}
