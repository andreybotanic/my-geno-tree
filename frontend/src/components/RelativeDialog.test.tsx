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
    birthPlace: null,
    deathPlace: null,
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
  layoutRevision: null,
  layout: null,
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
    expect(
      within(dialog).getByRole('combobox', { name: 'Gender' }),
    ).toHaveValue('female')
    const marriageDate = within(dialog).getByRole('group', {
      name: 'Marriage date',
    })
    const marriageUnknown = within(marriageDate).getByRole('button', {
      name: 'Unknown',
    })
    expect(marriageUnknown).toHaveAttribute('aria-pressed', 'false')
    expect(
      within(marriageDate).getByRole('combobox', {
        name: 'Marriage date: Date type',
      }),
    ).toHaveValue('exact')
    const maleSurname = within(dialog).getByRole('textbox', {
      name: 'New surname of the man',
    })
    const marriageEnded = within(dialog).getByRole('checkbox', {
      name: 'Marriage ended',
    })
    expect(
      maleSurname.compareDocumentPosition(marriageEnded)
        & Node.DOCUMENT_POSITION_FOLLOWING,
    ).not.toBe(0)
    await userEvent.click(
      marriageEnded,
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
        marriagePlace: null,
        dissolutionDate: { state: 'unknown' },
        dissolutionPlace: null,
        maleNewSurname: 'Newman',
        femaleNewSurname: 'Newwoman',
        parentRole: 'father',
        secondParent: null,
      },
    )
  })

  it('preselects the gender for a new father or mother', () => {
    const props = {
      anchor,
      onCancel: vi.fn(),
      onSave: vi.fn(async () => {}),
      snapshot,
    }
    const { unmount } = render(<RelativeDialog {...props} action="father" />)

    expect(screen.getByRole('combobox', { name: 'Gender' })).toHaveValue('male')

    unmount()
    render(<RelativeDialog {...props} action="mother" />)

    expect(screen.getByRole('combobox', { name: 'Gender' })).toHaveValue('female')
  })

  it('shows parents first and links an existing child with its selected co-parent', async () => {
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
    expect(within(dialog).getByRole('combobox', { name: 'Mother' }))
      .toHaveValue(second.id)
    expect(within(dialog).getByText('Father')).toBeInTheDocument()
    expect(within(dialog).getByRole('combobox', { name: 'Mother' }))
      .toHaveValue(second.id)
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )

    expect(onSave).toHaveBeenCalledWith(
      { personId: candidate.id },
      expect.objectContaining({
        parentRole: 'father',
        secondParent: { personId: second.id },
      }),
    )
  })

  it('groups eligible spouses first and creates a fixed-gender second parent', async () => {
    const spouseSnapshot: TreeSnapshot = {
      ...snapshot,
      marriages: [{
        id: 'marriage',
        malePersonId: anchor.id,
        femalePersonId: second.id,
        marriageDate: { state: 'unknown' },
        marriagePlace: null,
        dissolutionDate: null,
        dissolutionPlace: null,
        createdAt,
        updatedAt: createdAt,
      }],
    }
    render(
      <RelativeDialog
        action="child"
        anchor={anchor}
        onCancel={vi.fn()}
        onSave={vi.fn(async () => {})}
        snapshot={spouseSnapshot}
      />,
    )

    const dialog = screen.getByRole('dialog', { name: 'Add child' })
    const mother = within(dialog).getByRole('combobox', { name: 'Mother' })
    expect(mother.querySelector('optgroup')?.label).toBe('Spouses')
    expect(mother.querySelectorAll('optgroup')).toHaveLength(2)
    await userEvent.click(within(dialog).getByRole('radio', {
      name: 'New mother',
    }))

    const newParent = dialog.querySelector('.child-new-parent-fields')
    expect(newParent).not.toBeNull()
    expect(within(newParent as HTMLElement).getByRole('combobox', { name: 'Gender' }))
      .toHaveValue('female')
    expect(within(newParent as HTMLElement).getByRole('combobox', { name: 'Gender' }))
      .toBeDisabled()
  })

  it('shows mother and father as separate fixed blocks for a family child', () => {
    render(
      <RelativeDialog
        action="child"
        anchor={anchor}
        fixedParentIds={[anchor.id, second.id]}
        onCancel={vi.fn()}
        onSave={vi.fn(async () => {})}
        snapshot={snapshot}
      />,
    )
    const dialog = screen.getByRole('dialog', { name: 'Add child' })
    const parents = dialog.querySelectorAll('.child-parent-summary')
    expect(parents).toHaveLength(2)
    expect(parents[0]).toHaveTextContent('Mother')
    expect(parents[0]).toHaveTextContent('Second')
    expect(parents[1]).toHaveTextContent('Father')
    expect(parents[1]).toHaveTextContent('Petrov')
    expect(
      parents[1]!.compareDocumentPosition(
        within(dialog).getByRole('textbox', { name: 'Surname' }),
      ) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).not.toBe(0)
  })

  it('asks for a parent role when the selected person has an unknown gender', async () => {
    render(
      <RelativeDialog
        action="child"
        anchor={person('unknown', 'Unknown', 'unknown')}
        onCancel={vi.fn()}
        onSave={vi.fn(async () => {})}
        snapshot={snapshot}
      />,
    )
    const dialog = screen.getByRole('dialog', { name: 'Add child' })
    const role = within(dialog).getByRole('combobox', {
      name: 'Selected person is the',
    })
    expect(role).toHaveValue('')
    await userEvent.selectOptions(role, 'mother')
    expect(within(dialog).getByRole('combobox', { name: 'Father' }))
      .toBeInTheDocument()
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

  it('explains how to provide a blank marriage date and can return to new mode', async () => {
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
      .toHaveTextContent(
        'Specify the marriage date and, if applicable, the dissolution date',
      )

    await userEvent.click(
      within(dialog).getByRole('radio', { name: 'New person' }),
    )
    expect(screen.getByRole('dialog', { name: 'Add spouse' }))
      .toBeInTheDocument()
  })
})
