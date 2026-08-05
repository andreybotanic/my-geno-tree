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
    precision: 'year',
    year: 1900,
    approximate: true,
  },
  deathDate: null,
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
  onPatch = vi.fn(async () => {}),
  value = person,
}: {
  conflict?: boolean
  events?: GenealogicalEvent[]
  onClose?: () => void
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
      onEditMarriage={vi.fn()}
      onOpenPhoto={vi.fn()}
      onPatch={onPatch}
      onReorderEvents={vi.fn(async () => {})}
      onRemoveMarriage={vi.fn()}
      onRemoveParentLink={vi.fn()}
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
        people: [value],
        parentLinks: [],
        marriages: [],
      }}
      />
    </AuthContext>,
  )
  return { onClose, onPatch }
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
    expect(screen.getByText('No photo')).toBeInTheDocument()
    expect(screen.getAllByText('c. 1900')).toHaveLength(2)
    expect(screen.queryByText('Automatic event')).not.toBeInTheDocument()
    const panel = screen.getByRole('complementary', {
      name: 'Person details',
    })
    expect(panel.lastElementChild).toContainElement(
      screen.getByRole('button', { name: 'Delete person' }),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Close' }))
    expect(onClose).toHaveBeenCalledOnce()
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
      screen.getByRole('button', { name: 'Edit Birth surname' }),
    )
    const input = screen.getByRole('textbox', { name: 'Birth surname' })
    await userEvent.clear(input)
    await userEvent.type(input, '{Enter}')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The value could not be saved.',
    )
    expect(onPatch).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('textbox', { name: 'Birth surname' }))
      .not.toBeInTheDocument()
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
    await userEvent.selectOptions(
      within(dateEditor).getByRole('combobox', {
        name: 'Birth date: Precision',
      }),
      'month',
    )
    await userEvent.type(
      within(dateEditor).getByRole('spinbutton', {
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
        precision: 'month',
        year: 1900,
        month: 5,
        approximate: false,
      },
    })
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

  it('does not start another edit after a version conflict', async () => {
    renderPanel({ conflict: true })

    await userEvent.click(
      screen.getByRole('button', { name: 'Edit Given name' }),
    )

    expect(screen.queryByRole('textbox', { name: 'Given name' }))
      .not.toBeInTheDocument()
  })
})
