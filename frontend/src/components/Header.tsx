import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import { navigate } from '../routing'
import { AuthDialog } from './AuthDialog'
import { LanguageSelector } from './LanguageSelector'
import { AuthenticatedImage } from './AuthenticatedImage'

export function Header() {
  const { t } = useTranslation()
  const { isBooting, logout, user } = useAuth()
  const [authOpen, setAuthOpen] = useState(false)
  const [menuOpen, setMenuOpen] = useState(false)

  const go = (path: string) => {
    setMenuOpen(false)
    navigate(path)
  }

  return (
    <>
      <header className="app-header">
        <button
          aria-label={t('header.home')}
          className="brand"
          onClick={() => navigate('/')}
          type="button"
        >
          <span className="brand-mark" aria-hidden="true">
            ✣
          </span>
          <span>MyGenoTree</span>
        </button>

        <div className="header-actions">
          {!isBooting && !user && (
            <>
              <LanguageSelector />
              <button
                className="primary-button compact-button"
                onClick={() => setAuthOpen(true)}
                type="button"
              >
                {t('header.authenticate')}
              </button>
            </>
          )}
          {user && (
            <div className="profile-control">
              <button
                aria-expanded={menuOpen}
                aria-label={t('header.profileMenu')}
                className="avatar-button"
                onClick={() => setMenuOpen((open) => !open)}
                type="button"
              >
                <AuthenticatedImage
                  alt=""
                  fallback={(
                    <span className="avatar-placeholder" aria-hidden="true">
                      {user.username.slice(0, 1).toUpperCase()}
                    </span>
                  )}
                  url={user.avatarUrl}
                />
              </button>
              {menuOpen && (
                <div className="profile-menu">
                  <strong>{user.username}</strong>
                  <button onClick={() => go('/profile')} type="button">
                    {t('header.profile')}
                  </button>
                  <button onClick={() => go('/trees')} type="button">
                    {t('header.trees')}
                  </button>
                  <button
                    onClick={() => {
                      setMenuOpen(false)
                      void logout().then(() => navigate('/'))
                    }}
                    type="button"
                  >
                    {t('header.logout')}
                  </button>
                </div>
              )}
            </div>
          )}
        </div>
      </header>
      {authOpen && <AuthDialog onClose={() => setAuthOpen(false)} />}
    </>
  )
}
