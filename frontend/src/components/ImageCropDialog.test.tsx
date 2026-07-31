import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { ImageCropDialog } from './ImageCropDialog'

vi.mock('react-easy-crop', () => ({
  default: ({
    onCropComplete,
  }: {
    onCropComplete: (
      percentage: { x: number; y: number; width: number; height: number },
      pixels: { x: number; y: number; width: number; height: number },
    ) => void
  }) => (
    <button
      onClick={() => onCropComplete(
        { x: 0, y: 0, width: 75, height: 75 },
        { x: 10, y: 20, width: 90, height: 120 },
      )}
      type="button"
    >
      Apply crop
    </button>
  ),
}))

describe('ImageCropDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('submits the chosen file and pixel crop', async () => {
    const onSave = vi.fn(async () => {})
    render(
      <ImageCropDialog
        aspect={3 / 4}
        onCancel={vi.fn()}
        onSave={onSave}
        title="Photo crop"
      />,
    )
    const file = new File(['image'], 'portrait.png', { type: 'image/png' })
    await userEvent.upload(screen.getByLabelText(/JPEG, PNG or WebP/), file)
    await userEvent.click(screen.getByRole('button', { name: 'Apply crop' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith(file, {
      x: 10,
      y: 20,
      width: 90,
      height: 120,
    })
  })

  it('maps server validation errors and cancels', async () => {
    const onCancel = vi.fn()
    const onSave = vi.fn(async () => {
      throw new ApiRequestError(415, {
        code: 'IMAGE_TYPE_UNSUPPORTED',
        message: 'image_type_unsupported',
        fields: {},
        details: {},
      })
    })
    render(
      <ImageCropDialog
        aspect={1}
        cropShape="round"
        onCancel={onCancel}
        onSave={onSave}
        title="Avatar crop"
      />,
    )
    await userEvent.upload(
      screen.getByLabelText(/JPEG, PNG or WebP/),
      new File(['image'], 'avatar.png', { type: 'image/png' }),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Apply crop' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Use a non-animated JPEG, PNG or WebP image.',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onCancel).toHaveBeenCalledOnce()
  })
})
