import { useEffect, useRef, useState } from 'react'
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
  const profileControlRef = useRef<HTMLDivElement>(null)

  const go = (path: string) => {
    setMenuOpen(false)
    navigate(path)
  }

  useEffect(() => {
    if (!menuOpen) {
      return
    }

    const closeOutside = (event: PointerEvent) => {
      if (!profileControlRef.current?.contains(event.target as Node)) {
        setMenuOpen(false)
      }
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setMenuOpen(false)
      }
    }

    document.addEventListener('pointerdown', closeOutside)
    document.addEventListener('keydown', closeOnEscape)

    return () => {
      document.removeEventListener('pointerdown', closeOutside)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [menuOpen])

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

        <div className="header-breadcrumbs-slot" id="header-breadcrumbs" />

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
            <div className="profile-control" ref={profileControlRef}>
              <button
                aria-expanded={menuOpen}
                aria-label={t('header.profileMenu')}
                className="profile-trigger"
                onClick={() => setMenuOpen((open) => !open)}
                type="button"
              >
                <span className="profile-trigger-avatar">
                  <AuthenticatedImage
                    alt=""
                    fallback={(
                      <span className="avatar-placeholder" aria-hidden="true">
                        {user.username.slice(0, 1).toUpperCase()}
                      </span>
                    )}
                    url={user.avatarUrl}
                  />
                </span>
                <span className="profile-trigger-login">{user.username}</span>
                <svg
                  aria-hidden="true"
                  className="profile-trigger-chevron"
                  focusable="false"
                  viewBox="0 0 12 8"
                >
                  <path d="M1 1.25 6 6.25l5-5" />
                </svg>
              </button>
              {menuOpen && (
                <div className="profile-menu">
                  <button onClick={() => go('/profile')} type="button">
                    <ProfileMenuIcon type="profile" />
                    {t('header.profile')}
                  </button>
                  <button onClick={() => go('/trees')} type="button">
                    <ProfileMenuIcon type="trees" />
                    {t('header.trees')}
                  </button>
                  <button
                    onClick={() => {
                      setMenuOpen(false)
                      void logout().then(() => navigate('/'))
                    }}
                    type="button"
                  >
                    <ProfileMenuIcon type="logout" />
                    {t('header.logout')}
                  </button>
                </div>
              )}
            </div>
          )}
        </div>
      </header>
      {authOpen && (
        <AuthDialog
          onClose={() => setAuthOpen(false)}
          onSuccess={() => {
            setAuthOpen(false)
            navigate('/trees')
          }}
        />
      )}
    </>
  )
}

function ProfileMenuIcon({
  type,
}: {
  type: 'logout' | 'profile' | 'trees'
}) {
  const path = type === 'profile'
    ? (
        <>
          <circle cx="8" cy="5" r="2.5" />
          <path d="M3.5 14c.4-3 2-4.5 4.5-4.5s4.1 1.5 4.5 4.5" />
        </>
      )
    : type === 'trees'
      ? (
          <>
            <path d="M8 2v4M4 14v-3h8v3M4 11V8h8v3" />
            <circle cx="8" cy="2" r="1.25" />
            <circle cx="4" cy="14" r="1.25" />
            <circle cx="12" cy="14" r="1.25" />
          </>
        )
      : (
          <>
            <path d="M6.5 3H3v10h3.5M9.5 5l3 3-3 3M12.5 8h-7" />
          </>
        )

  return (
    <svg
      aria-hidden="true"
      className="profile-menu-icon"
      focusable="false"
      viewBox="0 0 16 16"
    >
      {path}
    </svg>
  )
}
