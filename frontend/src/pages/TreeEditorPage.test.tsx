import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { AuthorizedJsonRequest } from '../api/client'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import i18n from '../i18n'
import { TreeEditorPage } from './TreeEditorPage'

const treeId = '019861c4-6a93-7a2e-8ca8-51813740d4a5'

function renderEditor(items: unknown[]) {
  const request = vi.fn(
    async () => ({ items }),
  ) as unknown as AuthorizedJsonRequest
  const value: AuthContextValue = {
    accessToken: 'access',
    isBooting: false,
    user: null,
    login: vi.fn(),
    logout: vi.fn(),
    register: vi.fn(),
    request,
    updateLanguage: vi.fn(),
  }

  render(
    <AuthContext value={value}>
      <TreeEditorPage treeId={treeId} />
    </AuthContext>,
  )
}

describe('TreeEditorPage', () => {
  beforeEach(async () => {
    window.history.replaceState({}, '', `/trees/${treeId}`)
    await i18n.changeLanguage('en')
  })

  it('shows the empty editor for an owned tree', async () => {
    renderEditor([{
      id: treeId,
      name: 'Family history',
      personCount: 0,
      createdAt: '2026-07-31T00:00:00+00:00',
      updatedAt: '2026-07-31T00:00:00+00:00',
      etag: '"tree-current"',
    }])

    expect(await screen.findByText('Family history')).toBeInTheDocument()
    expect(screen.getByText('This tree is empty.')).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('button', { name: /My trees/ }),
    )
    expect(window.location.pathname).toBe('/trees')
  })

  it('does not reveal a missing or foreign tree', async () => {
    renderEditor([])

    expect(
      await screen.findByText('This tree does not exist or is unavailable.'),
    ).toBeInTheDocument()
  })
})
