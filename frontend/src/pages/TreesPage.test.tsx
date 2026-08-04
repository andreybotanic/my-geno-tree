import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { FamilyTree } from '../api/generated'
import type { AuthorizedJsonRequest } from '../api/client'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import i18n from '../i18n'
import { defaultBloodNetworkPresets } from '../bloodNetwork'
import { TreesPage } from './TreesPage'

const tree: FamilyTree = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a5',
  name: 'Family history',
  personCount: 3,
  createdAt: '2026-07-30T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
  etag: '"tree-current"',
}

function context(request: AuthorizedJsonRequest): AuthContextValue {
  return {
    accessToken: 'access',
    isBooting: false,
    user: {
      id: '019861c4-6a93-7a2e-8ca8-51813740d4a6',
      username: 'family_admin',
      email: 'family@example.test',
    preferredLanguage: 'en',
    bloodNetworkPresets: defaultBloodNetworkPresets(),
      avatarUrl: null,
      createdAt: '2026-07-30T00:00:00+00:00',
    },
    login: vi.fn(),
    logout: vi.fn(),
    register: vi.fn(),
    request,
    requestBlob: vi.fn(async () => new Blob()),
    updateLanguage: vi.fn(),
    updateUser: vi.fn(),
  }
}

function renderPage(request: AuthorizedJsonRequest) {
  return render(
    <AuthContext value={context(request)}>
      <TreesPage />
    </AuthContext>,
  )
}

describe('TreesPage', () => {
  beforeEach(async () => {
    window.history.replaceState({}, '', '/trees')
    await i18n.changeLanguage('en')
  })

  it('lists tree tiles and opens a tree', async () => {
    const request = vi.fn(
      async () => ({ items: [tree] }),
    ) as unknown as AuthorizedJsonRequest
    renderPage(request)

    expect(await screen.findByText('Family history')).toBeInTheDocument()
    expect(screen.getByText(/3 people/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    expect(window.location.pathname).toBe(`/trees/${tree.id}/tree`)
  })

  it('creates a tree from the modal and opens its editor', async () => {
    const created = { ...tree, id: '019861c4-6a93-7a2e-8ca8-51813740d4a7' }
    const request = vi.fn(
      async (_path: string, init?: RequestInit) => (
        init?.method === 'POST' ? created : { items: [] }
      ),
    ) as unknown as AuthorizedJsonRequest
    renderPage(request)

    await screen.findByText('You do not have any family trees yet.')
    await userEvent.click(
      screen.getByRole('button', { name: 'Create tree' }),
    )
    await userEvent.type(screen.getByLabelText('Tree name'), 'New family')
    const createDialog = screen.getByRole('dialog', {
      name: 'Create a family tree',
    })
    await userEvent.click(
      within(createDialog).getByRole('button', { name: 'Create tree' }),
    )

    await waitFor(() => {
      expect(window.location.pathname).toBe(`/trees/${created.id}/tree`)
    })
    expect(request).toHaveBeenCalledWith('/trees', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ name: 'New family' }),
    }))
  })

  it('renames and then irreversibly deletes a tree', async () => {
    let items = [tree]
    const request = vi.fn(async (
      _path: string,
      init?: RequestInit,
    ) => {
      if (init?.method === 'PATCH') {
        items = [{ ...tree, name: 'Renamed', etag: '"tree-new"' }]
        return items[0]
      }
      if (init?.method === 'DELETE') {
        items = []
        return undefined
      }

      return { items }
    }) as unknown as AuthorizedJsonRequest
    renderPage(request)

    await screen.findByText('Family history')
    await userEvent.click(screen.getByRole('button', { name: 'Rename' }))
    const nameInput = screen.getByLabelText('Tree name')
    await userEvent.clear(nameInput)
    await userEvent.type(nameInput, 'Renamed')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Renamed')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(
      screen.getByRole('alertdialog', { name: 'Delete tree?' }),
    ).toHaveTextContent('Renamed')
    const deleteDialog = screen.getByRole('alertdialog', {
      name: 'Delete tree?',
    })
    await userEvent.click(
      within(deleteDialog).getByRole('button', { name: 'Delete' }),
    )
    expect(
      await screen.findByText('You do not have any family trees yet.'),
    ).toBeInTheDocument()
  })

  it('keeps the rename dialog open on a version conflict', async () => {
    const request = vi.fn(async (
      _path: string,
      init?: RequestInit,
    ) => {
      if (init?.method === 'PATCH') {
        const { ApiRequestError } = await import('../api/client')
        throw new ApiRequestError(412, {
          code: 'TREE_VERSION_CONFLICT',
          message: 'tree_version_conflict',
          fields: {},
          details: {},
        })
      }
      return { items: [tree] }
    }) as unknown as AuthorizedJsonRequest
    renderPage(request)

    await screen.findByText('Family history')
    await userEvent.click(screen.getByRole('button', { name: 'Rename' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(
      await screen.findByText(/changed in another session/),
    ).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
