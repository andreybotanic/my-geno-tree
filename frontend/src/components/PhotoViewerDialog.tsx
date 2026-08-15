import { useTranslation } from 'react-i18next'
import CloseIcon from '../assets/icons/close.svg?react'
import { AuthenticatedImage } from './AuthenticatedImage'

type PhotoViewerDialogProps = {
  onClose: () => void
  title: string
  url: string
}

export function PhotoViewerDialog({
  onClose,
  title,
  url,
}: PhotoViewerDialogProps) {
  const { t } = useTranslation()

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-label={title}
        aria-modal="true"
        className="dialog-card photo-viewer-dialog"
        role="dialog"
      >
        <button
          aria-label={t('common.close')}
          className="dialog-close"
          onClick={onClose}
          type="button"
        >
          <CloseIcon aria-hidden="true" className="dialog-close-icon" focusable="false" />
        </button>
        <div className="photo-viewer-content">
          <AuthenticatedImage
            alt={title}
            className="photo-viewer-image"
            fallback={<p>{t('media.loadingImage')}</p>}
            url={url}
          />
        </div>
      </section>
    </div>
  )
}
