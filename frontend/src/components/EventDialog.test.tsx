import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { GenealogicalEvent } from '../api/generated'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { EventDialog } from './EventDialog'

const surnameEvent: GenealogicalEvent = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a8',
  personId: 'person',
  type: 'surname_change',
  source: 'manual',
  date: {
    state: 'known',
    year: 1920,
    kind: 'approximate',
  },
  place: null,
  title: null,
  description: null,
  data: { newSurname: 'Petrova' },
  sourceEntityType: null,
  sourceEntityId: null,
  manualOrder: null,
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
}

describe('EventDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('creates a custom event with a structured date and description', async () => {
    const onSave = vi.fn(async () => {})
    render(<EventDialog onCancel={vi.fn()} onSave={onSave} />)
    const dialog = screen.getByRole('dialog', { name: 'Add an event' })
    const eventDate = within(dialog).getByRole('group', {
      name: 'Event date',
    })
    expect(
      within(eventDate).getByRole('button', { name: 'Unknown' }),
    ).toHaveAttribute('aria-pressed', 'false')
    expect(
      within(eventDate).getByRole('combobox', {
        name: 'Event date: Date type',
      }),
    ).toHaveValue('exact')
    await userEvent.type(
      within(eventDate).getByRole('spinbutton', {
        name: 'Event date: Year',
      }),
      '1950',
    )
    await userEvent.selectOptions(
      within(eventDate).getByRole('combobox', {
        name: 'Event date: Month',
      }),
      '7',
    )
    await userEvent.type(
      within(dialog).getByRole('textbox', { name: 'Title' }),
      '  New home  ',
    )
    await userEvent.type(
      within(dialog).getByRole('textbox', { name: 'Description' }),
      'First line{enter}Second line',
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      type: 'custom',
      date: {
        state: 'known',
        year: 1950,
        month: 7,
        kind: 'exact',
      },
      title: 'New home',
      description: 'First line\nSecond line',
      place: null,
    })
  })

  it('edits a surname change without altering its approximate date', async () => {
    const onSave = vi.fn(async () => {})
    render(
      <EventDialog
        event={surnameEvent}
        onCancel={vi.fn()}
        onSave={onSave}
      />,
    )
    const surname = screen.getByRole('textbox', { name: 'New surname' })
    await userEvent.clear(surname)
    await userEvent.type(surname, '  Sidorova  ')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      type: 'surname_change',
      date: surnameEvent.date,
      newSurname: 'Sidorova',
      place: null,
    })
  })

  it('creates an event between two partial dates', async () => {
    const onSave = vi.fn(async () => {})
    render(<EventDialog onCancel={vi.fn()} onSave={onSave} />)
    const date = screen.getByRole('group', { name: 'Event date' })
    await userEvent.selectOptions(
      within(date).getByRole('combobox', { name: 'Event date: Date type' }),
      'between',
    )
    const from = within(date).getByRole('group', { name: 'From' })
    const to = within(date).getByRole('group', { name: 'To' })
    await userEvent.type(
      within(from).getByRole('spinbutton', { name: 'From: Year' }),
      '1950',
    )
    await userEvent.type(
      within(to).getByRole('spinbutton', { name: 'To: Year' }),
      '1951',
    )
    await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Move')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      type: 'custom',
      date: {
        state: 'known',
        kind: 'between',
        year: 1950,
        end: { year: 1951 },
      },
      title: 'Move',
      description: null,
      place: null,
    })
  })

  it('validates required values and reports an ETag conflict', async () => {
    const onSave = vi.fn(async () => {
      throw new ApiRequestError(412, {
        code: 'TREE_VERSION_CONFLICT',
        message: 'tree_version_conflict',
        fields: {},
        details: {},
      })
    })
    render(<EventDialog onCancel={vi.fn()} onSave={onSave} />)
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Check the entered name and date values.',
    )
    expect(onSave).not.toHaveBeenCalled()

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Title' }),
      'Valid title',
    )
    await userEvent.click(
      screen.getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This tree changed in another session.',
    )
  })

  it('creates a residence with an unknown date and requires resolving imported places', async () => {
    const onSave = vi.fn(async () => {})
    const { unmount } = render(
      <EventDialog onCancel={vi.fn()} onSave={onSave} />,
    )
    await userEvent.selectOptions(
      screen.getByRole('combobox', { name: 'Event type' }),
      'residence',
    )
    await userEvent.click(
      screen.getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Description' }),
      'Moved for work',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(onSave).toHaveBeenCalledWith({
      type: 'residence',
      date: { state: 'unknown' },
      description: 'Moved for work',
      place: null,
    })
    unmount()

    const unresolvedSave = vi.fn(async () => {})
    render(
      <EventDialog
        event={{
          ...surnameEvent,
          place: { name: 'Imported village', coordinates: null },
        }}
        onCancel={vi.fn()}
        onSave={unresolvedSave}
      />,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Check the entered name and date values.',
    )
    expect(unresolvedSave).not.toHaveBeenCalled()
  })
})
