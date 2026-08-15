import { useTranslation } from 'react-i18next'
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
          <svg
            aria-hidden="true"
            className="dialog-close-icon"
            focusable="false"
            viewBox="0 0 24 24"
          >
            <path d="m5 5 14 14M19 5 5 19" />
          </svg>
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
