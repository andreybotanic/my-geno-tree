import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type PropsWithChildren,
} from 'react'
import type { User } from '../api/generated'
import { authApi } from '../api/client'
import i18n, { currentGuestLanguage } from '../i18n'
import {
  AuthContext,
  type AuthContextValue,
} from './AuthContext'

export function AuthProvider({ children }: PropsWithChildren) {
  const [accessToken, setAccessToken] = useState<string | null>(null)
  const [user, setUser] = useState<User | null>(null)
  const [isBooting, setIsBooting] = useState(true)
  const bootRequest = useRef<ReturnType<typeof authApi.refresh> | null>(null)

  const acceptAuth = useCallback(
    (response: Awaited<ReturnType<typeof authApi.refresh>>) => {
      setAccessToken(response.accessToken)
      setUser(response.user)
      void i18n.changeLanguage(response.user.preferredLanguage)
    },
    [],
  )

  useEffect(() => {
    let active = true
    bootRequest.current ??= authApi.refresh()
    bootRequest.current
      .then((response) => {
        if (active) {
          acceptAuth(response)
        }
      })
      .catch(() => undefined)
      .finally(() => {
        if (active) {
          setIsBooting(false)
        }
      })

    return () => {
      active = false
    }
  }, [acceptAuth])

  const value = useMemo<AuthContextValue>(
    () => ({
      accessToken,
      isBooting,
      user,
      async login(input) {
        acceptAuth(await authApi.login(input))
      },
      async register(input) {
        acceptAuth(
          await authApi.register({
            ...input,
            language: i18n.resolvedLanguage === 'ru' ? 'ru' : 'en',
          }),
        )
      },
      async logout() {
        try {
          await authApi.logout()
        } finally {
          setAccessToken(null)
          setUser(null)
          await i18n.changeLanguage(currentGuestLanguage())
        }
      },
      async updateLanguage(language) {
        if (!accessToken) {
          return
        }
        const updated = await authApi.updateProfile(
          { language },
          accessToken,
        )
        setUser(updated)
        await i18n.changeLanguage(updated.preferredLanguage)
      },
    }),
    [acceptAuth, accessToken, isBooting, user],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
