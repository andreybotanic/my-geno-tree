import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Language } from '../api/generated'
import { useAuth } from '../auth/AuthContext'
import { profileMediaApi } from '../api/client'
import { AuthenticatedImage } from '../components/AuthenticatedImage'
import { ConfirmRelationshipDialog } from '../components/ConfirmRelationshipDialog'
import { ImageCropDialog } from '../components/ImageCropDialog'
import { LanguagePicker } from '../components/LanguagePicker'

export function ProfilePage() {
  const { t, i18n } = useTranslation()
  const { request, updateLanguage, updateUser, user } = useAuth()
  const [isSaving, setIsSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [avatarEditing, setAvatarEditing] = useState(false)
  const [avatarDeleting, setAvatarDeleting] = useState(false)

  if (!user) {
    return null
  }

  const save = async (language: Language) => {
    setIsSaving(true)
    setSaved(false)
    try {
      await updateLanguage(language)
      setSaved(true)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <main className="content-page profile-page">
      <div className="page-heading">
        <p className="eyebrow">MyGenoTree</p>
        <h1>{t('profile.title')}</h1>
        <p>{t('profile.subtitle')}</p>
      </div>
      <section className="profile-card">
        <div className="profile-avatar-editor">
          <div className="profile-avatar-large">
            <AuthenticatedImage
              alt={t('profile.avatar')}
              fallback={(
                <span aria-hidden="true">
                  {user.username.slice(0, 1).toUpperCase()}
                </span>
              )}
              url={user.avatarUrl}
            />
          </div>
          <div className="profile-avatar-actions">
            <button
              className="secondary-button compact-button"
              onClick={() => setAvatarEditing(true)}
              type="button"
            >
              {t(user.avatarUrl ? 'media.replaceAvatar' : 'media.addAvatar')}
            </button>
            {user.avatarUrl && (
              <button
                className="danger-link"
                onClick={() => setAvatarDeleting(true)}
                type="button"
              >
                {t('media.deleteAvatar')}
              </button>
            )}
          </div>
        </div>
        <dl className="profile-details">
          <div>
            <dt>{t('profile.username')}</dt>
            <dd>{user.username}</dd>
          </div>
          <div>
            <dt>{t('profile.email')}</dt>
            <dd>{user.email}</dd>
          </div>
          <div>
            <dt>{t('profile.createdAt')}</dt>
            <dd>
              {new Intl.DateTimeFormat(i18n.resolvedLanguage, {
                dateStyle: 'long',
              }).format(new Date(user.createdAt))}
            </dd>
          </div>
        </dl>
        <div className="profile-language">
          <span>{t('profile.language')}</span>
          <LanguagePicker
            disabled={isSaving}
            label={t('profile.language')}
            onChange={(language) => void save(language as Language)}
            value={user.preferredLanguage}
          />
        </div>
        <p className="save-status" aria-live="polite">
          {isSaving && t('common.saving')}
          {saved && t('profile.updated')}
        </p>
      </section>
      {avatarEditing && (
        <ImageCropDialog
          aspect={1}
          cropShape="round"
          onCancel={() => setAvatarEditing(false)}
          onSave={async (file, crop) => {
            updateUser(await profileMediaApi.uploadAvatar(request, file, crop))
            setAvatarEditing(false)
          }}
          title={t('media.avatarTitle')}
        />
      )}
      {avatarDeleting && (
        <ConfirmRelationshipDialog
          description={t('media.deleteAvatarDescription')}
          errorMessage={t('media.removeFailed')}
          onCancel={() => setAvatarDeleting(false)}
          onConfirm={async () => {
            updateUser(await profileMediaApi.removeAvatar(request))
            setAvatarDeleting(false)
          }}
          title={t('media.deleteAvatarTitle')}
        />
      )}
    </main>
  )
}
