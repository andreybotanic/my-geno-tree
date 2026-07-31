import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type {
  GenealogicalEvent,
  Person,
  TreeSnapshot,
} from '../api/generated'
import {
  ApiRequestError,
  type AuthorizedJsonRequest,
} from '../api/client'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import i18n from '../i18n'
import { TreeEditorPage } from './TreeEditorPage'

const treeId = '019861c4-6a93-7a2e-8ca8-51813740d4a5'
const person: Person = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a6',
  birthSurname: { state: 'known', value: 'Ivanova' },
  givenName: { state: 'known', value: 'Anna' },
  patronymic: { state: 'unknown' },
  gender: 'female',
  birthDate: {
    state: 'known',
    precision: 'year',
    year: 1900,
    approximate: false,
  },
  deathDate: null,
  photoThumbnailUrl: null,
  coordinates: { x: 0, y: 0 },
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
}
const event: GenealogicalEvent = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a7',
  personId: person.id,
  type: 'birth',
  source: 'automatic',
  date: person.birthDate,
  title: null,
  description: null,
  data: {},
  sourceEntityType: 'person',
  sourceEntityId: person.id,
  manualOrder: null,
  createdAt: person.createdAt,
  updatedAt: person.updatedAt,
}

function snapshot(people: Person[] = []): TreeSnapshot {
  return {
    tree: {
      id: treeId,
      name: 'Family history',
      createdAt: '2026-07-31T00:00:00+00:00',
      updatedAt: '2026-07-31T00:00:00+00:00',
    },
    etag: '"tree-current"',
    people,
    parentLinks: [],
    marriages: [],
  }
}

function renderEditor(request: AuthorizedJsonRequest) {
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

  it('creates the first fully unknown person from the empty editor', async () => {
    const created = snapshot([{
      ...person,
      birthSurname: { state: 'unknown' },
      givenName: { state: 'unknown' },
      birthDate: { state: 'unknown' },
    }])
    const request = vi.fn(async (
      _path: string,
      init?: RequestInit,
    ) => init?.method === 'POST' ? created : snapshot()) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    expect(await screen.findByText('Family history')).toBeInTheDocument()
    expect(screen.getByText('This tree is empty.')).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('button', { name: 'Create person' }),
    )
    const dialog = screen.getByRole('dialog', {
      name: 'Create the first person',
    })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    expect(
      await screen.findByRole('article', { name: 'Unknown person' }),
    ).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/graph-mutations`,
      expect.objectContaining({
        method: 'POST',
        headers: { 'If-Match': '"tree-current"' },
      }),
    )
  })

  it('loads person details lazily and autosaves one field', async () => {
    const renamed = {
      ...person,
      givenName: { state: 'known', value: 'Maria' } as const,
    }
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return init?.method === 'PATCH'
          ? { person: renamed, etag: '"tree-next"' }
          : person
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    expect(
      await screen.findByRole('complementary', { name: 'Person details' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Birth')).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )
    const input = screen.getByRole('textbox', { name: 'Given name' })
    await userEvent.clear(input)
    await userEvent.type(input, 'Maria{Enter}')

    expect(await screen.findByText('Maria')).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/people/${person.id}`,
      expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({
          givenName: { state: 'known', value: 'Maria' },
        }),
        headers: { 'If-Match': '"tree-current"' },
      }),
    )
  })

  it('stops autosaving and preserves the local value after a conflict', async () => {
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        if (init?.method === 'PATCH') {
          throw new ApiRequestError(412, {
            code: 'TREE_VERSION_CONFLICT',
            message: 'tree_version_conflict',
            fields: {},
            details: {},
          })
        }
        return person
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )
    const input = screen.getByRole('textbox', { name: 'Given name' })
    await userEvent.clear(input)
    await userEvent.type(input, 'Local value{Enter}')

    expect(
      await screen.findByText(/changed in another session/),
    ).toBeInTheDocument()
    expect(screen.getByDisplayValue('Local value')).toBeInTheDocument()
  })

  it('does not reveal a missing or foreign tree', async () => {
    const request = vi.fn(async () => {
      throw new ApiRequestError(404, {
        code: 'NOT_FOUND',
        message: 'not_found',
        fields: {},
        details: {},
      })
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    expect(
      await screen.findByText('This tree does not exist or is unavailable.'),
    ).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('button', { name: /My trees/ }),
    )
    expect(window.location.pathname).toBe('/trees')
  })

  it('retries a failed snapshot request', async () => {
    let attempts = 0
    const request = vi.fn(async () => {
      attempts += 1
      if (attempts === 1) {
        throw new Error('offline')
      }
      return snapshot()
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    expect(await screen.findByText('The tree could not be loaded.'))
      .toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    await waitFor(() => {
      expect(screen.getByText('Family history')).toBeInTheDocument()
    })
  })
})
