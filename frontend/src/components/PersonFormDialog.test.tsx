import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { PersonFormDialog } from './PersonFormDialog'

async function activateUnpressedUnknownButtons(container: HTMLElement) {
  for (const button of within(container).getAllByRole('button', {
    name: 'Unknown',
  })) {
    if (button.getAttribute('aria-pressed') === 'false') {
      await userEvent.click(button)
    }
  }
}

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
      name: 'Surname',
    })
    const surnameUnknown = within(surname).getByRole('button', {
      name: 'Unknown',
    })
    const surnameInput = within(surname).getByRole('textbox', {
      name: 'Surname',
    })
    expect(surnameUnknown).toHaveAttribute('title', 'Unknown')
    expect(surnameUnknown).toHaveAttribute('aria-pressed', 'false')
    expect(surnameUnknown).toHaveAttribute('tabindex', '-1')
    expect(surnameInput).toBeEnabled()
    expect(surname).toHaveClass('person-text-field')
    expect(surname.tagName).toBe('DIV')
    await userEvent.type(
      surnameInput,
      ' Ivanova ',
    )
    await userEvent.click(
      within(dialog).getByRole('group', { name: 'Given name' })
        .querySelector('button') as HTMLButtonElement,
    )
    await userEvent.click(
      within(dialog).getByRole('group', { name: 'Patronymic' })
        .querySelector('button') as HTMLButtonElement,
    )
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Gender' }),
      'female',
    )

    const birth = within(dialog).getByRole('group', { name: 'Birth date' })
    const birthKind = within(birth).getByRole('combobox', {
      name: 'Birth date: Date type',
    })
    expect(birthKind).toHaveValue('exact')
    expect(
      within(birth).getByRole('button', { name: 'Unknown' }),
    ).toHaveAttribute('aria-pressed', 'false')
    expect(
      within(birth).getByRole('button', { name: 'Unknown' }),
    ).toHaveAttribute('tabindex', '-1')
    expect(within(birth).getByRole('button', { name: 'Unknown' })
      .closest('.date-kind-row')).toContainElement(birthKind)
    await userEvent.type(
      within(birth).getByRole('spinbutton', { name: 'Birth date: Year' }),
      '1900',
    )
    const birthMonth = within(birth).getByRole('combobox', {
      name: 'Birth date: Month',
    })
    expect(birthMonth).toHaveValue('')
    expect(
      within(birthMonth).getByRole('option', { name: 'February' }),
    ).toHaveValue('2')
    await userEvent.selectOptions(
      birthMonth,
      '2',
    )

    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Deceased' }),
    )
    const death = within(dialog).getByRole('group', { name: 'Death date' })
    const deceased = within(dialog).getByRole('checkbox', {
      name: 'Deceased',
    })
    expect(deceased.closest('label')).toHaveClass(
      'choice-control',
      'conditional-date-toggle',
    )
    expect(deceased.closest('.death-date-row')).toContainElement(death)
    expect(death).toHaveClass('date-fieldset')
    expect(within(death).getByRole('combobox', {
      name: 'Death date: Date type',
    })).toHaveValue('exact')
    await userEvent.type(
      within(death).getByRole('spinbutton', { name: 'Death date: Year' }),
      '1980',
    )
    await userEvent.selectOptions(
      within(death).getByRole('combobox', { name: 'Death date: Month' }),
      '3',
    )
    await userEvent.type(
      within(death).getByRole('textbox', { name: 'Death date: Day' }),
      '04',
    )
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      birthSurname: { state: 'known', value: 'Ivanova' },
      givenName: { state: 'unknown' },
      patronymic: { state: 'unknown' },
      gender: 'female',
      birthDate: {
        state: 'known',
        year: 1900,
        month: 2,
        kind: 'exact',
      },
      birthPlace: null,
      deathDate: {
        state: 'known',
        year: 1980,
        month: 3,
        day: 4,
        kind: 'exact',
      },
      deathPlace: null,
    })
  })

  it('keeps the dialog open for invalid known input', async () => {
    const onSave = vi.fn(async () => {})
    render(<PersonFormDialog onCancel={vi.fn()} onSave={onSave} />)
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Check the entered name and date values.',
    )
    expect(onSave).not.toHaveBeenCalled()
  })

  it('ignores invalid day edits and adjusts the day after changing the month or year', async () => {
    render(<PersonFormDialog onCancel={vi.fn()} onSave={vi.fn(async () => {})} />)
    const birth = within(screen.getByRole('dialog', {
      name: 'Create the first person',
    })).getByRole('group', { name: 'Birth date' })
    const year = within(birth).getByRole('spinbutton', { name: 'Birth date: Year' })
    const month = within(birth).getByRole('combobox', { name: 'Birth date: Month' })
    const day = within(birth).getByRole('textbox', { name: 'Birth date: Day' })

    await userEvent.type(year, '2000')
    await userEvent.selectOptions(month, '2')
    await userEvent.type(day, '4')
    expect(day).toHaveValue('')
    await userEvent.type(day, '29')
    expect(day).toHaveValue('29')
    await userEvent.type(day, '.')
    expect(day).toHaveValue('29')
    await userEvent.clear(year)
    await userEvent.type(year, '1900')
    expect(day).toHaveValue('')
    await userEvent.selectOptions(month, '4')
    await userEvent.type(day, '3')
    expect(day).toHaveValue('3')
    await userEvent.type(day, '1')
    expect(day).toHaveValue('3')
    await userEvent.clear(day)
    await userEvent.type(day, '30')
    expect(day).toHaveValue('30')
  })

  it('shows month names in the selected interface language', async () => {
    await i18n.changeLanguage('ru')
    render(<PersonFormDialog onCancel={vi.fn()} onSave={vi.fn(async () => {})} />)
    const birth = within(screen.getByRole('dialog', {
      name: 'Создание первой персоны',
    })).getByRole('group', { name: 'Дата рождения' })

    expect(
      within(
        within(birth).getByRole('combobox', { name: 'Дата рождения: Месяц' }),
      ).getByRole('option', { name: 'Январь' }),
    ).toHaveValue('1')
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

    await activateUnpressedUnknownButtons(
      screen.getByRole('dialog', { name: 'Create the first person' }),
    )
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
