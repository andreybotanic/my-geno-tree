import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type {
  GenealogicalEvent,
  Person,
  PersonPatchRequest,
} from '../api/generated'
import i18n from '../i18n'
import { AuthContext, type AuthContextValue } from '../auth/AuthContext'
import { PersonDetailsPanel } from './PersonDetailsPanel'

const person: Person = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a6',
  birthSurname: { state: 'known', value: 'Ivanova' },
  givenName: { state: 'known', value: 'Anna' },
  patronymic: { state: 'unknown' },
  gender: 'female',
  birthDate: {
    state: 'known',
    year: 1900,
    kind: 'approximate',
  },
  deathDate: null,
  birthPlace: null,
  deathPlace: null,
  photoThumbnailUrl: null,
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
}

const birthEvent: GenealogicalEvent = {
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

const renderPanel = ({
  conflict = false,
  events = [birthEvent],
  onClose = vi.fn(),
  onEditEventPlace = vi.fn(),
  onPatch = vi.fn(async () => {}),
  value = person,
}: {
  conflict?: boolean
  events?: GenealogicalEvent[]
  onClose?: () => void
  onEditEventPlace?: (event: GenealogicalEvent) => void
  onPatch?: (patch: PersonPatchRequest) => Promise<void>
  value?: Person
} = {}) => {
  const auth: AuthContextValue = {
    accessToken: 'access',
    isBooting: false,
    user: null,
    login: vi.fn(),
    logout: vi.fn(),
    register: vi.fn(),
    request: vi.fn(),
    requestBlob: vi.fn(async () => new Blob(['image'])),
    updateLanguage: vi.fn(),
    updateUser: vi.fn(),
  }
  render(
    <AuthContext value={auth}>
      <PersonDetailsPanel
      conflict={conflict}
      events={events}
      onAddRelative={vi.fn()}
      onClose={onClose}
      onCreateEvent={vi.fn()}
      onDeleteEvent={vi.fn()}
      onDeletePerson={vi.fn()}
      onDeletePhoto={vi.fn()}
      onEditEvent={vi.fn()}
      onEditEventPlace={onEditEventPlace}
      onEditMarriage={vi.fn()}
      onOpenPhoto={vi.fn()}
      onPatch={onPatch}
      onReorderEvents={vi.fn(async () => {})}
      onRemoveMarriage={vi.fn()}
      onRemoveParentLink={vi.fn()}
      onSelectPerson={vi.fn()}
      onUploadPhoto={vi.fn()}
      person={value}
      snapshot={{
        tree: {
          id: 'tree',
          name: 'Family',
          reference: null,
          createdAt: person.createdAt,
          updatedAt: person.updatedAt,
        },
        etag: '"tree"',
        layoutRevision: null,
        layout: null,
        people: [value],
        parentLinks: [],
        marriages: [],
      }}
      />
    </AuthContext>,
  )
  return { onClose, onEditEventPlace, onPatch }
}

describe('PersonDetailsPanel', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('renders a localized timeline and closes', async () => {
    const onClose = vi.fn()
    renderPanel({ onClose })

    expect(screen.getByRole('heading', { name: 'Ivanova Anna' }))
      .toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Personal data' }))
      .toBeInTheDocument()
    expect(document.querySelector('.panel-photo-placeholder')).toBeNull()
    expect(screen.getAllByRole('button', { name: 'Add' })).toHaveLength(5)
    expect(screen.getByRole('button', { name: 'Add photo' })).toBeInTheDocument()
    expect(screen.getAllByText('~ 1900')).toHaveLength(2)
    expect(screen.queryByText('Automatic event')).not.toBeInTheDocument()
    const panel = screen.getByRole('complementary', {
      name: 'Person details',
    })
    expect(panel.lastElementChild).toContainElement(
      screen.getByRole('button', { name: 'Delete person' }),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Close' }))
    expect(onClose).toHaveBeenCalledOnce()
    expect(screen.getByRole('button', { name: 'Close' }))
      .toContainElement(document.querySelector('.dialog-close-icon'))
  })

  it('copies the person UID in development builds', async () => {
    const writeText = vi.fn(async () => {})
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    })
    renderPanel()

    await userEvent.click(screen.getByRole('button', { name: 'Copy UID' }))

    expect(writeText).toHaveBeenCalledWith(person.id)
    expect(screen.getByRole('heading', { name: 'Ivanova Anna' })
      .parentElement).toContainElement(
      screen.getByRole('button', { name: 'Copy UID' }),
    )
  })

  it('renders a protected photo and an empty timeline', async () => {
    renderPanel({
      events: [],
      value: {
        ...person,
        photoThumbnailUrl: '/api/v1/media/photo/thumbnail',
      },
    })

    expect(await screen.findByRole('presentation')).toHaveAttribute(
      'src',
      'blob:test-image',
    )
    expect(screen.getByText('No events yet.')).toBeInTheDocument()
  })

  it('saves a trimmed known text and can switch it to unknown', async () => {
    const onPatch = vi.fn(async () => {})
    renderPanel({ onPatch })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )
    const nameInput = screen.getByRole('textbox', { name: 'Given name' })
    await userEvent.clear(nameInput)
    await userEvent.type(nameInput, ' Maria {Enter}')
    expect(onPatch).toHaveBeenCalledWith({
      givenName: { state: 'known', value: 'Maria' },
    })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )
    const editor = screen.getByRole('textbox', { name: 'Given name' })
      .closest('form')
    expect(editor).not.toBeNull()
    const unknownToggle = within(editor as HTMLFormElement).getByRole(
      'button',
      { name: 'Unknown' },
    )
    await userEvent.click(unknownToggle)
    expect(editor).toContainElement(
      within(editor as HTMLFormElement).getByRole('textbox', {
        name: 'Given name',
      }),
    )
    expect(
      within(editor as HTMLFormElement).getByRole('textbox', {
        name: 'Given name',
      }),
    ).toBeDisabled()
    expect(
      within(editor as HTMLFormElement).getByRole('textbox', {
        name: 'Given name',
      }),
    ).toHaveValue('')
    await userEvent.click(
      within(editor as HTMLFormElement).getByRole('button', { name: 'Save' }),
    )
    expect(onPatch).toHaveBeenLastCalledWith({
      givenName: { state: 'unknown' },
    })
  })

  it('keeps invalid text in edit mode and supports cancel', async () => {
    const onPatch = vi.fn(async () => {})
    renderPanel({ onPatch })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Surname' }),
    )
    const input = screen.getByRole('textbox', { name: 'Surname' })
    await userEvent.clear(input)
    await userEvent.type(input, '{Enter}')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The value could not be saved.',
    )
    expect(onPatch).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('textbox', { name: 'Surname' }))
      .not.toBeInTheDocument()
  })

  it('keeps text and select editors inside their original row height layout', async () => {
    renderPanel()

    const row = screen.getByText('Given name').closest('.person-detail-row')
    expect(row).not.toBeNull()
    await userEvent.click(screen.getByRole('button', { name: 'Edit Given name' }))
    expect(row?.querySelector('.inline-person-editor-compact')).not.toBeNull()
    expect(within(row as HTMLElement).getByRole('button', { name: 'Cancel' }))
      .toHaveTextContent('×')

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    await userEvent.click(screen.getByRole('button', { name: 'Edit Gender' }))
    const genderRow = screen.getByText('Gender').closest('.person-detail-row')
    expect(genderRow?.querySelector('.inline-person-editor-compact')).not.toBeNull()
    expect(within(genderRow as HTMLElement).getByRole('button', { name: 'Cancel' }))
      .toHaveTextContent('×')
  })

  it('saves gender and structured birth date changes', async () => {
    const onPatch = vi.fn(async () => {})
    renderPanel({ onPatch })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Gender' }),
    )
    const gender = screen.getByRole('combobox', { name: 'Gender' })
    await userEvent.selectOptions(gender, 'male')
    await userEvent.click(
      within(gender.closest('form') as HTMLFormElement)
        .getByRole('button', { name: 'Save' }),
    )
    expect(onPatch).toHaveBeenLastCalledWith({ gender: 'male' })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Birth date' }),
    )
    const dateEditor = screen.getByRole('group', { name: 'Birth date' })
    const dateRow = dateEditor.closest('.person-detail-row')
    expect(within(dateRow as HTMLElement).queryAllByText('Birth date')).toHaveLength(1)
    expect(dateRow?.querySelector('fieldset')).toBeNull()
    expect(within(dateRow as HTMLElement).getByRole('button', { name: 'Cancel' }))
      .toHaveTextContent('×')
    await userEvent.selectOptions(
      within(dateEditor).getByRole('combobox', {
        name: 'Birth date: Month',
      }),
      '5',
    )
    await userEvent.click(
      within(dateEditor.closest('form') as HTMLFormElement)
        .getByRole('button', { name: 'Save' }),
    )
    expect(onPatch).toHaveBeenLastCalledWith({
      birthDate: {
        state: 'known',
        year: 1900,
        month: 5,
        kind: 'approximate',
      },
    })
  })

  it('makes unknown values editable and saves a focused date change', async () => {
    const onPatch = vi.fn(async () => {})
    renderPanel({
      onPatch,
      value: {
        ...person,
        givenName: { state: 'unknown' },
        birthDate: { state: 'unknown' },
      },
    })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )
    const name = screen.getByRole('textbox', { name: 'Given name' })
    expect(name).toBeEnabled()
    expect(name).toHaveFocus()

    await userEvent.click(
      screen.getByRole('button', { name: 'Cancel' }),
    )
    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Birth date' }),
    )
    const dateEditor = screen.getByRole('group', { name: 'Birth date' })
    const year = within(dateEditor).getByRole('spinbutton', {
      name: 'Birth date: Year',
    })
    expect(year).toBeEnabled()
    expect(year).toHaveFocus()
    await userEvent.type(year, '2001')
    await userEvent.selectOptions(
      within(dateEditor).getByRole('combobox', { name: 'Birth date: Month' }),
      '2',
    )
    await userEvent.type(
      within(dateEditor).getByRole('spinbutton', { name: 'Birth date: Day' }),
      '3',
    )
    await userEvent.click(
      within(dateEditor.closest('form') as HTMLFormElement)
        .getByRole('button', { name: 'Save' }),
    )
    expect(onPatch).toHaveBeenLastCalledWith({
      birthDate: {
        state: 'known',
        year: 2001,
        month: 2,
        day: 3,
        kind: 'exact',
      },
    })
  })

  it('labels unknown dates in the sidebar', () => {
    renderPanel({
      value: {
        ...person,
        birthDate: { state: 'unknown' },
        deathDate: { state: 'unknown' },
      },
    })

    for (const label of ['Birth date', 'Death date']) {
      const row = screen.getByText(label).closest('.person-detail-row')
      expect(row).not.toBeNull()
      expect(within(row as HTMLElement).getByText('Unknown')).toBeInTheDocument()
    }
  })

  it('does not save a date with an invalid year while retaining its parts', async () => {
    const onPatch = vi.fn(async () => {})
    renderPanel({
      onPatch,
      value: {
        ...person,
        birthDate: {
          state: 'known',
          year: 1900,
          month: 2,
          day: 3,
          kind: 'exact',
        },
      },
    })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Birth date' }),
    )
    const editor = screen.getByRole('group', { name: 'Birth date' })
    await userEvent.clear(
      within(editor).getByRole('spinbutton', { name: 'Birth date: Year' }),
    )
    await userEvent.type(
      within(editor).getByRole('spinbutton', { name: 'Birth date: Year' }),
      '0001',
    )
    await userEvent.click(
      within(editor.closest('form') as HTMLFormElement)
        .getByRole('button', { name: 'Save' }),
    )

    expect(onPatch).not.toHaveBeenCalled()
    expect(within(editor).getByRole('combobox', {
      name: 'Birth date: Month',
    })).toHaveValue('2')
    expect(within(editor).getByRole('spinbutton', {
      name: 'Birth date: Day',
    })).toHaveValue(3)
  })

  it('distinguishes living from a deceased person with unknown date', async () => {
    const onPatch = vi.fn(async () => {})
    renderPanel({ onPatch })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Death date' }),
    )
    let form = screen.getByRole('checkbox', { name: 'Living' })
      .closest('form') as HTMLFormElement
    await userEvent.click(within(form).getByRole('button', { name: 'Save' }))
    expect(onPatch).toHaveBeenLastCalledWith({ deathDate: null })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Death date' }),
    )
    form = screen.getByRole('checkbox', { name: 'Living' })
      .closest('form') as HTMLFormElement
    await userEvent.click(
      within(form).getByRole('checkbox', { name: 'Living' }),
    )
    await userEvent.click(within(form).getByRole('button', { name: 'Save' }))
    expect(onPatch).toHaveBeenLastCalledWith({
      deathDate: { state: 'unknown' },
    })
  })

  it('shows birth place separately and hides death place for a living person', async () => {
    renderPanel()

    expect(screen.getByText('Place of birth')).toBeInTheDocument()
    expect(screen.queryByText('Place of death')).not.toBeInTheDocument()

    const deathEvent: GenealogicalEvent = {
      ...birthEvent,
      id: 'death-event',
      type: 'death',
      date: { state: 'unknown' },
    }
    const onEditEventPlace = vi.fn()
    renderPanel({
      events: [birthEvent, deathEvent],
      onEditEventPlace,
      value: {
        ...person,
        deathDate: { state: 'unknown' },
        deathPlace: {
          name: 'Imported death place',
          coordinates: null,
        },
      },
    })

    expect(screen.getByText('Place of death')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', {
      name: 'Imported death place',
    }))
    expect(onEditEventPlace).toHaveBeenCalledWith(deathEvent)
  })

  it('does not start another edit after a version conflict', async () => {
    renderPanel({ conflict: true })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )

    expect(screen.queryByRole('textbox', { name: 'Given name' }))
      .not.toBeInTheDocument()
  })
})
