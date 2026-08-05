import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { Person, TreeSnapshot } from '../api/generated'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { RelativeDialog } from './RelativeDialog'

const createdAt = '2026-07-31T00:00:00+00:00'

function person(
  id: string,
  name: string,
  gender: Person['gender'],
): Person {
  return {
    id,
    birthSurname: { state: 'known', value: name },
    givenName: { state: 'unknown' },
    patronymic: { state: 'unknown' },
    gender,
    birthDate: { state: 'unknown' },
    deathDate: null,
    photoThumbnailUrl: null,
    createdAt,
    updatedAt: createdAt,
  }
}

const anchor = person('anchor', 'Petrov', 'male')
const candidate = person('candidate', 'Petrova', 'female')
const second = person('second', 'Second', 'female')
const snapshot: TreeSnapshot = {
  tree: { id: 'tree', name: 'Tree', reference: null, createdAt, updatedAt: createdAt },
  etag: '"tree"',
  people: [anchor, candidate, second],
  parentLinks: [],
  marriages: [],
}

describe('RelativeDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('creates a new spouse with period metadata', async () => {
    const onSave = vi.fn(async () => {})
    render(
      <RelativeDialog
        action="spouse"
        anchor={anchor}
        onCancel={vi.fn()}
        onSave={onSave}
        snapshot={snapshot}
      />,
    )
    const dialog = screen.getByRole('dialog', { name: 'Add spouse' })
    const marriageDate = within(dialog).getByRole('group', {
      name: 'Marriage date',
    })
    const marriageUnknown = within(marriageDate).getByRole('button', {
      name: 'Unknown',
    })
    expect(marriageUnknown).toHaveAttribute('aria-pressed', 'false')
    expect(
      within(marriageDate).getByRole('combobox', {
        name: 'Marriage date: Precision',
      }),
    ).toHaveValue('day')
    expect(
      within(marriageDate).getByRole('spinbutton', {
        name: 'Marriage date: Day',
      }),
    ).toBeEnabled()
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Gender' }),
      'female',
    )
    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Marriage ended' }),
    )
    expect(
      within(dialog).getByRole('checkbox', { name: 'Marriage ended' })
        .closest('label'),
    ).toHaveClass('choice-control', 'conditional-date-toggle')
    await userEvent.type(
      within(dialog).getByRole('textbox', {
        name: 'New surname of the man',
      }),
      ' Newman ',
    )
    await userEvent.type(
      within(dialog).getByRole('textbox', {
        name: 'New surname of the woman',
      }),
      ' Newwoman ',
    )
    for (const button of within(dialog).getAllByRole('button', {
      name: 'Unknown',
    })) {
      if (button.getAttribute('aria-pressed') === 'false') {
        await userEvent.click(button)
      }
    }
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({
        person: expect.objectContaining({ gender: 'female' }),
      }),
      {
        marriageDate: { state: 'unknown' },
        dissolutionDate: { state: 'unknown' },
        maleNewSurname: 'Newman',
        femaleNewSurname: 'Newwoman',
        parentRole: 'father',
        secondParentId: null,
      },
    )
  })

  it('links an existing child and keeps an explicitly selected co-parent', async () => {
    const onSave = vi.fn(async () => {})
    render(
      <RelativeDialog
        action="child"
        anchor={anchor}
        initialSecondParentId={second.id}
        onCancel={vi.fn()}
        onSave={onSave}
        snapshot={snapshot}
      />,
    )
    let dialog = screen.getByRole('dialog', { name: 'Add child' })
    await userEvent.click(
      within(dialog).getByRole('radio', { name: 'Existing person' }),
    )
    dialog = screen.getByRole('dialog', { name: 'Add child' })
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Person' }),
      candidate.id,
    )
    expect(
      within(dialog).getByRole('combobox', { name: 'Second parent' }),
    ).toHaveValue(second.id)
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', {
        name: 'Selected person is the',
      }),
      'mother',
    )
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    expect(onSave).toHaveBeenCalledWith(
      { personId: candidate.id },
      expect.objectContaining({
        parentRole: 'mother',
        secondParentId: second.id,
      }),
    )
  })

  it('validates existing selection and reports ETag conflicts', async () => {
    const onSave = vi.fn(async () => {
      throw new ApiRequestError(412, {
        code: 'TREE_VERSION_CONFLICT',
        message: 'tree_version_conflict',
        fields: {},
        details: {},
      })
    })
    render(
      <RelativeDialog
        action="father"
        anchor={anchor}
        onCancel={vi.fn()}
        onSave={onSave}
        snapshot={snapshot}
      />,
    )
    let dialog = screen.getByRole('dialog', { name: 'Add father' })
    await userEvent.click(
      within(dialog).getByRole('radio', { name: 'Existing person' }),
    )
    dialog = screen.getByRole('dialog', { name: 'Add father' })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await within(dialog).findByRole('alert'))
      .toHaveTextContent('Choose a person')

    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Person' }),
      candidate.id,
    )
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await within(dialog).findByRole('alert'))
      .toHaveTextContent('changed in another session')
  })

  it('rejects a blank known marriage date and can return to new mode', async () => {
    render(
      <RelativeDialog
        action="spouse"
        anchor={anchor}
        onCancel={vi.fn()}
        onSave={vi.fn(async () => {})}
        snapshot={snapshot}
      />,
    )
    let dialog = screen.getByRole('dialog', { name: 'Add spouse' })
    await userEvent.click(
      within(dialog).getByRole('radio', { name: 'Existing person' }),
    )
    dialog = screen.getByRole('dialog', { name: 'Add spouse' })
    await userEvent.selectOptions(
      within(dialog).getByRole('combobox', { name: 'Person' }),
      candidate.id,
    )
    const marriage = within(dialog).getByRole('group', {
      name: 'Marriage date',
    })
    expect(
      within(marriage).getByRole('button', { name: 'Unknown' }),
    ).toHaveAttribute('aria-pressed', 'false')
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await within(dialog).findByRole('alert'))
      .toHaveTextContent('relationship could not be saved')

    await userEvent.click(
      within(dialog).getByRole('radio', { name: 'New person' }),
    )
    expect(screen.getByRole('dialog', { name: 'Add spouse' }))
      .toBeInTheDocument()
  })
})
