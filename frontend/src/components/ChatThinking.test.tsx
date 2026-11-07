import { act, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import i18n from '../i18n'
import { ChatThinking } from './ChatThinking'

afterEach(() => vi.useRealTimers())

it(
  'shows the latest completed tool name with a localized prefix and elapsed time',
  async () => {
    await i18n.changeLanguage('ru')
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-04T00:00:00Z'))
    const view = render(<ChatThinking startedAt={Date.now()} progress={[]} />)
    expect(screen.getByRole('status')).toHaveTextContent('Думаю...')
    expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('0:00')
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
    act(() => vi.advanceTimersByTime(65000))
    expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('1:05')
    view.rerender(<ChatThinking startedAt={Date.now() - 65000} progress={['get_tree_context', 'find_people']} />)
    expect(screen.getByRole('status')).toHaveTextContent('Выполнено: find_people')
    expect(screen.queryByText('Выполнено: get_tree_context')).not.toBeInTheDocument()
    await act(async () => i18n.changeLanguage('en'))
    view.rerender(<ChatThinking startedAt={Date.now() - 65000} progress={['get_tree_context', 'find_people', 'get_tree_context']} />)
    expect(screen.getByRole('status')).toHaveTextContent('Completed: get_tree_context')
    expect(screen.queryByText('Completed: find_people')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Elapsed time')).toHaveTextContent('1:05')
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
    view.rerender(<ChatThinking startedAt={Date.now()} progress={['model_request']} />)
    expect(screen.getByRole('status')).toHaveTextContent('Thinking...')
    view.rerender(<ChatThinking startedAt={Date.now()} progress={['custom_tool']} />)
    expect(screen.getByRole('status')).toHaveTextContent('Completed: custom_tool')
  }
)
