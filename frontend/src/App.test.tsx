import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { StrictMode } from 'react'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import App from './App'
import { AuthProvider } from './auth/AuthProvider'
import i18n from './i18n'

const apiError = {
  code: 'UNAUTHORIZED',
  message: 'authentication_required',
  fields: {},
  details: {},
}

const authenticated = {
  accessToken: 'access-token',
  accessTokenExpiresAt: new Date(Date.now() + 3600000).toISOString(),
  user: {
    id: '019861c4-6a93-7a2e-8ca8-51813740d4a5',
    username: 'family_admin',
    email: 'family@example.test',
    preferredLanguage: 'en',
    avatarUrl: null,
    createdAt: '2026-07-31T00:00:00+00:00',
  },
}

function renderApp() {
  return render(
    <StrictMode>
      <AuthProvider>
        <App />
      </AuthProvider>
    </StrictMode>,
  )
}

describe('App', () => {
  beforeEach(async () => {
    window.history.replaceState({}, '', '/')
    await i18n.changeLanguage('en')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/auth/refresh')) {
          return Promise.resolve(
            Response.json(apiError, { status: 401 }),
          )
        }
        if (url.endsWith('/statistics')) {
          return Promise.resolve(Response.json({
            accountCount: 12,
            treeCount: 8,
            personCount: 1234,
          }))
        }
        if (url.endsWith('/trees')) {
          return Promise.resolve(Response.json({ items: [] }))
        }
        return Promise.resolve(Response.json({ status: 'ok' }))
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the localized product shell and public counters', async () => {
    renderApp()

    expect(
      screen.getByRole('heading', {
        name: 'Keep every family story connected.',
      }),
    ).toBeInTheDocument()

    await waitFor(() => {
      expect(screen.getByText('12')).toBeInTheDocument()
      expect(screen.getByText('8')).toBeInTheDocument()
      expect(screen.getByText('1234')).toBeInTheDocument()
    })
    expect(screen.getByText('Total people')).toBeInTheDocument()
    expect(
      await screen.findByRole('button', { name: 'Sign in / Sign up' }),
    ).toBeInTheDocument()
    expect(
      vi.mocked(fetch).mock.calls.filter(([input]) =>
        String(input).endsWith('/auth/refresh'),
      ),
    ).toHaveLength(1)
  })

  it('opens the shared login form in a modal', async () => {
    renderApp()

    await userEvent.click(
      await screen.findByRole('button', { name: 'Sign in / Sign up' }),
    )

    expect(
      screen.getByRole('dialog', { name: 'Welcome back' }),
    ).toBeInTheDocument()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('opens the default authenticated experience on the trees page', async () => {
    vi.mocked(fetch).mockImplementation(
      (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)
        if (url.endsWith('/auth/refresh')) {
          return Promise.resolve(Response.json(apiError, { status: 401 }))
        }
        if (url.endsWith('/auth/login') && init?.method === 'POST') {
          return Promise.resolve(Response.json(authenticated))
        }
        if (url.endsWith('/trees')) {
          return Promise.resolve(Response.json({ items: [] }))
        }
        return Promise.resolve(Response.json({ status: 'ok' }))
      },
    )
    renderApp()

    await userEvent.click(
      await screen.findByRole('button', { name: 'Sign in / Sign up' }),
    )
    await userEvent.type(
      screen.getByLabelText('Username or email'),
      'family_admin',
    )
    await userEvent.type(screen.getByLabelText('Password'), 'StrongPass1!')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(
      await screen.findByRole('heading', { name: 'My trees' }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe('/trees')
  })

  it('persists a guest language selected from the header', async () => {
    renderApp()

    await userEvent.click(
      await screen.findByRole('button', { name: 'Language' }),
    )
    expect(screen.getByTestId('language-flag-ru')).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('option', { name: 'Русский' }),
    )

    expect(
      screen.getByRole('heading', {
        name: 'Сохраните связь каждой семейной истории.',
      }),
    ).toBeInTheDocument()
    expect(document.cookie).toContain('mygenotree_language=ru')
  })

  it('shows an unavailable state for an unsuccessful API response', async () => {
    vi.mocked(fetch).mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      return Promise.resolve(
        url.endsWith('/auth/refresh')
          ? Response.json(apiError, { status: 401 })
          : new Response(null, { status: 503 }),
      )
    })

    renderApp()

    expect(
      await screen.findByText('Local API is unavailable'),
    ).toBeInTheDocument()
  })

  it('returns to a protected route after login', async () => {
    window.history.replaceState({}, '', '/trees')
    vi.mocked(fetch).mockImplementation(
      (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)
        if (url.endsWith('/auth/refresh')) {
          return Promise.resolve(Response.json(apiError, { status: 401 }))
        }
        if (url.endsWith('/auth/login') && init?.method === 'POST') {
          return Promise.resolve(Response.json(authenticated))
        }
        if (url.endsWith('/trees')) {
          return Promise.resolve(Response.json({ items: [] }))
        }
        return Promise.resolve(Response.json({ status: 'ok' }))
      },
    )
    renderApp()

    expect(
      await screen.findByRole('heading', { name: 'Sign in to continue' }),
    ).toBeInTheDocument()
    await userEvent.type(
      screen.getByLabelText('Username or email'),
      'family_admin',
    )
    await userEvent.type(screen.getByLabelText('Password'), 'StrongPass1!')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(
      await screen.findByRole('heading', { name: 'My trees' }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe('/trees')
  })

  it('does not flash the home page while restoring a protected route', async () => {
    window.history.replaceState({}, '', '/trees')
    let resolveRefresh!: (response: Response) => void
    const refreshResponse = new Promise<Response>((resolve) => {
      resolveRefresh = resolve
    })
    vi.mocked(fetch).mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/auth/refresh')) return refreshResponse
      if (url.endsWith('/trees')) {
        return Promise.resolve(Response.json({ items: [] }))
      }
      return Promise.resolve(Response.json({ status: 'ok' }))
    })

    renderApp()

    expect(screen.getByRole('status')).toHaveTextContent('Loading…')
    expect(screen.queryByRole('heading', {
      name: 'Keep every family story connected.',
    })).not.toBeInTheDocument()

    resolveRefresh(Response.json(authenticated))
    expect(
      await screen.findByRole('heading', { name: 'My trees' }),
    ).toBeInTheDocument()
  })

  it('refreshes an expired access token and retries an authorized request', async () => {
    window.history.replaceState({}, '', '/trees')
    const renewed = {
      ...authenticated,
      accessToken: 'renewed-access-token',
    }
    let refreshCount = 0
    const treeAuthorizations: Array<string | null> = []
    vi.mocked(fetch).mockImplementation(
      (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)
        if (url.endsWith('/auth/refresh')) {
          refreshCount += 1
          return Promise.resolve(Response.json(
            refreshCount === 1 ? authenticated : renewed,
          ))
        }
        if (url.endsWith('/trees')) {
          const authorization = new Headers(init?.headers)
            .get('Authorization')
          treeAuthorizations.push(authorization)

          return Promise.resolve(
            authorization === 'Bearer renewed-access-token'
              ? Response.json({ items: [] })
              : Response.json(apiError, { status: 401 }),
          )
        }

        return Promise.resolve(Response.json({ status: 'ok' }))
      },
    )

    renderApp()

    expect(
      await screen.findByText('You do not have any family trees yet.'),
    ).toBeInTheDocument()
    expect(refreshCount).toBe(2)
    expect(treeAuthorizations).toContain('Bearer access-token')
    expect(treeAuthorizations).toContain('Bearer renewed-access-token')
  })

  it('restores a session, updates the profile language and logs out', async () => {
    window.history.replaceState({}, '', '/profile')
    const russianUser = {
      ...authenticated.user,
      preferredLanguage: 'ru',
    }
    vi.mocked(fetch).mockImplementation(
      (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)
        if (url.endsWith('/auth/refresh')) {
          return Promise.resolve(Response.json(authenticated))
        }
        if (url.endsWith('/me') && init?.method === 'PATCH') {
          return Promise.resolve(Response.json(russianUser))
        }
        if (url.endsWith('/auth/logout')) {
          expect(init?.headers).toBeUndefined()
          return Promise.resolve(new Response(null, { status: 204 }))
        }
        if (url.endsWith('/trees')) {
          return Promise.resolve(Response.json({ items: [] }))
        }
        if (url.endsWith('/statistics')) {
          return Promise.resolve(Response.json({
            accountCount: 1,
            treeCount: 0,
            personCount: 0,
          }))
        }
        return Promise.resolve(Response.json({ status: 'ok' }))
      },
    )
    renderApp()

    expect(
      await screen.findByRole('heading', { name: 'Profile' }),
    ).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('button', { name: 'Interface language' }),
    )
    await userEvent.click(
      screen.getByRole('option', { name: 'Русский' }),
    )
    expect(
      await screen.findByText('Язык сохранён.'),
    ).toBeInTheDocument()

    await userEvent.click(
      screen.getByRole('button', { name: 'Открыть меню профиля' }),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Мои деревья' }))
    expect(window.location.pathname).toBe('/trees')

    await userEvent.click(
      screen.getByRole('button', { name: 'Открыть меню профиля' }),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Выйти' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Сохраните связь каждой семейной истории.',
      }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe('/')
  })

  it('shows the login with an icon menu and closes it outside', async () => {
    window.history.replaceState({}, '', '/profile')
    vi.mocked(fetch).mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/auth/refresh')) {
        return Promise.resolve(Response.json(authenticated))
      }
      return Promise.resolve(Response.json({ status: 'ok' }))
    })
    renderApp()

    const profileHeading = await screen.findByRole('heading', {
      name: 'Profile',
    })
    const trigger = screen.getByRole('button', {
      name: 'Open profile menu',
    })
    expect(within(trigger).getByText('family_admin')).toBeInTheDocument()

    await userEvent.click(trigger)
    for (const name of ['Profile', 'My trees', 'Log out']) {
      expect(
        screen.getByRole('button', { name }).querySelector('svg'),
      ).not.toBeNull()
    }

    await userEvent.click(profileHeading)
    expect(
      screen.queryByRole('button', { name: 'My trees' }),
    ).not.toBeInTheDocument()
  })
})
