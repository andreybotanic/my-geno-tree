import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { User } from '../api/generated'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import i18n from '../i18n'
import { defaultBloodNetworkPresets } from '../bloodNetwork'
import { ProfilePage } from './ProfilePage'

vi.mock('../components/ImageCropDialog', () => ({
  ImageCropDialog: ({
    onSave,
  }: {
    onSave: (
      file: File,
      crop: { x: number; y: number; width: number; height: number },
    ) => Promise<void>
  }) => (
    <button
      onClick={() => void onSave(
        new File(['avatar'], 'avatar.png', { type: 'image/png' }),
        { x: 0, y: 0, width: 100, height: 100 },
      )}
      type="button"
    >
      Save mock crop
    </button>
  ),
}))

const user: User = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a5',
  username: 'family_admin',
  email: 'family@example.test',
  preferredLanguage: 'en',
  bloodNetworkPresets: defaultBloodNetworkPresets(),
  avatarUrl: '/me/avatar?media=old',
  createdAt: '2026-07-31T00:00:00+00:00',
}

describe('ProfilePage media', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('replaces and confirms deletion of the private avatar', async () => {
    const updated = { ...user, avatarUrl: '/me/avatar?media=new' }
    const removed = { ...user, avatarUrl: null }
    const requestMock = vi.fn(async (_path: string, init?: RequestInit) => (
      init?.method === 'DELETE' ? removed : updated
    ))
    const request = requestMock as unknown as AuthContextValue['request']
    const updateUser = vi.fn()
    const value: AuthContextValue = {
      accessToken: 'access',
      isBooting: false,
      user,
      login: vi.fn(),
      logout: vi.fn(),
      register: vi.fn(),
      request,
      requestBlob: vi.fn(async () => new Blob(['image'])),
      updateLanguage: vi.fn(),
      updateUser,
    }
    render(
      <AuthContext value={value}>
        <ProfilePage />
      </AuthContext>,
    )

    await userEvent.click(screen.getByRole('button', {
      name: 'Replace avatar',
    }))
    await userEvent.click(screen.getByRole('button', {
      name: 'Save mock crop',
    }))
    expect(updateUser).toHaveBeenCalledWith(updated)
    const uploadCall = requestMock.mock.calls.find(
      ([path, init]) => path === '/me/avatar' && init?.method === 'POST',
    )
    expect(uploadCall?.[1]?.body).toBeInstanceOf(FormData)

    await userEvent.click(screen.getByRole('button', {
      name: 'Delete avatar',
    }))
    const dialog = screen.getByRole('dialog', { name: 'Delete avatar?' })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Remove' }),
    )
    expect(updateUser).toHaveBeenLastCalledWith(removed)
  })
})
