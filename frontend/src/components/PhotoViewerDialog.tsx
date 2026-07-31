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
        aria-labelledby="photo-viewer-title"
        aria-modal="true"
        className="dialog-card photo-viewer-dialog"
        role="dialog"
      >
        <h2 id="photo-viewer-title">{title}</h2>
        <AuthenticatedImage
          alt={title}
          fallback={<p>{t('media.loadingImage')}</p>}
          url={url}
        />
        <div className="dialog-actions">
          <button
            className="secondary-button"
            onClick={onClose}
            type="button"
          >
            {t('common.close')}
          </button>
        </div>
      </section>
    </div>
  )
}
