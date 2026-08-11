import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import { AuthenticatedImage } from './AuthenticatedImage'

function context(requestBlob: AuthContextValue['requestBlob']): AuthContextValue {
  return {
    accessToken: 'access',
    isBooting: false,
    user: null,
    login: vi.fn(),
    logout: vi.fn(),
    register: vi.fn(),
    request: vi.fn(),
    requestBlob,
    updateLanguage: vi.fn(),
    updateUser: vi.fn(),
  }
}

describe('AuthenticatedImage', () => {
  it('loads a private blob and falls back after a failed replacement', async () => {
    const requestBlob = vi.fn(async (url: string) => {
      if (url === '/broken') {
        throw new Error('unavailable')
      }
      return new Blob(['image'])
    })
    const { rerender } = render(
      <AuthContext value={context(requestBlob)}>
        <AuthenticatedImage
          alt="Portrait"
          fallback={<span>No image</span>}
          url="/private-first"
        />
      </AuthContext>,
    )

    expect(screen.getByText('No image')).toBeInTheDocument()
    expect(await screen.findByRole('img', { name: 'Portrait' }))
      .toHaveAttribute('src', 'blob:test-image')
    expect(requestBlob).toHaveBeenCalledWith('/private-first')

    rerender(
      <AuthContext value={context(requestBlob)}>
        <AuthenticatedImage
          alt="Portrait"
          fallback={<span>No image</span>}
          url="/broken"
        />
      </AuthContext>,
    )
    await waitFor(() => {
      expect(screen.getByText('No image')).toBeInTheDocument()
    })
  })

  it('shares one pending media request between matching images', async () => {
    let resolveBlob: ((blob: Blob) => void) | undefined
    const requestBlob = vi.fn(() => new Promise<Blob>((resolve) => {
      resolveBlob = resolve
    }))
    render(
      <AuthContext value={context(requestBlob)}>
        <AuthenticatedImage
          alt="First portrait"
          fallback={<span>First placeholder</span>}
          url="/private-pending"
        />
        <AuthenticatedImage
          alt="Second portrait"
          fallback={<span>Second placeholder</span>}
          url="/private-pending"
        />
      </AuthContext>,
    )

    await waitFor(() => expect(requestBlob).toHaveBeenCalledOnce())
    resolveBlob?.(new Blob(['image']))

    expect(await screen.findByRole('img', { name: 'First portrait' }))
      .toBeInTheDocument()
    expect(await screen.findByRole('img', { name: 'Second portrait' }))
      .toBeInTheDocument()
  })

  it('reuses a successful blob after the image component is remounted', async () => {
    const requestBlob = vi.fn(async () => new Blob(['image']))
    const first = render(
      <AuthContext value={context(requestBlob)}>
        <AuthenticatedImage
          alt="Cached portrait"
          fallback={<span>No image</span>}
          url="/private-cached"
        />
      </AuthContext>,
    )

    expect(await screen.findByRole('img', { name: 'Cached portrait' }))
      .toBeInTheDocument()
    expect(requestBlob).toHaveBeenCalledOnce()
    first.unmount()

    render(
      <AuthContext value={context(requestBlob)}>
        <AuthenticatedImage
          alt="Cached portrait"
          fallback={<span>No image</span>}
          url="/private-cached"
        />
      </AuthContext>,
    )

    expect(await screen.findByRole('img', { name: 'Cached portrait' }))
      .toBeInTheDocument()
    expect(requestBlob).toHaveBeenCalledOnce()
  })
})
