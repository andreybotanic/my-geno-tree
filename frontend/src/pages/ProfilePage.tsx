import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Language } from '../api/generated'
import { useAuth } from '../auth/AuthContext'

export function ProfilePage() {
  const { t, i18n } = useTranslation()
  const { updateLanguage, user } = useAuth()
  const [isSaving, setIsSaving] = useState(false)
  const [saved, setSaved] = useState(false)

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
        <div className="profile-avatar-large" aria-hidden="true">
          {user.username.slice(0, 1).toUpperCase()}
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
        <label className="profile-language">
          <span>{t('profile.language')}</span>
          <select
            disabled={isSaving}
            onChange={(event) => void save(event.target.value as Language)}
            value={user.preferredLanguage}
          >
            <option value="en">🇺🇸 English</option>
            <option value="ru">🇷🇺 Русский</option>
          </select>
        </label>
        <p className="save-status" aria-live="polite">
          {isSaving && t('common.saving')}
          {saved && t('profile.updated')}
        </p>
      </section>
    </main>
  )
}
