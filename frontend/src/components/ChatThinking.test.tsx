import { act, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import i18n from '../i18n'
import { ChatThinking } from './ChatThinking'

afterEach(() => vi.useRealTimers())

it(
  'shows current commentary as escaped text with a localized fallback and elapsed time',
  async () => {
    await i18n.changeLanguage('ru')
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-04T00:00:00Z'))
    const view = render(<ChatThinking startedAt={Date.now()} commentary={null} />)
    expect(screen.getByRole('status')).toHaveTextContent('Думаю...')
    expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('0:00')
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
    act(() => vi.advanceTimersByTime(65000))
    expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('1:05')
    view.rerender(<ChatThinking startedAt={Date.now() - 65000} commentary="Проверяю родственников." />)
    expect(screen.getByRole('status')).toHaveTextContent('Проверяю родственников.')
    view.rerender(<ChatThinking startedAt={Date.now()} commentary="<img src=x onerror=alert(1)>" />)
    expect(screen.getByRole('status')).toHaveTextContent('<img src=x onerror=alert(1)>')
    expect(view.container.querySelector('img')).toBeNull()
    await act(async () => i18n.changeLanguage('en'))
    view.rerender(<ChatThinking startedAt={Date.now() - 65000} commentary="Checking the family." />)
    expect(screen.getByRole('status')).toHaveTextContent('Checking the family.')
    expect(screen.getByLabelText('Elapsed time')).toHaveTextContent('1:05')
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
    view.rerender(<ChatThinking startedAt={Date.now()} commentary={null} />)
    expect(screen.getByRole('status')).toHaveTextContent('Thinking...')
  }
)
