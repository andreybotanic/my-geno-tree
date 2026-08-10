import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import i18n from '../i18n'
import { emptyMarriageForm } from '../marriageFormState'

vi.mock('./PlaceDialog', () => ({
  PlaceDialog: ({
    onCancel,
    onDelete,
    onSave,
    place,
  }: {
    onCancel: () => void
    onDelete?: () => void
    onSave: (place: {
      name: string
      coordinates: { latitude: number, longitude: number }
    }) => void
    place: { name: string } | null
  }) => (
    <div aria-label="Place dialog">
      <span>{place?.name ?? 'New place'}</span>
      <button onClick={onCancel}>Cancel place</button>
      {onDelete && <button onClick={onDelete}>Delete place</button>}
      <button onClick={() => onSave({
        name: 'Selected place',
        coordinates: { latitude: 55, longitude: 83 },
      })}>
        Save place
      </button>
    </div>
  ),
  PlaceTextButton: ({
    onClick,
    place,
  }: {
    onClick: () => void
    place: { name: string } | null
  }) => <button onClick={onClick}>{place?.name ?? 'Add place'}</button>,
}))

import { MarriageFormFields } from './MarriageFormFields'

describe('MarriageFormFields', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('saves a selected marriage place and can cancel without changing it', async () => {
    const onChange = vi.fn()
    render(
      <MarriageFormFields
        onChange={onChange}
        state={emptyMarriageForm()}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Add place' }))
    await userEvent.click(screen.getByRole('button', { name: 'Cancel place' }))
    expect(onChange).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'Add place' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save place' }))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({
      marriagePlace: {
        name: 'Selected place',
        coordinates: { latitude: 55, longitude: 83 },
      },
    }))
  })

  it('edits and deletes the dissolution place independently', async () => {
    const onChange = vi.fn()
    const state = {
      ...emptyMarriageForm(),
      dissolved: true,
      dissolutionPlace: {
        name: 'Old place',
        coordinates: { latitude: 54, longitude: 82 },
      },
    }
    render(<MarriageFormFields onChange={onChange} state={state} />)

    await userEvent.click(screen.getByRole('button', { name: 'Old place' }))
    expect(screen.getByLabelText('Place dialog')).toHaveTextContent('Old place')
    await userEvent.click(screen.getByRole('button', { name: 'Delete place' }))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({
      dissolutionPlace: null,
      marriagePlace: null,
    }))
  })
})
