import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import i18n from '../i18n'
import { AddButton } from './AddButton'

describe('AddButton', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('uses the shared label and a decorative plus icon', async () => {
    const onClick = vi.fn()
    render(<AddButton onClick={onClick} />)

    const button = screen.getByRole('button', { name: 'Add' })
    expect(button.querySelector('.add-button-icon')).toHaveAttribute(
      'aria-hidden',
      'true',
    )
    expect(button.querySelector('path')).toHaveAttribute(
      'd',
      'M12 4.5v15M4.5 12h15',
    )
    expect(button.querySelector('svg')).toHaveAttribute('stroke-width', '3')

    await userEvent.click(button)
    expect(onClick).toHaveBeenCalledOnce()
  })
})
