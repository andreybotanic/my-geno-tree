import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { PersonFormDialog } from './PersonFormDialog'

describe('PersonFormDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('builds known names and structured life dates', async () => {
    const onSave = vi.fn(async () => {})
    render(<PersonFormDialog onCancel={vi.fn()} onSave={onSave} />)
    const dialog = screen.getByRole('dialog', {
      name: 'Create the first person',
    })
    const surname = within(dialog).getByRole('group', {
      name: 'Birth surname',
    })
    await userEvent.click(within(surname).getByRole('checkbox'))
    await userEvent.type(
      within(surname).getByRole('textbox', { name: 'Birth surname' }),
      ' Ivanova ',
    )
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Gender' }),
      'female',
    )

    const birth = within(dialog).getByRole('group', { name: 'Birth date' })
    await userEvent.click(within(birth).getByRole('checkbox'))
    await userEvent.selectOptions(
      within(birth).getByRole('combobox', { name: 'Birth date: Precision' }),
      'month',
    )
    await userEvent.type(
      within(birth).getByRole('spinbutton', { name: 'Birth date: Year' }),
      '1900',
    )
    await userEvent.type(
      within(birth).getByRole('spinbutton', { name: 'Birth date: Month' }),
      '2',
    )

    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Deceased' }),
    )
    const death = within(dialog).getByRole('group', { name: 'Death date' })
    await userEvent.click(within(death).getByRole('checkbox'))
    await userEvent.selectOptions(
      within(death).getByRole('combobox', { name: 'Death date: Precision' }),
      'day',
    )
    await userEvent.type(
      within(death).getByRole('spinbutton', { name: 'Death date: Year' }),
      '1980',
    )
    await userEvent.type(
      within(death).getByRole('spinbutton', { name: 'Death date: Month' }),
      '3',
    )
    await userEvent.type(
      within(death).getByRole('spinbutton', { name: 'Death date: Day' }),
      '4',
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      birthSurname: { state: 'known', value: 'Ivanova' },
      givenName: { state: 'unknown' },
      patronymic: { state: 'unknown' },
      gender: 'female',
      birthDate: {
        state: 'known',
        precision: 'month',
        year: 1900,
        month: 2,
        approximate: false,
      },
      deathDate: {
        state: 'known',
        precision: 'day',
        year: 1980,
        month: 3,
        day: 4,
        approximate: false,
      },
    })
  })

  it('keeps the dialog open for invalid known input', async () => {
    const onSave = vi.fn(async () => {})
    render(<PersonFormDialog onCancel={vi.fn()} onSave={onSave} />)
    const surname = screen.getByRole('group', { name: 'Birth surname' })
    await userEvent.click(within(surname).getByRole('checkbox'))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Check the entered name and date values.',
    )
    expect(onSave).not.toHaveBeenCalled()
  })

  it.each([
    [
      new ApiRequestError(412, {
        code: 'TREE_VERSION_CONFLICT',
        message: 'tree_version_conflict',
        fields: {},
        details: {},
      }),
      'This tree changed in another session. Reload the current list and try again.',
    ],
    [new Error('network'), 'The value could not be saved.'],
  ])('reports a rejected save without closing', async (error, message) => {
    const onSave = vi.fn(async () => {
      throw error
    })
    render(<PersonFormDialog onCancel={vi.fn()} onSave={onSave} />)

    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
    })
  })

  it('cancels without submitting', async () => {
    const onCancel = vi.fn()
    const onSave = vi.fn(async () => {})
    render(<PersonFormDialog onCancel={onCancel} onSave={onSave} />)

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onCancel).toHaveBeenCalledOnce()
    expect(onSave).not.toHaveBeenCalled()
  })
})
