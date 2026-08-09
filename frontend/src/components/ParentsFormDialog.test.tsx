import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { ParentsFormDialog } from './ParentsFormDialog'

async function markPersonUnknown(container: HTMLElement) {
  for (const button of within(container).getAllByRole('button', {
    name: 'Unknown',
  })) {
    if (button.getAttribute('aria-pressed') === 'false') {
      await userEvent.click(button)
    }
  }
}

describe('ParentsFormDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('creates two new parents without a marriage by default', async () => {
    const onSave = vi.fn(async () => {})
    render(<ParentsFormDialog onCancel={vi.fn()} onSave={onSave} />)

    const dialog = screen.getByRole('dialog', { name: 'Add parents' })
    const father = within(dialog).getByRole('group', { name: 'Father' })
    const mother = within(dialog).getByRole('group', { name: 'Mother' })
    expect(within(father).getByRole('combobox', { name: 'Gender' }))
      .toBeDisabled()
    expect(within(father).getByRole('combobox', { name: 'Gender' }))
      .toHaveValue('male')
    expect(within(mother).getByRole('combobox', { name: 'Gender' }))
      .toBeDisabled()
    expect(within(mother).getByRole('combobox', { name: 'Gender' }))
      .toHaveValue('female')
    expect(within(dialog).getByRole('checkbox', { name: 'Married' }))
      .not.toBeChecked()
    expect(within(dialog).queryByRole('group', { name: 'Marriage date' }))
      .not.toBeInTheDocument()

    await markPersonUnknown(father)
    await markPersonUnknown(mother)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      father: {
        birthSurname: { state: 'unknown' },
        givenName: { state: 'unknown' },
        patronymic: { state: 'unknown' },
        gender: 'male',
        birthDate: { state: 'unknown' },
        deathDate: null,
      },
      mother: {
        birthSurname: { state: 'unknown' },
        givenName: { state: 'unknown' },
        patronymic: { state: 'unknown' },
        gender: 'female',
        birthDate: { state: 'unknown' },
        deathDate: null,
      },
      marriage: null,
    })
  })

  it('submits an ended marriage with surname changes', async () => {
    const onSave = vi.fn(async () => {})
    render(<ParentsFormDialog onCancel={vi.fn()} onSave={onSave} />)
    const dialog = screen.getByRole('dialog', { name: 'Add parents' })
    await markPersonUnknown(
      within(dialog).getByRole('group', { name: 'Father' }),
    )
    await markPersonUnknown(
      within(dialog).getByRole('group', { name: 'Mother' }),
    )
    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Married' }),
    )
    await userEvent.click(
      within(
        within(dialog).getByRole('group', { name: 'Marriage date' }),
      ).getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.type(
      within(dialog).getByRole('textbox', {
        name: 'New surname of the husband',
      }),
      'Newman',
    )
    await userEvent.type(
      within(dialog).getByRole('textbox', {
        name: 'New surname of the wife',
      }),
      'Newwoman',
    )
    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Marriage ended' }),
    )
    await userEvent.click(
      within(
        within(dialog).getByRole('group', { name: 'Dissolution date' }),
      ).getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({
      marriage: {
        marriageDate: { state: 'unknown' },
        dissolutionDate: { state: 'unknown' },
        maleNewSurname: 'Newman',
        femaleNewSurname: 'Newwoman',
      },
    }))
  })

  it('requires enabled marriage dates and keeps the dialog open', async () => {
    const onSave = vi.fn(async () => {})
    render(<ParentsFormDialog onCancel={vi.fn()} onSave={onSave} />)
    const dialog = screen.getByRole('dialog', { name: 'Add parents' })
    await markPersonUnknown(
      within(dialog).getByRole('group', { name: 'Father' }),
    )
    await markPersonUnknown(
      within(dialog).getByRole('group', { name: 'Mother' }),
    )
    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Married' }),
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Specify the marriage date',
    )
    expect(onSave).not.toHaveBeenCalled()
  })

  it('reports conflicts and cancels without submitting', async () => {
    const onCancel = vi.fn()
    const onSave = vi.fn(async () => {
      throw new ApiRequestError(412, {
        code: 'TREE_VERSION_CONFLICT',
        message: 'tree_version_conflict',
        fields: {},
        details: {},
      })
    })
    render(<ParentsFormDialog onCancel={onCancel} onSave={onSave} />)
    const dialog = screen.getByRole('dialog', { name: 'Add parents' })
    await markPersonUnknown(
      within(dialog).getByRole('group', { name: 'Father' }),
    )
    await markPersonUnknown(
      within(dialog).getByRole('group', { name: 'Mother' }),
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'This tree changed in another session.',
    )
    await waitFor(() => {
      expect(within(dialog).getByRole('button', { name: 'Save' })).toBeEnabled()
    })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(onCancel).toHaveBeenCalledOnce()
  })
})
