import { useEffect, useState } from 'react'
import Cropper, { type Area, type Point } from 'react-easy-crop'
import { useTranslation } from 'react-i18next'
import { ApiRequestError, type CropPixels } from '../api/client'

type ImageCropDialogProps = {
  aspect: number
  cropShape?: 'rect' | 'round'
  onCancel: () => void
  onSave: (file: File, crop: CropPixels) => Promise<void>
  title: string
}

export function ImageCropDialog({
  aspect,
  cropShape = 'rect',
  onCancel,
  onSave,
  title,
}: ImageCropDialogProps) {
  const { t } = useTranslation()
  const [file, setFile] = useState<File | null>(null)
  const [imageUrl, setImageUrl] = useState<string | null>(null)
  const [crop, setCrop] = useState<Point>({ x: 0, y: 0 })
  const [cropPixels, setCropPixels] = useState<Area | null>(null)
  const [zoom, setZoom] = useState(1)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!file) {
      setImageUrl(null)
      return
    }
    const nextUrl = URL.createObjectURL(file)
    setImageUrl(nextUrl)

    return () => URL.revokeObjectURL(nextUrl)
  }, [file])

  const save = async () => {
    if (!file || !cropPixels) {
      setError(t('media.chooseImage'))
      return
    }
    if (file.size > 10 * 1024 * 1024) {
      setError(t('media.tooLarge'))
      return
    }
    setSaving(true)
    setError('')
    try {
      await onSave(file, cropPixels)
    } catch (caught) {
      if (caught instanceof ApiRequestError) {
        setError(
          caught.body.code === 'TREE_VERSION_CONFLICT'
            ? t('editor.versionConflict')
            : caught.body.code === 'IMAGE_TOO_LARGE'
              ? t('media.tooLarge')
              : caught.body.code === 'IMAGE_TYPE_UNSUPPORTED'
                ? t('media.unsupported')
                : caught.body.code === 'IMAGE_CROP_INVALID'
                  ? t('media.invalidCrop')
                  : t('media.saveFailed'),
        )
      } else {
        setError(t('media.saveFailed'))
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="image-crop-title"
        aria-modal="true"
        className="dialog-card image-crop-dialog"
        role="dialog"
      >
        <h2 id="image-crop-title">{title}</h2>
        <label className="image-file-field">
          <span>{t('media.imageFile')}</span>
          <input
            accept="image/jpeg,image/png,image/webp"
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null)
              setCrop({ x: 0, y: 0 })
              setZoom(1)
              setCropPixels(null)
              setError('')
            }}
            type="file"
          />
        </label>
        {imageUrl && (
          <>
            <div className="cropper-stage">
              <Cropper
                aspect={aspect}
                crop={crop}
                cropShape={cropShape}
                image={imageUrl}
                onCropChange={setCrop}
                onCropComplete={(_area, pixels) => setCropPixels(pixels)}
                onZoomChange={setZoom}
                roundCropAreaPixels
                showGrid={cropShape !== 'round'}
                zoom={zoom}
              />
            </div>
            <label className="zoom-field">
              <span>{t('media.zoom')}</span>
              <input
                aria-label={t('media.zoom')}
                max="3"
                min="1"
                onChange={(event) => setZoom(Number(event.target.value))}
                step="0.01"
                type="range"
                value={zoom}
              />
            </label>
          </>
        )}
        <p className="media-help">{t(
          aspect === 1 ? 'media.avatarHelp' : 'media.photoHelp',
        )}</p>
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
            className="primary-button"
            disabled={saving || !file}
            onClick={() => void save()}
            type="button"
          >
            {saving ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </section>
    </div>
  )
}
