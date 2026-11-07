import { act, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import i18n from '../i18n'
import { ChatThinking } from './ChatThinking'

afterEach(() => vi.useRealTimers())

it(
  'shows only the thinking line and elapsed time in both interface languages',
  async () => {
    await i18n.changeLanguage('ru')
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-04T00:00:00Z'))
    const view = render(<ChatThinking startedAt={Date.now()} />)
    expect(screen.getByRole('status')).toHaveTextContent('Думаю...')
    expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('0:00')
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
    act(() => vi.advanceTimersByTime(65000))
    expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('1:05')
    await act(async () => i18n.changeLanguage('en'))
    view.rerender(<ChatThinking startedAt={Date.now() - 65000} />)
    expect(screen.getByRole('status')).toHaveTextContent('Thinking...')
    expect(screen.getByLabelText('Elapsed time')).toHaveTextContent('1:05')
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
  }
)
