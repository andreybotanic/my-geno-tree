import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { MarriagePeriod } from '../api/generated'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { MarriagePeriodDialog } from './MarriagePeriodDialog'

const marriage: MarriagePeriod = {
  id: 'marriage',
  malePersonId: 'male',
  femalePersonId: 'female',
  marriageDate: {
    state: 'known',
    precision: 'month',
    year: 2001,
    month: 2,
    approximate: false,
  },
  dissolutionDate: {
    state: 'known',
    precision: 'day',
    year: 2004,
    month: 3,
    day: 4,
    approximate: false,
  },
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
}

describe('MarriagePeriodDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('loads structured dates, clears dissolution and saves', async () => {
    const onCancel = vi.fn()
    const onSave = vi.fn(async () => {})
    render(
      <MarriagePeriodDialog
        marriage={marriage}
        onCancel={onCancel}
        onSave={onSave}
      />,
    )
    const dialog = screen.getByRole('dialog', {
      name: 'Edit marriage period',
    })
    const marriageDate = within(dialog).getByRole('group', {
      name: 'Marriage date',
    })
    expect(
      within(marriageDate).getByRole('spinbutton', {
        name: 'Marriage date: Year',
      }),
    ).toHaveValue(2001)
    await userEvent.click(
      within(dialog).getByRole('checkbox', { name: 'Marriage ended' }),
    )
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(onSave).toHaveBeenCalledWith({
      marriageDate: {
        state: 'known',
        precision: 'month',
        year: 2001,
        month: 2,
        approximate: false,
      },
      dissolutionDate: null,
    })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Cancel' }),
    )
    expect(onCancel).toHaveBeenCalledOnce()
  })

  it('keeps invalid input open with a localized validation error', async () => {
    render(
      <MarriagePeriodDialog
        marriage={{
          ...marriage,
          marriageDate: { state: 'unknown' },
          dissolutionDate: null,
        }}
        onCancel={vi.fn()}
        onSave={vi.fn(async () => {})}
      />,
    )
    const dialog = screen.getByRole('dialog', {
      name: 'Edit marriage period',
    })
    const marriageDate = within(dialog).getByRole('group', {
      name: 'Marriage date',
    })
    await userEvent.click(
      within(marriageDate).getByRole('button', { name: 'Unknown' }),
    )
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await within(dialog).findByRole('alert'))
      .toHaveTextContent('Check the entered name and date values')
  })

  it('reports an ETag conflict without closing the dialog', async () => {
    render(
      <MarriagePeriodDialog
        marriage={marriage}
        onCancel={vi.fn()}
        onSave={vi.fn(async () => {
          throw new ApiRequestError(412, {
            code: 'TREE_VERSION_CONFLICT',
            message: 'tree_version_conflict',
            fields: {},
            details: {},
          })
        })}
      />,
    )
    const dialog = screen.getByRole('dialog', {
      name: 'Edit marriage period',
    })
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Save' }),
    )
    expect(await within(dialog).findByRole('alert'))
      .toHaveTextContent('changed in another session')
  })
})
