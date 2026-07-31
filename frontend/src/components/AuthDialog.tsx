import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { AuthForm } from './AuthForm'

type AuthDialogProps = {
  onClose: () => void
}

export function AuthDialog({ onClose }: AuthDialogProps) {
  const { t } = useTranslation()

  useEffect(() => {
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose()
      }
    }
    document.addEventListener('keydown', handleEscape)

    return () => document.removeEventListener('keydown', handleEscape)
  }, [onClose])

  return (
    <div className="dialog-backdrop" role="presentation" onMouseDown={onClose}>
      <section
        aria-labelledby="auth-dialog-title"
        aria-modal="true"
        className="dialog-card"
        onMouseDown={(event) => event.stopPropagation()}
        role="dialog"
      >
        <h2 className="sr-only" id="auth-dialog-title">
          {t('auth.loginTitle')}
        </h2>
        <button
          aria-label={t('common.close')}
          className="dialog-close"
          onClick={onClose}
          type="button"
        >
          ×
        </button>
        <AuthForm onSuccess={onClose} />
      </section>
    </div>
  )
}
