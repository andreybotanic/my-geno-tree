import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type PropsWithChildren,
} from 'react'
import type { User } from '../api/generated'
import {
  ApiRequestError,
  authApi,
  blobRequest,
  jsonRequest,
} from '../api/client'
import i18n, { currentGuestLanguage } from '../i18n'
import {
  AuthContext,
  type AuthContextValue,
} from './AuthContext'

export function AuthProvider({ children }: PropsWithChildren) {
  const [accessToken, setAccessToken] = useState<string | null>(null)
  const [user, setUser] = useState<User | null>(null)
  const [isBooting, setIsBooting] = useState(true)
  const accessTokenRef = useRef<string | null>(null)
  const bootRequest = useRef<ReturnType<typeof authApi.refresh> | null>(null)
  const refreshRequest = useRef<ReturnType<typeof authApi.refresh> | null>(null)

  const acceptAuth = useCallback(
    (response: Awaited<ReturnType<typeof authApi.refresh>>) => {
      accessTokenRef.current = response.accessToken
      setAccessToken(response.accessToken)
      setUser(response.user)
      void i18n.changeLanguage(response.user.preferredLanguage)
    },
    [],
  )

  const refreshAccessToken = useCallback(async (rejectedToken: string) => {
    if (accessTokenRef.current && accessTokenRef.current !== rejectedToken) {
      return accessTokenRef.current
    }

    refreshRequest.current ??= authApi
      .refresh()
      .finally(() => {
        refreshRequest.current = null
      })

    try {
      const refreshed = await refreshRequest.current
      acceptAuth(refreshed)

      return refreshed.accessToken
    } catch (error) {
      accessTokenRef.current = null
      setAccessToken(null)
      setUser(null)
      await i18n.changeLanguage(currentGuestLanguage())
      throw error
    }
  }, [acceptAuth])

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

  const request = useCallback(
    async <T,>(path: string, init: RequestInit = {}): Promise<T> => {
      const accessToken = accessTokenRef.current
      if (!accessToken) {
        throw new Error('An authenticated session is required')
      }

      const withToken = (token: string) => {
        const headers = new Headers(init.headers)
        headers.set('Authorization', `Bearer ${token}`)

        return jsonRequest<T>(path, {
          ...init,
          headers,
        })
      }

      try {
        return await withToken(accessToken)
      } catch (error) {
        if (!(error instanceof ApiRequestError) || error.status !== 401) {
          throw error
        }

        return withToken(await refreshAccessToken(accessToken))
      }
    },
    [refreshAccessToken],
  )

  const requestBlob = useCallback(
    async (path: string, init: RequestInit = {}): Promise<Blob> => {
      const accessToken = accessTokenRef.current
      if (!accessToken) {
        throw new Error('An authenticated session is required')
      }
      const withToken = (token: string) => {
        const headers = new Headers(init.headers)
        headers.set('Authorization', `Bearer ${token}`)

        return blobRequest(path, { ...init, headers })
      }
      try {
        return await withToken(accessToken)
      } catch (error) {
        if (!(error instanceof ApiRequestError) || error.status !== 401) {
          throw error
        }
        return withToken(await refreshAccessToken(accessToken))
      }
    },
    [refreshAccessToken],
  )

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
          accessTokenRef.current = null
          setAccessToken(null)
          setUser(null)
          await i18n.changeLanguage(currentGuestLanguage())
        }
      },
      request,
      requestBlob,
      async updateLanguage(language) {
        const updated = await request<User>('/me', {
          method: 'PATCH',
          body: JSON.stringify({ language }),
        })
        setUser(updated)
        await i18n.changeLanguage(updated.preferredLanguage)
      },
      updateUser(updatedUser) {
        setUser(updatedUser)
      },
    }),
    [acceptAuth, accessToken, isBooting, request, requestBlob, user],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
