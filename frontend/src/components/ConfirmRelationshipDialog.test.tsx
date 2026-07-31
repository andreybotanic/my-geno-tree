import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import i18n from '../i18n'
import { ConfirmRelationshipDialog } from './ConfirmRelationshipDialog'

describe('ConfirmRelationshipDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('cancels or confirms an irreversible mutation', async () => {
    const onCancel = vi.fn()
    const onConfirm = vi.fn(async () => {})
    render(
      <ConfirmRelationshipDialog
        description="Permanent change"
        onCancel={onCancel}
        onConfirm={onConfirm}
        title="Delete link?"
      />,
    )

    expect(screen.getByRole('dialog', { name: 'Delete link?' }))
      .toHaveTextContent('Permanent change')
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    await userEvent.click(screen.getByRole('button', { name: 'Remove' }))
    expect(onCancel).toHaveBeenCalledOnce()
    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('keeps the confirmation open when removal fails', async () => {
    render(
      <ConfirmRelationshipDialog
        description="Permanent change"
        onCancel={vi.fn()}
        onConfirm={vi.fn(async () => {
          throw new Error('conflict')
        })}
        title="Delete link?"
      />,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Remove' }))
    expect(await screen.findByRole('alert'))
      .toHaveTextContent('could not be removed')
    expect(screen.getByRole('dialog', { name: 'Delete link?' }))
      .toBeInTheDocument()
  })
})
