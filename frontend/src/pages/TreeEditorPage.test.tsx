import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type {
  GenealogicalEvent,
  MarriagePeriod,
  ParentLink,
  Person,
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
const father: Person = {
  ...person,
  id: '019861c4-6a93-7a2e-8ca8-51813740d4b0',
  birthSurname: { state: 'known', value: 'Petrov' },
  givenName: { state: 'known', value: 'Ivan' },
  gender: 'male',
  coordinates: { x: -140, y: -240 },
}
const spouse: Person = {
  ...person,
  id: '019861c4-6a93-7a2e-8ca8-51813740d4b1',
  birthSurname: { state: 'known', value: 'Ivanov' },
  givenName: { state: 'known', value: 'Petr' },
  gender: 'male',
  coordinates: { x: 280, y: 0 },
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

  render(
    <AuthContext value={value}>
      <div id="header-breadcrumbs" />
      <TreeEditorPage treeId={treeId} />
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

class OptimizerWorkerMock {
  static instances: OptimizerWorkerMock[] = []
  onerror: ((event: Event) => void) | null = null
  onmessage: ((event: MessageEvent) => void) | null = null
  postMessage = vi.fn()
  terminate = vi.fn()

  constructor() {
    OptimizerWorkerMock.instances.push(this)
  }

  emit(message: unknown) {
    this.onmessage?.({ data: message } as MessageEvent)
  }
}

describe('TreeEditorPage', () => {
  beforeEach(async () => {
    window.history.replaceState({}, '', `/trees/${treeId}`)
    await i18n.changeLanguage('en')
    OptimizerWorkerMock.instances = []
    vi.unstubAllGlobals()
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

    expect(
      await screen.findByRole('article', { name: 'Unknown person' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Optimize layout' })).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(
      `/trees/${treeId}/graph-mutations`,
      expect.objectContaining({
        method: 'POST',
        headers: { 'If-Match': '"tree-current"' },
      }),
    )
  })

  it('switches to a temporary blood-only network without opening person editing', async () => {
    const tree = graphSnapshot([father, person, spouse], [parentLink], [marriage])
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('article', { name: 'Ivanova Anna' })
    await userEvent.click(
      screen.getByRole('button', { name: 'Show blood network' }),
    )

    expect(
      screen.getByRole('button', { name: 'Show family tree' }),
    ).toHaveAttribute('aria-pressed', 'true')
    const anna = screen.getByRole('article', { name: 'Ivanova Anna' })
    expect(anna).toHaveClass('blood-network-person')
    expect(anna.closest('.react-flow__node')).toHaveClass('draggable')
    expect(document.querySelector('.blood-network-family')).toBeInTheDocument()
    expect(screen.getByText('Blood links')).toBeInTheDocument()
    expect(screen.getByText('Connected points attract. All points repel.')).toBeInTheDocument()
    expect(document.querySelector('.person-details-panel')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Regenerate blood network' })).toBeInTheDocument()
    await userEvent.click(document.querySelector('.blood-network-force-settings summary')!)
    const parentPairSpring = screen.getByRole('slider', { name: 'Parent-pair spring' })
    expect(parentPairSpring).toHaveValue('0.2')
    expect(screen.getByRole('slider', { name: 'Parent hinge target angle (°)' })).toHaveValue('45')
    expect(screen.getByRole('slider', { name: 'Parent hinge spring' })).toHaveValue('4')
    expect(screen.getByRole('slider', { name: 'Descendant-side force' })).toHaveValue('12')
    expect(screen.getByRole('slider', { name: 'Person flow hinge' })).toHaveValue('8')
    expect(screen.getByRole('slider', { name: 'Shared-parent spring' })).toHaveValue('0.12')
    expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('3')
    expect(screen.getByRole('slider', { name: 'Distant-kin repulsion' })).toHaveValue('0.3')
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
    await userEvent.click(document.querySelector('.blood-network-force-settings summary')!)

    expect(screen.getByRole('combobox', { name: 'Force preset' })).toHaveValue('weak')
    expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('8')
    expect(screen.getByRole('checkbox', { name: 'Default' })).toBeChecked()

    const presetName = screen.getByRole('textbox', { name: 'Preset name' })
    await userEvent.clear(presetName)
    await userEvent.type(presetName, 'Open')
    fireEvent.change(screen.getByRole('slider', { name: 'Base repulsion' }), {
      target: { value: '7' },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Reload preset' }))
    expect(screen.getByRole('slider', { name: 'Base repulsion' })).toHaveValue('8')

    expect(request).not.toHaveBeenCalledWith(
      '/me/blood-network-presets',
      expect.anything(),
    )
    fireEvent.change(screen.getByRole('slider', { name: 'Base repulsion' }), {
      target: { value: '7' },
    })
    const savePreset = screen.getByRole('button', { name: 'Save preset' })
    expect(savePreset).toBeEnabled()
    await userEvent.click(savePreset)

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      '/me/blood-network-presets',
      expect.objectContaining({
        body: expect.stringContaining('"name":"Open"'),
        method: 'PATCH',
      }),
    ))
  })

  it('shows a worker preview and applies only its coordinate mutation', async () => {
    vi.stubGlobal('Worker', OptimizerWorkerMock)
    const partner = { ...spouse, id: 'partner-two', coordinates: { x: 640, y: 0 } }
    const tree = graphSnapshot([person, spouse, partner], [], [
      marriage,
      { ...marriage, id: 'marriage-two', malePersonId: partner.id },
    ])
    const optimized = {
      ...tree,
      etag: '"tree-optimized"',
      people: tree.people.map((item) => item.id === partner.id
        ? { ...item, coordinates: { x: -320, y: 0 } }
        : item),
    }
    const request = vi.fn(async (
      _path: string,
      init?: RequestInit,
    ) => init?.method === 'POST' ? optimized : tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('button', { name: 'Optimize layout' })
    await userEvent.click(screen.getByRole('button', { name: 'Optimize layout' }))
    expect(screen.queryByText('Optimizing layout')).not.toBeInTheDocument()
    const worker = OptimizerWorkerMock.instances[0]!
    worker.emit({ type: 'progress', checked: 2n, total: 6n })
    expect(screen.queryByText(/checked 2 of 6/)).not.toBeInTheDocument()
    worker.emit({
      type: 'result',
      result: {
        candidateCount: 6n,
        checkedCount: 6n,
        coordinateUpdates: [{ personId: partner.id, coordinates: { x: -320, y: 0 } }],
        currentWidth: 900,
        limited: false,
        optimizedWidth: 580,
      },
    })

    expect(await screen.findByText('Layout preview')).toBeInTheDocument()
    const originalLayout = screen.getByRole('button', { name: 'Original layout' })
    const newLayout = screen.getByRole('button', { name: 'New layout' })
    expect(newLayout).toHaveAttribute('aria-pressed', 'true')
    await userEvent.click(originalLayout)
    expect(originalLayout).toHaveAttribute('aria-pressed', 'true')
    await userEvent.click(screen.getByRole('button', { name: 'Apply' }))
    await waitFor(() => expect(request).toHaveBeenLastCalledWith(
      `/trees/${treeId}/graph-mutations`,
      expect.objectContaining({
        body: JSON.stringify({
          type: 'reflow_coordinates',
          coordinateUpdates: [{ personId: partner.id, coordinates: { x: -320, y: 0 } }],
        }),
        headers: { 'If-Match': '"tree-optimized"' },
        method: 'POST',
      }),
    ))
  })

  it('shows calculation progress separately only after a long-running search', async () => {
    vi.stubGlobal('Worker', OptimizerWorkerMock)
    const partner = { ...spouse, id: 'partner-two', coordinates: { x: 640, y: 0 } }
    const tree = graphSnapshot([person, spouse, partner], [], [
      marriage,
      { ...marriage, id: 'marriage-two', malePersonId: partner.id },
    ])
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('button', { name: 'Optimize layout' })
    vi.useFakeTimers()
    fireEvent.click(screen.getByRole('button', { name: 'Optimize layout' }))
    expect(screen.queryByText('Optimizing layout')).not.toBeInTheDocument()
    act(() => vi.advanceTimersByTime(2_500))
    expect(screen.getByText('Optimizing layout')).toBeInTheDocument()
    expect(screen.getByText(/checked 0 of 6/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(OptimizerWorkerMock.instances[0]?.terminate).toHaveBeenCalledTimes(1)
    vi.useRealTimers()
  })

  it('warns before a search exceeding ten thousand layouts and can cancel it', async () => {
    const center = { ...person, id: 'center', coordinates: { x: 0, y: 0 } }
    const partners = Array.from({ length: 8 }, (_, index) => ({
      ...spouse,
      id: `partner-${index}`,
      coordinates: { x: (index + 1) * 320, y: 0 },
    }))
    const tree = graphSnapshot(
      [center, ...partners],
      [],
      partners.map((partner, index) => ({
        ...marriage,
        id: `marriage-${index}`,
        malePersonId: partner.id,
        femalePersonId: center.id,
      })),
    )
    const request = vi.fn(async () => tree) as unknown as AuthorizedJsonRequest
    renderEditor(request)

    await screen.findByRole('button', { name: 'Optimize layout' })
    await userEvent.click(screen.getByRole('button', { name: 'Optimize layout' }))
    expect(await screen.findByRole('dialog', { name: 'Many possible layouts' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('dialog', { name: 'Many possible layouts' })).not.toBeInTheDocument()
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
      within(photoActions as HTMLElement).getByRole('button', { name: 'Add' }),
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
          person: expect.objectContaining({ gender: 'unknown' }),
          coordinates: { x: -160, y: -240 },
        },
        coordinateUpdates: [{
          personId: person.id,
          coordinates: { x: -160, y: 0 },
        }],
      })
    })
    expect(screen.getAllByText('Petrov Ivan')).toHaveLength(2)
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
    expect(await screen.findAllByText('Ivanov Petr')).toHaveLength(2)
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
          coordinateUpdates: [],
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
