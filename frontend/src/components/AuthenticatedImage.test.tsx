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
          url="/private"
        />
      </AuthContext>,
    )

    expect(screen.getByText('No image')).toBeInTheDocument()
    expect(await screen.findByRole('img', { name: 'Portrait' }))
      .toHaveAttribute('src', 'blob:test-image')
    expect(requestBlob).toHaveBeenCalledWith('/private')

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
})
