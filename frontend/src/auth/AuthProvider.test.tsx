import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEffect } from 'react'
import type { AuthResponse, User } from '../api/generated'
import type { AuthContextValue } from './AuthContext'
import { ApiRequestError } from '../api/client'
import { defaultBloodNetworkPresets } from '../bloodNetwork'
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
  bloodNetworkPresets: defaultBloodNetworkPresets(),
  avatarUrl: null,
  createdAt: '2026-07-31T00:00:00+00:00',
}

function auth(accessToken: string): AuthResponse {
  return {
    accessToken,
    accessTokenExpiresAt: new Date(Date.now() + 3600000).toISOString(),
    user,
  }
}

function Harness({
  onRequestReferences,
}: {
  onRequestReferences?: (request: AuthContextValue['request'], requestBlob: AuthContextValue['requestBlob']) => void
}) {
  const session = useAuth()

  useEffect(() => {
    onRequestReferences?.(session.request, session.requestBlob)
  }, [onRequestReferences, session.request, session.requestBlob])

  return (
    <>
      <output>{session.isBooting ? 'booting' : session.user?.username}</output>
      <button
        onClick={() => void session.requestBlob('/private-image')}
        type="button"
      >
        Load image
      </button>
      <button
        onClick={() => void session.request('/private-data')}
        type="button"
      >
        Load data
      </button>
      <button
        onClick={() => void Promise.all([
          session.request('/private-data'),
          session.requestBlob('/private-image'),
        ])}
        type="button"
      >
        Load data and image
      </button>
    </>
  )
}

describe('AuthProvider protected blobs', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.refresh.mockResolvedValue(auth('initial-token'))
    api.blobRequest.mockResolvedValue(new Blob(['image']))
    api.jsonRequest.mockResolvedValue({})
  })

  it('refreshes known expired credentials once before concurrent requests without sending an expired token', async () => {
    api.refresh
      .mockResolvedValueOnce({ ...auth('expired-token'), accessTokenExpiresAt: new Date(Date.now() - 1000).toISOString() })
      .mockResolvedValueOnce(auth('fresh-token'))
    render(<AuthProvider><Harness /></AuthProvider>)
    expect(await screen.findByText('anna')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Load data and image' }))
    await waitFor(() => {
      expect(api.jsonRequest).toHaveBeenCalledOnce()
      expect(api.blobRequest).toHaveBeenCalledOnce()
    })
    expect(api.refresh).toHaveBeenCalledTimes(2)
    for (const request of [api.jsonRequest, api.blobRequest]) {
      const init = request.mock.calls[0]?.[1] as RequestInit
      expect(new Headers(init.headers).get('Authorization')).toBe('Bearer fresh-token')
    }
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

  it('retries a JSON request after one shared refresh without changing request identities', async () => {
    const references: Array<readonly [AuthContextValue['request'], AuthContextValue['requestBlob']]> = []
    api.refresh
      .mockResolvedValueOnce(auth('expired-token'))
      .mockResolvedValueOnce(auth('refreshed-token'))
    api.jsonRequest
      .mockRejectedValueOnce(new ApiRequestError(401, {
        code: 'UNAUTHORIZED',
        message: 'unauthorized',
        fields: {},
        details: {},
      }))
      .mockResolvedValueOnce({})

    render(
      <AuthProvider>
        <Harness onRequestReferences={(request, requestBlob) => {
          references.push([request, requestBlob])
        }} />
      </AuthProvider>,
    )
    expect(await screen.findByText('anna')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Load data' }))
    await waitFor(() => expect(api.jsonRequest).toHaveBeenCalledTimes(2))

    expect(api.refresh).toHaveBeenCalledTimes(2)
    const retried = api.jsonRequest.mock.calls[1]?.[1] as RequestInit
    expect(new Headers(retried.headers).get('Authorization'))
      .toBe('Bearer refreshed-token')
    expect(references).toHaveLength(1)
  })

  it('shares one refresh between simultaneous JSON and blob retries', async () => {
    api.refresh
      .mockResolvedValueOnce(auth('expired-token'))
      .mockResolvedValueOnce(auth('refreshed-token'))
    const unauthorized = new ApiRequestError(401, {
      code: 'UNAUTHORIZED',
      message: 'unauthorized',
      fields: {},
      details: {},
    })
    api.jsonRequest
      .mockRejectedValueOnce(unauthorized)
      .mockResolvedValueOnce({})
    api.blobRequest
      .mockRejectedValueOnce(unauthorized)
      .mockResolvedValueOnce(new Blob(['image']))

    render(<AuthProvider><Harness /></AuthProvider>)
    expect(await screen.findByText('anna')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Load data and image' }))
    await waitFor(() => {
      expect(api.jsonRequest).toHaveBeenCalledTimes(2)
      expect(api.blobRequest).toHaveBeenCalledTimes(2)
    })

    expect(api.refresh).toHaveBeenCalledTimes(2)
    for (const init of [api.jsonRequest.mock.calls[1]?.[1], api.blobRequest.mock.calls[1]?.[1]]) {
      expect(new Headers((init as RequestInit).headers).get('Authorization'))
        .toBe('Bearer refreshed-token')
    }
  })
})
