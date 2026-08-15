import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type {
  GenealogicalEvent,
  MarriagePeriod,
  ParentLink,
  Person,
  SearchRoutePlan,
  TreeSnapshot,
  User,
} from '../api/generated'
import {
  ApiRequestError,
  type AuthorizedJsonRequest,
} from '../api/client'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import i18n from '../i18n'
import { defaultBloodNetworkPresets } from '../bloodNetwork'
import { TreeEditorPage } from './TreeEditorPage'

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
        new File(['photo'], 'photo.webp', { type: 'image/webp' }),
        { x: 10, y: 20, width: 90, height: 120 },
      )}
      type="button"
    >
      Save photo crop
    </button>
  ),
}))

vi.mock('maplibre-gl', () => {
  class Map {
    addControl() {}
    jumpTo() {}
    off() {}
    on() {}
    once(_type: string, callback: () => void) { callback() }
    remove() {}
  }

  class Marker {
    addTo() { return this }
    getLngLat() { return { lat: 0, lng: 0 } }
    on() { return this }
    remove() {}
    setLngLat() { return this }
  }

  return {
    Map,
    Marker,
    NavigationControl: class {},
    setMaxParallelImageRequests: vi.fn(),
  }
})

const treeId = '019861c4-6a93-7a2e-8ca8-51813740d4a5'
const person: Person = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a6',
  birthSurname: { state: 'known', value: 'Ivanova' },
  givenName: { state: 'known', value: 'Anna' },
  patronymic: { state: 'unknown' },
  gender: 'female',
  birthDate: {
    state: 'known',
    year: 1900,
    kind: 'exact',
  },
  deathDate: null,
  birthPlace: null,
  deathPlace: null,
  photoThumbnailUrl: null,
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
}
const event: GenealogicalEvent = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a7',
  personId: person.id,
  type: 'birth',
  source: 'automatic',
  date: person.birthDate,
  place: null,
  title: null,
  description: null,
  data: {},
  sourceEntityType: 'person',
  sourceEntityId: person.id,
  manualOrder: null,
  createdAt: person.createdAt,
  updatedAt: person.updatedAt,
}
const father: Person = {
  ...person,
  id: '019861c4-6a93-7a2e-8ca8-51813740d4b0',
  birthSurname: { state: 'known', value: 'Petrov' },
  givenName: { state: 'known', value: 'Ivan' },
  gender: 'male',
}
const spouse: Person = {
  ...person,
  id: '019861c4-6a93-7a2e-8ca8-51813740d4b1',
  birthSurname: { state: 'known', value: 'Ivanov' },
  givenName: { state: 'known', value: 'Petr' },
  gender: 'male',
}
const parentLink: ParentLink = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4c0',
  parentId: father.id,
  childId: person.id,
  role: 'father',
}
const marriage: MarriagePeriod = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4d0',
  malePersonId: spouse.id,
  femalePersonId: person.id,
  marriageDate: { state: 'unknown' },
  dissolutionDate: null,
  marriagePlace: null,
  dissolutionPlace: null,
  createdAt: person.createdAt,
  updatedAt: person.updatedAt,
}

function snapshot(people: Person[] = []): TreeSnapshot {
  return {
    tree: {
      id: treeId,
      name: 'Family history',
      reference: people[0] ? { type: 'person', personId: people[0].id } : null,
      createdAt: '2026-07-31T00:00:00+00:00',
      updatedAt: '2026-07-31T00:00:00+00:00',
    },
    etag: '"tree-current"',
    layoutRevision: people.length > 0 ? people.map((person) => person.id).join(':') : null,
    layout: null,
    people,
    parentLinks: [],
    marriages: [],
  }
}

function graphSnapshot(
  people: Person[],
  parentLinks: ParentLink[] = [],
  marriages: MarriagePeriod[] = [],
  etag = '"tree-current"',
): TreeSnapshot {
  return {
    ...snapshot(people),
    etag,
    parentLinks,
    marriages,
  }
}

function renderEditor(
  request: AuthorizedJsonRequest,
  user: User | null = null,
  importWarningCount: number | null = null,
) {
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
    updateUser: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <div id="header-breadcrumbs" />
      <TreeEditorPage importWarningCount={importWarningCount} treeId={treeId} />
    </AuthContext>,
  )
}

async function activateUnpressedUnknownButtons(container: HTMLElement) {
  for (const button of within(container).getAllByRole('button', {
    name: 'Unknown',
  })) {
    if (button.getAttribute('aria-pressed') === 'false') {
      await userEvent.click(button)
    }
  }
}

function sectionByHeading(name: string) {
  const section = screen.getByRole('heading', { name }).closest('section')
  if (!section) {
    throw new Error(`Section "${name}" was not found`)
  }

  return section
}

describe('TreeEditorPage', () => {
  beforeEach(async () => {
    window.history.replaceState({}, '', `/trees/${treeId}`)
    window.localStorage.clear()
    await i18n.changeLanguage('en')
    vi.unstubAllGlobals()
  })

  it('shows the import warning once when places remain text-only', async () => {
    renderEditor(vi.fn(async () => snapshot()) as unknown as AuthorizedJsonRequest, null, 3)

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Places kept as text because they could not be recognized: 3',
    )
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

    const breadcrumbs = await screen.findByRole('navigation', {
      name: 'Tree navigation',
    })
    expect(breadcrumbs).toHaveTextContent('My trees/Family history')
    expect(screen.getByText('This tree is empty.')).toBeInTheDocument()
    await userEvent.click(
      screen.getByRole('button', { name: 'Create person' }),
    )
    const dialog = screen.getByRole('dialog', {
      name: 'Create the first person',
    })
    await activateUnpressedUnknownButtons(dialog)
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    const createdCard = await screen.findByRole('article', { name: 'Unknown person' })
    expect(createdCard).toBeInTheDocument()
    expect(createdCard.closest('.react-flow__node')).toHaveStyle({
      height: '160px',
      width: '286px',
    })
    for (const name of ['Zoom out', 'Zoom in', 'Reset camera', 'Fit graph to view']) {
      expect(screen.getByRole('button', { name }).querySelector('svg')).not.toBeNull()
    }
    const resetPanel = screen.getByRole('button', { name: 'Reset camera' })
      .closest('.camera-reset-controls')
    const zoomPanel = screen.getByRole('button', { name: 'Zoom out' })
      .closest('.zoom-controls')
    if (!(resetPanel instanceof HTMLElement)) {
      throw new Error('The camera reset panel was not found.')
    }
    expect(within(resetPanel).getByRole('button', { name: 'Fit graph to view' }))
      .toBeInTheDocument()
    expect(resetPanel?.nextElementSibling).toBe(zoomPanel)
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/graph-mutations`,
      expect.objectContaining({
        method: 'POST',
        headers: { 'If-Match': '"tree-current"' },
      }),
    )
  })

  it('edits the zoom as an integer percentage within the available range', async () => {
    const request = vi.fn(async () => graphSnapshot([person])) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const zoom = await screen.findByRole('button', { name: 'Current zoom' })
    expect(zoom).toHaveTextContent('100%')
    await userEvent.click(zoom)
    const zoomInput = screen.getByRole('textbox', { name: 'Current zoom' })
    expect(zoomInput).toHaveValue('100')
    expect(zoomInput).toHaveFocus()
    expect(zoomInput).toHaveProperty('selectionStart', 3)
    expect(zoomInput).toHaveProperty('selectionEnd', 3)
    await userEvent.clear(zoomInput)
    await userEvent.type(zoomInput, '150{Enter}')
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Current zoom' })).toHaveTextContent('150%')
    })

    await userEvent.click(screen.getByRole('button', { name: 'Current zoom' }))
    const invalidInput = screen.getByRole('textbox', { name: 'Current zoom' })
    await userEvent.clear(invalidInput)
    await userEvent.type(invalidInput, '201{Enter}')
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Current zoom' })).toHaveTextContent('150%')
    })
  })

  it('fits the complete graph without zooming in past 100%', async () => {
    const request = vi.fn(async () => graphSnapshot([person])) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await userEvent.click(screen.getByRole('button', { name: 'Zoom in' }))
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Current zoom' })).toHaveTextContent('120%')
    })
    await userEvent.click(screen.getByRole('button', { name: 'Fit graph to view' }))
    await waitFor(() => {
      const zoom = Number(screen.getByRole('button', { name: 'Current zoom' })
        .textContent?.replace('%', ''))
      expect(zoom).toBeGreaterThanOrEqual(10)
      expect(zoom).toBeLessThanOrEqual(100)
    })
  })

  it('fits the blood network with its simulated node bounds', async () => {
    const request = vi.fn(async () => graphSnapshot([father, person], [parentLink])) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await userEvent.click(screen.getByRole('button', { name: 'Show blood network' }))
    await screen.findByRole('button', { name: 'Show family tree' })
    await userEvent.click(screen.getByRole('button', { name: 'Current zoom' }))
    const zoomInput = screen.getByRole('textbox', { name: 'Current zoom' })
    await userEvent.clear(zoomInput)
    await userEvent.type(zoomInput, '200{Enter}')
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Current zoom' })).toHaveTextContent('200%')
    })
    await userEvent.click(screen.getByRole('button', { name: 'Fit graph to view' }))
    await waitFor(() => {
      const zoom = Number(screen.getByRole('button', { name: 'Current zoom' })
        .textContent?.replace('%', ''))
      expect(zoom).toBeGreaterThanOrEqual(10)
      expect(zoom).toBeLessThanOrEqual(100)
    })
  })

  it('keeps the person context menu inside the viewport', async () => {
    vi.stubGlobal('innerWidth', 320)
    vi.stubGlobal('innerHeight', 240)
    const originalGetBoundingClientRect = HTMLElement.prototype.getBoundingClientRect
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function getBoundingClientRect(this: HTMLElement) {
        if (this.matches('menu.tree-context-menu')) {
          return {
            height: 180,
            width: 200,
          } as DOMRect
        }

        return originalGetBoundingClientRect.call(this)
      },
    )
    const request = vi.fn(async () => graphSnapshot([person])) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(card.closest('.react-flow__node')!, {
      clientX: 300,
      clientY: 220,
    })

    const menu = screen.getByRole('menu', { name: 'Person actions' })
    expect(menu).toHaveStyle({ left: '120px', top: '60px' })
  })

  it('sets a deduplicated spouse pair as reference from the person menu', async () => {
    const repeatedMarriage = { ...marriage, id: 'second-period' }
    const initial = {
      ...graphSnapshot([person, spouse], [], [marriage, repeatedMarriage]),
      tree: {
        ...snapshot().tree,
        reference: { type: 'person', personId: person.id } as const,
      },
    }
    const updated = {
      ...initial,
      etag: '"tree-reference"',
      tree: {
        ...initial.tree,
        reference: {
          type: 'marriage',
          personIds: [person.id, spouse.id] as [string, string],
        },
      },
    }
    const request = vi.fn(async (path: string, init?: RequestInit) => (
      path.endsWith('/reference') && init?.method === 'PUT' ? updated : initial
    )) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(card.closest('.react-flow__node')!, {
      clientX: 80,
      clientY: 120,
    })
    const menu = screen.getByRole('menu', { name: 'Person actions' })
    expect(within(menu).getAllByRole('menuitem')).toHaveLength(6)
    await userEvent.click(within(menu).getByRole('menuitem', {
      name: 'Set marriage with Ivanov Petr as reference',
    }))

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/reference`,
      expect.objectContaining({
        body: JSON.stringify({
          type: 'marriage',
          personIds: [person.id, spouse.id],
        }),
        headers: { 'If-Match': '"tree-current"' },
        method: 'PUT',
      }),
    ))
    expect(screen.getByRole('article', { name: 'Ivanova Anna' }))
      .toHaveClass('person-card-reference')
    expect(screen.getByRole('article', { name: 'Ivanov Petr' }))
      .toHaveClass('person-card-reference')
    expect(screen.getByRole('article', { name: 'Ivanova Anna' }))
      .toHaveClass('person-card-female')
    expect(screen.getByRole('article', { name: 'Ivanov Petr' }))
      .toHaveClass('person-card-male')
  })

  it('sets a married pair as reference from its bracket menu', async () => {
    const initial = graphSnapshot([person, spouse], [], [marriage])
    const updated = {
      ...initial,
      tree: {
        ...initial.tree,
        reference: {
          type: 'marriage',
          personIds: [person.id, spouse.id] as [string, string],
        },
      },
    }
    const request = vi.fn(async (path: string) => (
      path.endsWith('/reference') ? updated : initial
    )) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanov Petr' })
    const bracket = document.querySelector('.react-flow__edge')
    expect(bracket).not.toBeNull()
    fireEvent.contextMenu(bracket!, { clientX: 140, clientY: 160 })
    await userEvent.click(screen.getByRole('menuitem', {
      name: 'Set this marriage as reference',
    }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/reference`,
      expect.objectContaining({ method: 'PUT' }),
    ))
    fireEvent.contextMenu(bracket!, { clientX: 140, clientY: 160 })
    expect(screen.queryByRole('menuitem', {
      name: 'Set this marriage as reference',
    })).not.toBeInTheDocument()
  })

  it('opens a dialog and creates both parents from the person menu', async () => {
    const initial = graphSnapshot([person])
    const updated = graphSnapshot([
      person,
      { ...father, id: '019861c4-6a93-7a2e-8ca8-51813740d4e0' },
      { ...person, id: '019861c4-6a93-7a2e-8ca8-51813740d4e1' },
    ])
    const request = vi.fn(async (_path: string, init?: RequestInit) => (
      init?.method === 'POST' ? updated : initial
    )) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(card.closest('.react-flow__node')!, {
      clientX: 80,
      clientY: 120,
    })
    await userEvent.click(screen.getByRole('menuitem', { name: 'Add parents' }))

    const dialog = screen.getByRole('dialog', { name: 'Add parents' })
    expect((request as unknown as ReturnType<typeof vi.fn>).mock.calls)
      .not.toContainEqual([
        `/trees/${treeId}/graph-mutations`,
        expect.anything(),
      ])
    await activateUnpressedUnknownButtons(dialog)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/graph-mutations`,
      expect.objectContaining({
        body: JSON.stringify({
          type: 'add_parents',
          childId: person.id,
          father: {
            birthSurname: { state: 'unknown' },
            givenName: { state: 'unknown' },
            patronymic: { state: 'unknown' },
            gender: 'male',
            birthDate: { state: 'unknown' },
            birthPlace: null,
            deathDate: null,
            deathPlace: null,
          },
          marriage: null,
          mother: {
            birthSurname: { state: 'unknown' },
            givenName: { state: 'unknown' },
            patronymic: { state: 'unknown' },
            gender: 'female',
            birthDate: { state: 'unknown' },
            birthPlace: null,
            deathDate: null,
            deathPlace: null,
          },
        }),
        headers: { 'If-Match': '"tree-current"' },
        method: 'POST',
      }),
    ))
  })

  it('uses gendered spouse labels', async () => {
    const request = vi.fn(async () => graphSnapshot([person])) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(card.closest('.react-flow__node')!, {
      clientX: 80,
      clientY: 120,
    })
    expect(screen.getByRole('menuitem', { name: 'Add husband' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Add wife' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('menuitem', { name: 'Add husband' }))
    expect(screen.getByRole('dialog', { name: 'Add spouse' })).toBeInTheDocument()
  })

  it('hides spouse creation for a person with an unknown gender', async () => {
    const unknown = { ...person, gender: 'unknown' as const }
    const request = vi.fn(async () => graphSnapshot([unknown])) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(card.closest('.react-flow__node')!, {
      clientX: 80,
      clientY: 120,
    })
    expect(screen.queryByRole('menuitem', { name: 'Add husband' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Add wife' })).not.toBeInTheDocument()
  })

  it('locks both parents when adding a child or a marriage from a co-parent bracket', async () => {
    const child: Person = {
      ...spouse,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e2',
      gender: 'unknown',
    }
    const fatherLink: ParentLink = {
      ...parentLink,
      childId: child.id,
      parentId: father.id,
      role: 'father',
    }
    const motherLink: ParentLink = {
      ...parentLink,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e3',
      childId: child.id,
      parentId: person.id,
      role: 'mother',
    }
    const initial = graphSnapshot([father, person, child], [fatherLink, motherLink])
    const request = vi.fn(async () => initial) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    const bracket = document.querySelector('.react-flow__edge')
    expect(bracket).not.toBeNull()
    fireEvent.contextMenu(bracket!, { clientX: 140, clientY: 160 })

    expect(screen.getByRole('menuitem', { name: 'Add child' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Create marriage' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Add child' }))
    expect(screen.getByRole('dialog', { name: 'Add child' })).toBeInTheDocument()
    expect(screen.getByText('Ivanova Anna, Petrov Ivan')).toBeInTheDocument()
    expect(screen.queryByText('Second parent')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    fireEvent.contextMenu(bracket!, { clientX: 140, clientY: 160 })
    await userEvent.click(screen.getByRole('menuitem', { name: 'Create marriage' }))
    expect(screen.getByRole('dialog', { name: 'Add spouse' })).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: 'New person' })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Person' })).not.toBeInTheDocument()
  })

  it('hides reference actions for the current reference person and marriage', async () => {
    const personReference = graphSnapshot([person, spouse], [], [marriage])
    const request = vi.fn(async () => personReference) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(card.closest('.react-flow__node')!, {
      clientX: 80,
      clientY: 120,
    })
    expect(screen.queryByRole('menuitem', { name: 'Set as reference' })).not.toBeInTheDocument()
  })

  it('switches to a temporary relationship network without opening person editing', async () => {
    const tree = graphSnapshot([father, person, spouse], [parentLink], [marriage])
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    expect(window.location.pathname).toBe(`/trees/${treeId}/tree`)
    expect(screen.getByRole('button', { name: 'Export' })).toBeInTheDocument()
    const showBloodNetwork = screen.getByRole('button', { name: 'Show blood network' })
    expect(showBloodNetwork.querySelector('svg')).toHaveAttribute('viewBox', '0 0 24 24')
    await userEvent.click(showBloodNetwork)

    expect(window.location.pathname).toBe(`/trees/${treeId}/network`)
    const showFamilyTree = screen.getByRole('button', { name: 'Show family tree' })
    expect(screen.getByRole('button', { name: 'Export' })).toBeInTheDocument()
    expect(showFamilyTree.querySelector('svg')).toHaveAttribute('viewBox', '0 0 16 16')
    expect(showFamilyTree.querySelector('path')).toHaveAttribute(
      'd',
      'M8 2v4M4 14v-3h8v3M4 11V8h8v3',
    )
    const anna = screen.getByRole('article', { name: 'Ivanova Anna' })
    expect(anna).toHaveClass('blood-network-person')
    expect(anna.closest('.react-flow__node')).toHaveClass('draggable')
    expect(document.querySelector('.blood-network-family')).toBeInTheDocument()
    expect(screen.getByLabelText('Shared parents of 0 children')).toBeInTheDocument()
    await waitFor(() => {
      expect(document.querySelectorAll('.blood-network-edge-overlay line')).toHaveLength(4)
    })
    expect(screen.queryByText('Blood links')).not.toBeInTheDocument()
    expect(screen.queryByText('Connected points attract. All points repel.')).not.toBeInTheDocument()
    expect(document.querySelector('.person-details-panel')).not.toBeInTheDocument()
    expect(document.querySelector('.blood-network-sidebar')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Forces' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save blood network map' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Regenerate blood network' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Regenerate blood network' }))
    await waitFor(() => {
      expect(document.querySelectorAll('.blood-network-edge-overlay line')).toHaveLength(4)
    })
    const parentPairSpring = screen.getByRole('slider', { name: 'Parent-pair spring' })
    expect(parentPairSpring).toHaveValue('0.2')
    await userEvent.click(parentPairSpring)
    await userEvent.keyboard('{ArrowRight}')
    await waitFor(() => {
      expect(document.querySelectorAll('.blood-network-edge-overlay line')).toHaveLength(4)
    })
    expect(screen.getByRole('slider', { name: 'Parent hinge target angle (°)' })).toHaveValue('45')
    expect(screen.getByRole('slider', { name: 'Parent hinge spring' })).toHaveValue('4')
    expect(screen.getByRole('slider', { name: 'Descendant-side force' })).toHaveValue('12')
    expect(screen.getByRole('slider', { name: 'Person flow hinge' })).toHaveAttribute('max', '50')
    expect(screen.getByRole('slider', { name: 'Person flow hinge' })).toHaveValue('8')
    expect(screen.getByRole('slider', { name: 'Shared-parent spring' })).toHaveAttribute('max', '2')
    expect(screen.getByRole('slider', { name: 'Shared-parent spring' })).toHaveValue('0.12')
    expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('3')
    const repulsionDistanceExponent = screen.getByRole(
      'slider',
      { name: 'Repulsion distance exponent' },
    )
    expect(repulsionDistanceExponent).toHaveAttribute('min', '1')
    expect(repulsionDistanceExponent).toHaveAttribute('max', '3')
    expect(repulsionDistanceExponent).toHaveValue('1')
    const edgeNodeCapsuleThickness = screen.getByRole(
      'slider',
      { name: 'Edge-collision capsule thickness (px)' },
    )
    expect(edgeNodeCapsuleThickness).toHaveAttribute('max', '100')
    expect(edgeNodeCapsuleThickness).toHaveValue('24')
    expect(screen.getByRole('slider', { name: 'Edge-collision repulsion' }))
      .toHaveValue('12')
    const distantKinRepulsion = screen.getByRole('slider', { name: 'Distant-kin repulsion' })
    expect(distantKinRepulsion).toHaveAttribute('min', '0')
    expect(distantKinRepulsion).toHaveAttribute('max', '3')
    expect(distantKinRepulsion).toHaveValue('0.3')
    expect(screen.getByRole(
      'slider',
      { name: 'Distant-kin repulsion distance exponent' },
    )).toHaveValue('1')
    expect(screen.getByRole(
      'slider',
      { name: 'Unrelated parent-node repulsion' },
    )).toHaveValue('6')
    const settlementThreshold = screen.getByRole(
      'slider',
      { name: 'Settlement threshold (px/node/s)' },
    )
    expect(settlementThreshold).toHaveValue('1')
    fireEvent.change(parentPairSpring, { target: { value: '0.3' } })
    expect(parentPairSpring).toHaveValue('0.3')
    fireEvent.change(settlementThreshold, { target: { value: '2' } })
    expect(settlementThreshold).toHaveValue('2')

    await userEvent.click(screen.getByRole('button', { name: 'Save blood network map' }))
    await waitFor(() => {
      expect(request).toHaveBeenCalledWith(
        `/trees/${treeId}/blood-network-state`,
        expect.objectContaining({
          method: 'PUT',
          headers: { 'If-Match': '"tree-current"' },
          body: expect.any(String),
        }),
      )
    })
    const saveCall = (request as unknown as ReturnType<typeof vi.fn>).mock.calls.find(
      ([path]) => path === `/trees/${treeId}/blood-network-state`,
    )
    if (!saveCall) {
      throw new Error('The blood network save request was not sent.')
    }
    expect(JSON.parse((saveCall[1] as RequestInit).body as string)).toEqual(
      expect.objectContaining({
        forces: expect.objectContaining({
          averageMovementPerSecondThreshold: 2,
          baseRepulsionDistanceExponent: 1,
          kinshipRepulsionDistanceExponent: 1,
          edgeNodeCapsuleThickness: 24,
          edgeNodeRepulsionStrength: 12,
        }),
        nodes: expect.any(Array),
        viewport: expect.objectContaining({ zoom: expect.any(Number) }),
      }),
    )

    await userEvent.hover(anna)
    const tooltip = within(anna).getByRole('tooltip')
    expect(tooltip).toHaveTextContent('Ivanova Anna')
    expect(tooltip).toHaveTextContent('1900 – present')

    fireEvent.click(anna)
    await waitFor(() => {
      expect(request).not.toHaveBeenCalledWith(
        `/trees/${treeId}/people/${person.id}`,
        expect.anything(),
      )
    })

    await userEvent.click(screen.getByRole('button', { name: 'Show family tree' }))
    expect(window.location.pathname).toBe(`/trees/${treeId}/tree`)

  })

  it('keeps a separate camera while switching editor modes', async () => {
    window.history.replaceState({}, '', `/trees/${treeId}/tree`)
    const tree = graphSnapshot([father, person], [parentLink])
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent('100%')
    })
    await userEvent.click(screen.getByRole('button', { name: 'Zoom in' }))
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent('120%')
    })
    const treeZoom = screen.getByLabelText('Current zoom').textContent

    await userEvent.click(screen.getByRole('button', { name: 'Show blood network' }))
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Show family tree' })).toBeInTheDocument()
    })

    await userEvent.click(screen.getByRole('button', { name: 'Show family tree' }))
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent(treeZoom ?? '')
    })
  })

  it('starts a refreshed ordinary tree at the reference and 100%', async () => {
    window.history.replaceState({}, '', `/trees/${treeId}/tree`)
    const tree = graphSnapshot([person])
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    const rendered = renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent('100%')
    })
    await userEvent.click(screen.getByRole('button', { name: 'Zoom in' }))
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).not.toHaveTextContent('100%')
    })

    rendered.unmount()
    renderEditor(request)
    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent('100%')
      expect(document.querySelector('.blood-network-canvas')).toHaveAttribute(
        'aria-busy',
        'false',
      )
    })
  })

  it('resets a saved map camera to the viewport stored with the map', async () => {
    const path = `/trees/${treeId}/network`
    window.history.replaceState({}, '', path)
    const tree = {
      ...graphSnapshot([person]),
      bloodNetworkState: {
        forces: defaultBloodNetworkPresets()[0].forces,
        nodes: [{
          id: person.id,
          coordinates: { x: 240, y: -180 },
        }],
        viewport: { x: 40, y: -20, zoom: 0.7 },
      },
    }
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent('70%')
    })
    await userEvent.click(screen.getByRole('button', { name: 'Zoom in' }))
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).not.toHaveTextContent('70%')
    })
    const reset = screen.getByRole('button', { name: 'Reset camera' })
    expect(reset.querySelector('svg')).not.toBeNull()
    await userEvent.click(reset)
    await waitFor(() => {
      expect(screen.getByLabelText('Current zoom')).toHaveTextContent('70%')
    })
  })

  it('activates rotation around a node from its context menu until Escape', async () => {
    const tree = graphSnapshot([father, person], [parentLink])
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await userEvent.click(screen.getByRole('button', { name: 'Show blood network' }))
    const anna = screen.getByRole('article', { name: 'Ivanova Anna' })
    fireEvent.contextMenu(anna.closest('.react-flow__node')!, { clientX: 120, clientY: 140 })

    expect(screen.getByRole('menu', { name: 'Node actions' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Rotate' }))

    expect(anna).toHaveClass('blood-network-rotation-pivot')
    expect(document.querySelector('.blood-network-rotation-mode')).toBeInTheDocument()
    const canvas = document.querySelector('.blood-network-rotation-mode') as HTMLElement
    const setPointerCapture = vi.fn()
    const releasePointerCapture = vi.fn()
    Object.assign(canvas, { setPointerCapture, releasePointerCapture })
    fireEvent.pointerDown(canvas, {
      button: 0,
      buttons: 1,
      clientX: 600,
      clientY: 400,
      pointerId: 1,
    })
    fireEvent.pointerMove(canvas, {
      buttons: 1,
      clientX: 400,
      clientY: 600,
      pointerId: 1,
    })
    fireEvent.pointerUp(canvas, { button: 0, pointerId: 1 })
    expect(setPointerCapture).toHaveBeenCalledWith(1)
    expect(releasePointerCapture).toHaveBeenCalledWith(1)
    expect(anna).toHaveClass('blood-network-rotation-pivot')
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(anna).not.toHaveClass('blood-network-rotation-pivot')
    expect(document.querySelector('.blood-network-rotation-mode')).not.toBeInTheDocument()
  })

  it('asks before replacing an already saved blood network map', async () => {
    const tree = {
      ...graphSnapshot([person]),
      bloodNetworkState: {
        forces: {
          ...defaultBloodNetworkPresets()[1].forces,
          baseRepulsion: 5,
        },
        nodes: [{
          id: person.id,
          coordinates: { x: 240, y: -180 },
        }],
        viewport: { x: 40, y: -20, zoom: 0.7 },
      },
    }
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await userEvent.click(screen.getByRole('button', { name: 'Show blood network' }))
    await waitFor(() => {
      expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('5')
    })
    await userEvent.click(screen.getByRole('button', { name: 'Save blood network map' }))

    const dialog = screen.getByRole('dialog', { name: 'Save map changes?' })
    expect(within(dialog).getByText(/saved map, viewport, and force settings/i))
      .toBeInTheDocument()
    expect(request).not.toHaveBeenCalledWith(
      `/trees/${treeId}/blood-network-state`,
      expect.anything(),
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(request).toHaveBeenCalledWith(
        `/trees/${treeId}/blood-network-state`,
        expect.objectContaining({ method: 'PUT' }),
      )
    })
  })

  it('applies the user default force preset and saves changes only on request', async () => {
    const presets = defaultBloodNetworkPresets()
    const user: User = {
      id: '019861c4-6a93-7a2e-8ca8-51813740d4f0',
      username: 'force_user',
      email: 'force@example.test',
      preferredLanguage: 'en',
      avatarUrl: null,
      bloodNetworkPresets: [
        { ...presets[0], isDefault: true },
        { ...presets[1], isDefault: false },
      ],
      createdAt: '2026-07-31T00:00:00+00:00',
    }
    const updatedUser: User = {
      ...user,
      bloodNetworkPresets: [
        { ...user.bloodNetworkPresets[0], name: 'Open' },
        user.bloodNetworkPresets[1],
      ],
    }
    const tree = graphSnapshot([father, person], [parentLink])
    const request = vi.fn(async (path: string) => (
      path === '/me/blood-network-presets' ? updatedUser : tree
    )) as unknown as AuthorizedJsonRequest
    renderEditor(request, user)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await userEvent.click(screen.getByRole('button', { name: 'Show blood network' }))

    expect(screen.getByRole('combobox', { name: 'Force preset' })).toHaveValue('weak')
    expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('8')
    expect(screen.getByRole('slider', { name: 'Parent-to-child path length (px)' })).toHaveValue('300')
    expect(screen.getByRole('slider', { name: 'Parent-to-node length ratio' })).toHaveValue('0.58')
    expect(screen.getByRole('checkbox', { name: 'Default' })).toBeChecked()
    const savePreset = screen.getByRole('button', { name: 'Save preset' })
    expect(savePreset).toBeDisabled()

    fireEvent.change(screen.getByRole('slider', { name: 'Base repulsion' }), {
      target: { value: '7' },
    })
    expect(savePreset).toBeEnabled()

    await userEvent.click(screen.getByRole('button', { name: 'Reload preset' }))
    expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('8')
    expect(savePreset).toBeDisabled()

    expect(screen.queryByRole('textbox', { name: 'Preset name' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Edit Preset name' }))
    const presetName = screen.getByRole('textbox', { name: 'Preset name' })
    await userEvent.clear(presetName)
    await userEvent.type(presetName, 'Open')
    expect(savePreset).toBeEnabled()

    expect(request).not.toHaveBeenCalledWith(
      '/me/blood-network-presets',
      expect.anything(),
    )
    await userEvent.click(savePreset)

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      '/me/blood-network-presets',
      expect.objectContaining({
        body: expect.stringContaining('"name":"Open"'),
        method: 'PATCH',
      }),
    ))
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

    expect(await screen.findByRole('article', { name: 'Ivanova Maria' })).toBeInTheDocument()
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

  it('adds a hidden parent and co-parent as a local overlay without BFS', async () => {
    const mother: Person = {
      ...person,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4b2',
      birthSurname: { state: 'known', value: 'Petrova' },
      givenName: { state: 'known', value: 'Maria' },
    }
    const motherLink: ParentLink = {
      id: '019861c4-6a93-7a2e-8ca8-51813740d4c1',
      parentId: mother.id,
      childId: person.id,
      role: 'mother',
    }
    const tree = graphSnapshot([person])
    const details = {
      etag: tree.etag,
      person,
      events: [event],
      relationships: {
        people: [father, mother].map((relative) => ({
          id: relative.id,
          birthSurname: relative.birthSurname,
          givenName: relative.givenName,
          patronymic: relative.patronymic,
          gender: relative.gender,
          birthDate: relative.birthDate,
          deathDate: relative.deathDate,
          photoThumbnailUrl: relative.photoThumbnailUrl,
        })),
        parentLinks: [parentLink, motherLink],
        marriages: [],
      },
      permissions: {
        canEdit: true,
        canDelete: true,
        removableMarriageIds: [],
        removableParentLinkIds: [parentLink.id, motherLink.id],
      },
    }
    const request = vi.fn(async (path: string) => {
      if (path === '/trees/' + treeId + '/snapshot') {
        return tree
      }
      if (path === '/trees/' + treeId + '/people/' + person.id + '/details') {
        return details
      }
      if (path === '/trees/' + treeId + '/people/' + father.id + '/details') {
        return { ...details, person: father }
      }
      throw new Error('Unexpected request: ' + path)
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(screen.getByRole('button', { name: 'Petrov Ivan' }))

    expect(await screen.findByRole('heading', { name: 'Petrov Ivan' }))
      .toBeInTheDocument()
    expect(screen.getByRole('article', { name: 'Petrova Maria' }))
      .toHaveClass('person-card-overlay')
    expect(screen.getByRole('article', { name: 'Petrov Ivan' }))
      .toHaveClass('person-card-overlay')
    expect(request).toHaveBeenCalledWith(
      '/trees/' + treeId + '/people/' + father.id + '/details',
    )
    expect(request).not.toHaveBeenCalledWith(
      '/trees/' + treeId + '/route-to-reference/' + father.id,
    )
    expect(screen.getByRole('article', { name: 'Petrov Ivan' })
      .closest('.react-flow__node')).toHaveClass('selected')
  })

  it('reuses cached person details while the snapshot ETag is unchanged', async () => {
    const request = vi.fn(async (path: string) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.click(card)
    await screen.findByRole('complementary', { name: 'Person details' })
    fireEvent.click(document.querySelector('.react-flow__pane')!)
    expect(screen.queryByRole('complementary', { name: 'Person details' }))
      .not.toBeInTheDocument()

    fireEvent.click(card)
    expect(await screen.findByRole('complementary', { name: 'Person details' }))
      .toBeInTheDocument()
    expect(request).toHaveBeenCalledTimes(3)
    expect(request).toHaveBeenCalledWith(`/trees/${treeId}/people/${person.id}/details`)
    expect(request).not.toHaveBeenCalledWith(`/trees/${treeId}/people/${person.id}`)
  })

  it('opens a hidden search result as an editable route overlay and closes it', async () => {
    const hiddenAncestor: Person = {
      ...father,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e0',
      birthSurname: { state: 'known', value: 'Hidden' },
      givenName: { state: 'known', value: 'Ancestor' },
    }
    const routeLink: ParentLink = {
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e1',
      parentId: hiddenAncestor.id,
      childId: person.id,
      role: 'father',
    }
    const hiddenSpouse: Person = {
      ...spouse,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e2',
      birthSurname: { state: 'known', value: 'Hidden' },
      givenName: { state: 'known', value: 'Spouse' },
    }
    const hiddenMarriage: MarriagePeriod = {
      ...marriage,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e3',
      malePersonId: hiddenAncestor.id,
      femalePersonId: hiddenSpouse.id,
    }
    const route: SearchRoutePlan = {
      etag: '"tree-current"',
      targetPersonId: hiddenAncestor.id,
      people: [person, hiddenAncestor],
      nodes: [
        { personId: person.id, generation: 0, kind: 'route' },
        { personId: hiddenAncestor.id, generation: -1, kind: 'route' },
      ],
      parentLinks: [routeLink],
      marriages: [],
      familyBlocks: [],
    }
    const replacementRoute: SearchRoutePlan = {
      etag: '"tree-current"',
      targetPersonId: hiddenSpouse.id,
      people: [person, hiddenSpouse],
      nodes: [
        { personId: person.id, generation: 0, kind: 'route' },
        { personId: hiddenSpouse.id, generation: 0, kind: 'route' },
      ],
      parentLinks: [],
      marriages: [hiddenMarriage],
      familyBlocks: [],
    }
    const details = {
      etag: '"tree-current"',
      person: hiddenAncestor,
      relationships: {
        people: [person, hiddenSpouse],
        parentLinks: [routeLink],
        marriages: [hiddenMarriage],
      },
      events: [],
      permissions: {
        canEdit: true,
        canDelete: true,
        removableMarriageIds: [],
        removableParentLinkIds: [routeLink.id],
      },
    }
    const request = vi.fn(async (path: string) => {
      if (path === `/trees/${treeId}/snapshot`) {
        return graphSnapshot([person, father, hiddenAncestor], [parentLink])
      }
      if (path.startsWith(`/trees/${treeId}/people/search?`)) {
        return {
          items: [{
            id: hiddenAncestor.id,
            fullName: 'Hidden Ancestor',
            birthYear: null,
            deathYear: null,
          }],
        }
      }
      if (path === `/trees/${treeId}/route-to-reference/${hiddenAncestor.id}`) {
        return route
      }
      if (path === '/trees/' + treeId + '/route-to-reference/' + hiddenSpouse.id) {
        return replacementRoute
      }
      if (path === `/trees/${treeId}/people/${hiddenAncestor.id}/details`) {
        return details
      }
      if (path === '/trees/' + treeId + '/people/' + hiddenSpouse.id + '/details') {
        return { ...details, person: hiddenSpouse }
      }
      throw new Error(`Unexpected request: ${path}`)
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await userEvent.click(await screen.findByRole('button', { name: 'Search people' }))
    await userEvent.type(screen.getByRole('combobox', { name: 'Search by full name' }), 'Hidden')
    await userEvent.click(await screen.findByRole('option', { name: /Hidden Ancestor/ }))

    const overlayCard = await screen.findByRole('article', { name: 'Hidden Ancestor' })
    expect(overlayCard).toHaveClass('person-card-overlay')
    expect(await screen.findByRole('complementary', { name: 'Person details' }))
      .toHaveTextContent('Hidden Ancestor')
    expect(screen.getByRole('article', { name: 'Petrov Ivan' })).toHaveClass('person-card-muted')
    expect(screen.getByRole('button', { name: 'Tree for Hidden Ancestor' }))
      .toHaveClass('path-display-target')

    expect(document.querySelector('.tree-edge-highlight')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Search people' }))
    await userEvent.type(screen.getByRole('combobox', { name: 'Search by full name' }), 'Hidden')
    expect(await screen.findByLabelText('Already shown in the tree')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('option', { name: /Hidden Ancestor/ }))
    expect((request as unknown as ReturnType<typeof vi.fn>).mock.calls.filter(([path]) => (
      path === `/trees/${treeId}/route-to-reference/${hiddenAncestor.id}`
    ))).toHaveLength(1)

    await userEvent.click(screen.getByRole('button', { name: 'Hidden Spouse' }))
    expect(await screen.findByRole('heading', { name: 'Hidden Spouse' }))
      .toBeInTheDocument()
    expect(screen.getByRole('article', { name: 'Hidden Spouse' }))
      .toHaveClass('person-card-overlay')
    expect(screen.queryByRole('article', { name: 'Hidden Ancestor' }))
      .not.toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(
      '/trees/' + treeId + '/route-to-reference/' + hiddenSpouse.id,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Close route' }))
    expect(screen.queryByRole('article', { name: 'Hidden Spouse' })).not.toBeInTheDocument()
    expect(screen.queryByRole('complementary', { name: 'Person details' })).not.toBeInTheDocument()
  })

  it('reloads the snapshot instead of applying person details from another tree version', async () => {
    const staleDetails = {
      etag: '"tree-next"',
      person,
      events: [],
      relationships: {
        people: [],
        parentLinks: [],
        marriages: [],
      },
      permissions: {
        canEdit: true,
        canDelete: true,
        removableMarriageIds: [],
        removableParentLinkIds: [],
      },
    }
    let snapshotRequests = 0
    const request = vi.fn(async (path: string) => {
      if (path === `/trees/${treeId}/snapshot`) {
        ++snapshotRequests
        return snapshotRequests === 1
          ? snapshot([person])
          : { ...snapshot([person]), etag: '"tree-next"' }
      }
      if (path === `/trees/${treeId}/people/${person.id}/details`) {
        return staleDetails
      }
      throw new Error(`Unexpected request: ${path}`)
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(await screen.findByRole('article', { name: 'Ivanova Anna' }))

    await waitFor(() => {
      expect((request as unknown as ReturnType<typeof vi.fn>).mock.calls.filter(([path]) => (
        path === `/trees/${treeId}/snapshot`
      ))).toHaveLength(2)
    })
    expect(screen.queryByRole('complementary', { name: 'Person details' }))
      .not.toBeInTheDocument()
  })

  it('keeps both displayed parents and the selected muted card visible in a route display', async () => {
    const mother: Person = {
      ...person,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e5',
      birthSurname: { state: 'known', value: 'Sidorova' },
      givenName: { state: 'known', value: 'Maria' },
    }
    const hiddenChild: Person = {
      ...person,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e6',
      birthSurname: { state: 'known', value: 'Petrova' },
      givenName: { state: 'known', value: 'Elena' },
    }
    const unrelated: Person = {
      ...spouse,
      gender: 'female',
    }
    const unrelatedMarriage: MarriagePeriod = {
      ...marriage,
      malePersonId: father.id,
      femalePersonId: unrelated.id,
    }
    const fatherRouteLink: ParentLink = {
      ...parentLink,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e7',
      childId: hiddenChild.id,
    }
    const motherRouteLink: ParentLink = {
      id: '019861c4-6a93-7a2e-8ca8-51813740d4e8',
      parentId: mother.id,
      childId: hiddenChild.id,
      role: 'mother',
    }
    const route: SearchRoutePlan = {
      etag: '"tree-current"',
      targetPersonId: hiddenChild.id,
      people: [father, mother, hiddenChild],
      nodes: [
        { personId: father.id, generation: -1, kind: 'route' },
        { personId: mother.id, generation: -1, kind: 'support' },
        { personId: hiddenChild.id, generation: 0, kind: 'route' },
      ],
      parentLinks: [fatherRouteLink, motherRouteLink],
      marriages: [],
      familyBlocks: [{
        id: 'parents',
        personIds: [father.id, mother.id],
        childIds: [hiddenChild.id],
        married: false,
      }],
    }
    const childDetails = {
      etag: '"tree-current"',
      person: hiddenChild,
      relationships: {
        people: [father, mother, hiddenChild],
        parentLinks: [fatherRouteLink, motherRouteLink],
        marriages: [],
      },
      events: [],
      permissions: {
        canEdit: true,
        canDelete: true,
        removableMarriageIds: [],
        removableParentLinkIds: [fatherRouteLink.id, motherRouteLink.id],
      },
    }
    const request = vi.fn(async (path: string) => {
      if (path === `/trees/${treeId}/snapshot`) {
        return graphSnapshot([father, mother, unrelated], [], [unrelatedMarriage])
      }
      if (path.startsWith(`/trees/${treeId}/people/search?`)) {
        return {
          items: [{
            id: hiddenChild.id,
            fullName: 'Petrova Elena',
            birthYear: null,
            deathYear: null,
          }],
        }
      }
      if (path === `/trees/${treeId}/route-to-reference/${hiddenChild.id}`) {
        return route
      }
      if (path === `/trees/${treeId}/people/${hiddenChild.id}/details`) {
        return childDetails
      }
      if (path === `/trees/${treeId}/people/${unrelated.id}/details`) {
        return {
          ...childDetails,
          person: unrelated,
          relationships: {
            people: [unrelated],
            parentLinks: [],
            marriages: [],
          },
        }
      }
      throw new Error(`Unexpected request: ${path}`)
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await userEvent.click(await screen.findByRole('button', { name: 'Search people' }))
    await userEvent.type(screen.getByRole('combobox', { name: 'Search by full name' }), 'Petrova')
    await userEvent.click(await screen.findByRole('option', { name: /Petrova Elena/ }))

    expect(await screen.findByRole('article', { name: 'Petrova Elena' }))
      .not.toHaveClass('person-card-path')
    expect(screen.getByRole('article', { name: 'Petrov Ivan' }))
      .not.toHaveClass('person-card-muted')
    expect(screen.getByRole('article', { name: 'Sidorova Maria' }))
      .not.toHaveClass('person-card-muted')
    expect(screen.getByRole('article', { name: 'Ivanov Petr' }))
      .toHaveClass('person-card-muted')

    fireEvent.click(screen.getByRole('article', { name: 'Ivanov Petr' }))
    await waitFor(() => expect(screen.getByRole('article', { name: 'Ivanov Petr' }))
      .not.toHaveClass('person-card-muted'))
  })

  it('closes person details when the empty tree canvas is clicked', async () => {
    const request = vi.fn(async (path: string) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
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

    const pane = document.querySelector('.react-flow__pane')
    expect(pane).not.toBeNull()
    fireEvent.click(pane as Element)

    expect(screen.queryByRole('complementary', { name: 'Person details' }))
      .not.toBeInTheDocument()
  })

  it('adds and removes a place on an immutable automatic event', async () => {
    const resolvedPlace = {
      name: 'Novosibirsk, Russia',
      coordinates: { latitude: 55.0302, longitude: 82.9204 },
    }
    let placeSaved = false
    let etag = '"tree-current"'
    const request = vi.fn(async (path: string, init?: RequestInit) => {
      if (path === `/trees/${treeId}/snapshot`) {
        return {
          ...graphSnapshot([{
            ...person,
            birthPlace: placeSaved ? resolvedPlace : null,
          }]),
          etag,
        }
      }
      if (path === `/trees/${treeId}/people/${person.id}`) {
        return {
          ...person,
          birthPlace: placeSaved ? resolvedPlace : null,
        }
      }
      if (path === `/trees/${treeId}/people/${person.id}/events`) {
        return { items: [{ ...event, place: placeSaved ? resolvedPlace : null }] }
      }
      if (path.startsWith('/map/geocoding/search?')) {
        return { items: [resolvedPlace] }
      }
      if (path === `/trees/${treeId}/events/${event.id}/place`) {
        placeSaved = init?.method === 'PUT'
        etag = placeSaved ? '"tree-place-saved"' : '"tree-place-removed"'
        return init?.method === 'PUT'
          ? { event: { ...event, place: resolvedPlace }, etag }
          : undefined
      }

      throw new Error(`Unexpected request: ${path}`)
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    const card = await screen.findByRole('article', { name: 'Ivanova Anna' })
    fireEvent.click(card)
    await userEvent.click(await screen.findByRole('button', {
      name: 'Add a place for Birth',
    }))
    let dialog = screen.getByRole('dialog', { name: 'Event place' })
    await userEvent.type(
      within(dialog).getByRole('combobox', { name: 'Settlement search' }),
      'Novosibirsk',
    )
    await userEvent.click(await within(dialog).findByRole('option', {
      name: 'Novosibirsk, Russia',
    }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/events/${event.id}/place`,
      {
        method: 'PUT',
        body: JSON.stringify(resolvedPlace),
        headers: { 'If-Match': '"tree-current"' },
      },
    ))
    expect(await screen.findByRole('button', {
      name: 'Edit Novosibirsk, Russia for Birth',
    })).toBeInTheDocument()
    expect(screen.getByText('Novosibirsk, Russia')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', {
      name: 'Edit Novosibirsk, Russia for Birth',
    }))
    dialog = screen.getByRole('dialog', { name: 'Event place' })
    await userEvent.click(within(dialog).getByRole('button', {
      name: 'Delete place',
    }))

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/events/${event.id}/place`,
      {
        method: 'DELETE',
        headers: { 'If-Match': '"tree-place-saved"' },
      },
    ))
    expect(await screen.findByRole('button', {
      name: 'Add a place for Birth',
    })).toBeInTheDocument()
  })

  it('creates, edits, and deletes a manual event with current ETags', async () => {
    let currentEvents = [event]
    let deleted = false
    const manualEvent: GenealogicalEvent = {
      ...event,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4a8',
      type: 'custom',
      source: 'manual',
      title: 'Graduated',
      description: null,
      sourceEntityType: null,
      sourceEntityId: null,
    }
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith(`/events/${manualEvent.id}`)) {
        if (init?.method === 'DELETE') {
          currentEvents = [event]
          deleted = true
          return undefined
        }
        const changed = { ...manualEvent, title: 'University graduation' }
        currentEvents = [event, changed]
        return { event: changed, etag: '"tree-event-updated"' }
      }
      if (path.endsWith('/events')) {
        if (init?.method === 'POST') {
          currentEvents = [event, manualEvent]
          return { event: manualEvent, etag: '"tree-event-created"' }
        }
        return { items: currentEvents }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
      }

      return graphSnapshot(
        [person],
        [],
        [],
        deleted ? '"tree-event-deleted"' : '"tree-current"',
      )
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(
      within(sectionByHeading('Events')).getByRole('button', { name: 'Add' }),
    )
    let dialog = screen.getByRole('dialog', { name: 'Add an event' })
    await userEvent.type(
      within(dialog).getByRole('textbox', { name: 'Title' }),
      'Graduated',
    )
    await userEvent.click(
      within(
        within(dialog).getByRole('group', { name: 'Event date' }),
      ).getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    expect(await screen.findByText('Graduated')).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/people/${person.id}/events`,
      expect.objectContaining({
        method: 'POST',
        headers: { 'If-Match': '"tree-current"' },
        body: JSON.stringify({
          type: 'custom',
          date: { state: 'unknown' },
          title: 'Graduated',
          description: null,
          place: null,
        }),
      }),
    )

    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))
    dialog = screen.getByRole('dialog', { name: 'Edit event' })
    const title = within(dialog).getByRole('textbox', { name: 'Title' })
    await userEvent.clear(title)
    await userEvent.type(title, 'University graduation')
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await screen.findByText('University graduation'))
      .toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/people/${person.id}/events/${manualEvent.id}`,
      expect.objectContaining({
        method: 'PATCH',
        headers: { 'If-Match': '"tree-event-created"' },
      }),
    )

    await userEvent.click(screen.getByRole('button', { name: 'Remove' }))
    dialog = screen.getByRole('dialog', { name: 'Delete event?' })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Remove' }),
    )
    await waitFor(() => {
      expect(screen.queryByText('University graduation'))
        .not.toBeInTheDocument()
    })
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/people/${person.id}/events/${manualEvent.id}`,
      expect.objectContaining({
        method: 'DELETE',
        headers: { 'If-Match': '"tree-event-updated"' },
      }),
    )
  })

  it('persists manual order for events with ambiguous dates', async () => {
    const manualEvent: GenealogicalEvent = {
      ...event,
      id: '019861c4-6a93-7a2e-8ca8-51813740d4a8',
      type: 'custom',
      source: 'manual',
      title: 'Moved',
      sourceEntityType: null,
      sourceEntityId: null,
    }
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/event-order') && init?.method === 'PUT') {
        return {
          items: [manualEvent, event],
          etag: '"tree-reordered"',
        }
      }
      if (path.endsWith('/events')) {
        return { items: [event, manualEvent] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
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
      screen.getByRole('button', { name: 'Move Moved up' }),
    )

    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/people/${person.id}/event-order`,
      {
        method: 'PUT',
        body: JSON.stringify({ eventIds: [manualEvent.id, event.id] }),
        headers: { 'If-Match': '"tree-current"' },
      },
    )
  })

  it('uploads and confirms deletion of a primary person photo', async () => {
    const withPhoto: Person = {
      ...person,
      photoThumbnailUrl:
        `/trees/${treeId}/people/${person.id}/photo/thumbnail?media=photo`,
    }
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/photo') && init?.method === 'POST') {
        return { person: withPhoto, etag: '"tree-photo"' }
      }
      if (path.endsWith('/photo') && init?.method === 'DELETE') {
        return { person, etag: '"tree-photo-removed"' }
      }
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    const photoActions = document.querySelector('.person-photo-actions')
    expect(photoActions).not.toBeNull()
    await userEvent.click(
      within(photoActions as HTMLElement).getByRole('button', {
        name: 'Add photo',
      }),
    )
    await userEvent.click(screen.getByRole('button', {
      name: 'Save photo crop',
    }))
    expect(await screen.findByRole('button', { name: 'Replace photo' }))
      .toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', {
      name: 'Open full photo',
    }))
    const viewer = screen.getByRole('dialog', { name: 'Full photo' })
    expect(await within(viewer).findByRole('img', { name: 'Full photo' }))
      .toHaveAttribute('src', 'blob:test-image')
    await userEvent.click(within(viewer).getByRole('button', { name: 'Close' }))
    expect(screen.queryByRole('dialog', { name: 'Full photo' }))
      .not.toBeInTheDocument()
    const uploadCall = (
      request as unknown as ReturnType<typeof vi.fn>
    ).mock.calls.find(
      ([path, init]) => String(path).endsWith('/photo')
        && (init as RequestInit).method === 'POST',
    )
    expect(uploadCall?.[1]).toEqual(expect.objectContaining({
      method: 'POST',
      headers: { 'If-Match': '"tree-current"' },
      body: expect.any(FormData),
    }))

    await userEvent.click(screen.getByRole('button', { name: 'Delete photo' }))
    const dialog = screen.getByRole('dialog', { name: 'Delete photo?' })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Remove' }),
    )
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Delete photo' }))
        .not.toBeInTheDocument()
    })
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/people/${person.id}/photo`,
      {
        method: 'DELETE',
        headers: { 'If-Match': '"tree-photo"' },
      },
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

  it('adds a new father through an atomic graph mutation', async () => {
    const updated = graphSnapshot(
      [person, father],
      [parentLink],
      [],
      '"tree-next"',
    )
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
      }
      if (path.endsWith('/graph-mutations') && init?.method === 'POST') {
        return updated
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(
      within(sectionByHeading('Parents'))
        .getAllByRole('button', { name: 'Add' })[0] as HTMLElement,
    )
    const dialog = screen.getByRole('dialog', { name: 'Add father' })
    await activateUnpressedUnknownButtons(dialog)
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    await waitFor(() => {
      const call = (request as unknown as ReturnType<typeof vi.fn>).mock.calls
        .find(([path]) => String(path).endsWith('/graph-mutations'))
      if (!call) {
        throw new Error('graph mutation request was not sent')
      }
      expect(JSON.parse(String((call[1] as RequestInit).body))).toEqual({
        type: 'add_parent',
        childId: person.id,
        role: 'father',
        relative: {
          person: expect.objectContaining({ gender: 'male' }),
        },
      })
    })
    expect(screen.getByRole('article', { name: 'Petrov Ivan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Petrov Ivan' })).toBeInTheDocument()
  })

  it('links an existing spouse and edits the resulting marriage period', async () => {
    const married = graphSnapshot(
      [person, spouse],
      [],
      [marriage],
      '"tree-married"',
    )
    const changedMarriage = {
      ...marriage,
      dissolutionDate: { state: 'unknown' } as const,
    }
    const request = vi.fn(async (
      path: string,
    ) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
      }
      if (path.endsWith('/graph-mutations')) {
        return married
      }
      if (path.endsWith(`/marriages/${marriage.id}`)) {
        return { marriage: changedMarriage, etag: '"tree-edited"' }
      }
      return snapshot([person, spouse])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(
      within(sectionByHeading('Families')).getAllByRole('button', { name: 'Add' })[0] as HTMLElement,
    )
    let dialog = screen.getByRole('dialog', { name: 'Add spouse' })
    await userEvent.click(
      within(dialog).getByRole('radio', { name: 'Existing person' }),
    )
    dialog = screen.getByRole('dialog', { name: 'Add spouse' })
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Person' }),
      spouse.id,
    )
    await userEvent.click(
      within(
        within(dialog).getByRole('group', { name: 'Marriage date' }),
      ).getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await screen.findByRole('article', { name: 'Ivanov Petr' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ivanov Petr' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))
    const editDialog = screen.getByRole('dialog', {
      name: 'Edit marriage',
    })
    await userEvent.click(
      within(editDialog).getByRole('checkbox', { name: 'Marriage ended' }),
    )
    await userEvent.click(
      within(editDialog).getByRole('button', { name: 'Save' }),
    )

    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/marriages/${marriage.id}`,
      expect.objectContaining({
        method: 'PATCH',
        headers: { 'If-Match': '"tree-married"' },
        body: JSON.stringify({
          marriageDate: { state: 'unknown' },
          dissolutionDate: { state: 'unknown' },
        }),
      }),
    )
  })

  it('confirms and deletes a permitted leaf person', async () => {
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
      }
      if (path.endsWith('/graph-mutations') && init?.method === 'POST') {
        return graphSnapshot([], [], [], '"tree-empty"')
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(
      screen.getByRole('button', { name: 'Delete person' }),
    )
    const dialog = screen.getByRole('dialog', { name: 'Delete person?' })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Remove' }),
    )

    await waitFor(() => {
      expect(screen.queryByRole('complementary', { name: 'Person details' }))
        .not.toBeInTheDocument()
    })
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/graph-mutations`,
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          type: 'delete_person',
          personId: person.id,
        }),
      }),
    )
  })

  it('shows the global conflict banner after a graph mutation conflict', async () => {
    const request = vi.fn(async (
      path: string,
      init?: RequestInit,
    ) => {
      if (path.endsWith('/events')) {
        return { items: [event] }
      }
      if (path.endsWith(`/people/${person.id}`)) {
        return person
      }
      if (path.endsWith('/graph-mutations') && init?.method === 'POST') {
        throw new ApiRequestError(412, {
          code: 'TREE_VERSION_CONFLICT',
          message: 'tree_version_conflict',
          fields: {},
          details: {},
        })
      }
      return snapshot([person])
    }) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    fireEvent.click(
      await screen.findByRole('article', { name: 'Ivanova Anna' }),
    )
    await screen.findByRole('complementary', { name: 'Person details' })
    await userEvent.click(
      within(sectionByHeading('Parents'))
        .getAllByRole('button', { name: 'Add' })[1] as HTMLElement,
    )
    const dialog = screen.getByRole('dialog', { name: 'Add mother' })
    await activateUnpressedUnknownButtons(dialog)
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await screen.findAllByText(/changed in another session/))
      .toHaveLength(2)
    await userEvent.click(
      screen.getByRole('button', { name: 'Reload' }),
    )
    await waitFor(() => {
      expect(screen.queryByText(/changed in another session/))
        .not.toBeInTheDocument()
    })
  })
})
