import { useState } from 'react'
import { useTranslation } from 'react-i18next'

type ConfirmRelationshipDialogProps = {
  confirmClassName?: string
  confirmLabel?: string
  description: string
  errorMessage?: string
  onCancel: () => void
  onConfirm: () => Promise<void>
  title: string
}

export function ConfirmRelationshipDialog({
  confirmClassName,
  confirmLabel,
  description,
  errorMessage,
  onCancel,
  onConfirm,
  title,
}: ConfirmRelationshipDialogProps) {
  const { t } = useTranslation()
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const confirm = async () => {
    setSaving(true)
    setError('')
    try {
      await onConfirm()
    } catch {
      setError(errorMessage ?? t('relationships.removeFailed'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="relationship-confirm-title"
        aria-modal="true"
        className="dialog-card"
        role="dialog"
      >
        <h2 id="relationship-confirm-title">{title}</h2>
        <p>{description}</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        <div className="dialog-actions">
          <button
            className="secondary-button"
            onClick={onCancel}
            type="button"
          >
            {t('common.cancel')}
          </button>
          <button
            className={confirmClassName ?? 'danger-button'}
            disabled={saving}
            onClick={() => void confirm()}
            type="button"
          >
            {saving ? t('common.saving') : (confirmLabel ?? t('common.remove'))}
          </button>
        </div>
      </section>
    </div>
  )
}
