import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

export function ChatThinking({ startedAt, progress }: { startedAt: number; progress: string[] }) {
  const { t } = useTranslation()
  const [now, setNow] = useState(Date.now)
  useEffect(
    () => {
      const timer = window.setInterval(() => setNow(Date.now()), 1000)
      return () => window.clearInterval(timer)
    },
    []
  )
  const seconds = Math.max(0, Math.floor((now - startedAt) / 1000))
  const action = progress.at(-1)
  return <div className="chat-thinking">
    <p role="status"><span className="chat-thinking-dot" aria-hidden="true" />
      <span className="chat-thinking-label">{action && action !== 'model_request'
        ? t(`chat.progress.${action}`, { defaultValue: t('chat.processing') }) : t('chat.processing')}</span>
      <span className="chat-thinking-time" aria-label={t('chat.elapsed')}>{Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}</span>
    </p>
  </div>
}
