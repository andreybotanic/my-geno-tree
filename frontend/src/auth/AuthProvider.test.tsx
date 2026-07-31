import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthResponse, User } from '../api/generated'
import { ApiRequestError } from '../api/client'
import { useAuth } from './AuthContext'
import { AuthProvider } from './AuthProvider'

const api = vi.hoisted(() => ({
  blobRequest: vi.fn(),
  jsonRequest: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  refresh: vi.fn(),
  register: vi.fn(),
}))

vi.mock('../api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/client')>()

  return {
    ...actual,
    authApi: {
      login: api.login,
      logout: api.logout,
      refresh: api.refresh,
      register: api.register,
    },
    blobRequest: api.blobRequest,
    jsonRequest: api.jsonRequest,
  }
})

const user: User = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a6',
  username: 'anna',
  email: 'anna@example.test',
  preferredLanguage: 'en',
  avatarUrl: null,
  createdAt: '2026-07-31T00:00:00+00:00',
}

function auth(accessToken: string): AuthResponse {
  return {
    accessToken,
    accessTokenExpiresAt: '2026-07-31T01:00:00+00:00',
    user,
  }
}

function Harness() {
  const session = useAuth()

  return (
    <>
      <output>{session.isBooting ? 'booting' : session.user?.username}</output>
      <button
        onClick={() => void session.requestBlob('/private-image')}
        type="button"
      >
        Load image
      </button>
    </>
  )
}

describe('AuthProvider protected blobs', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.refresh.mockResolvedValue(auth('initial-token'))
    api.blobRequest.mockResolvedValue(new Blob(['image']))
  })

  it('loads a protected blob with the in-memory access token', async () => {
    render(<AuthProvider><Harness /></AuthProvider>)
    expect(await screen.findByText('anna')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Load image' }))
    await waitFor(() => expect(api.blobRequest).toHaveBeenCalledOnce())
    const init = api.blobRequest.mock.calls[0]?.[1] as RequestInit
    expect(new Headers(init.headers).get('Authorization'))
      .toBe('Bearer initial-token')
  })

  it('refreshes once and retries a blob after an expired access token', async () => {
    api.refresh
      .mockResolvedValueOnce(auth('expired-token'))
      .mockResolvedValueOnce(auth('refreshed-token'))
    api.blobRequest
      .mockRejectedValueOnce(new ApiRequestError(401, {
        code: 'UNAUTHORIZED',
        message: 'unauthorized',
        fields: {},
        details: {},
      }))
      .mockResolvedValueOnce(new Blob(['image']))
    render(<AuthProvider><Harness /></AuthProvider>)
    expect(await screen.findByText('anna')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Load image' }))
    await waitFor(() => expect(api.blobRequest).toHaveBeenCalledTimes(2))
    const retried = api.blobRequest.mock.calls[1]?.[1] as RequestInit
    expect(new Headers(retried.headers).get('Authorization'))
      .toBe('Bearer refreshed-token')
  })
})
