import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import ChevronDownIcon from '../assets/icons/chevron-down.svg?react'
import LogoutIcon from '../assets/icons/logout.svg?react'
import PersonOutlineIcon from '../assets/icons/person-outline.svg?react'
import TreeIcon from '../assets/icons/tree.svg?react'
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
                <ChevronDownIcon aria-hidden="true" className="profile-trigger-chevron" focusable="false" />
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
  const Icon = type === 'profile'
    ? PersonOutlineIcon
    : type === 'trees'
      ? TreeIcon
      : LogoutIcon

  return <Icon aria-hidden="true" className="profile-menu-icon" focusable="false" />
}
