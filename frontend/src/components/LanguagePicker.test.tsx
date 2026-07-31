import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { LanguagePicker } from './LanguagePicker'

describe('LanguagePicker', () => {
  it('shows real SVG flags for both language choices', async () => {
    const onChange = vi.fn()
    const { container } = render(
      <LanguagePicker
        label="Language"
        onChange={onChange}
        value="en"
      />,
    )

    expect(screen.getByTestId('language-flag-en').tagName).toBe('svg')
    expect(container.textContent).not.toContain('🇺🇸')

    await userEvent.click(screen.getByRole('button', { name: 'Language' }))

    expect(screen.getAllByTestId('language-flag-en')).toHaveLength(2)
    expect(screen.getByTestId('language-flag-ru').tagName).toBe('svg')
    expect(container.textContent).not.toContain('🇷🇺')

    await userEvent.click(screen.getByRole('option', { name: 'Русский' }))

    expect(onChange).toHaveBeenCalledWith('ru')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })
})
