import { act, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import i18n from '../i18n'
import { TreeMergeThinking } from './TreeMergeThinking'

afterEach(() => vi.useRealTimers())

it('shows elapsed time and only completed server stages in both interface languages', async () => {
  await i18n.changeLanguage('ru')
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-11-04T00:00:00Z'))
  const view = render(<TreeMergeThinking startedAt={Date.now()} progress={['tree_loaded']} />)
  expect(screen.getByRole('status')).toHaveTextContent('Думаю...')
  expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('0:00')
  expect(screen.getByText('Контекст дерева получен')).toBeInTheDocument()
  expect(screen.queryByText('Поиск по именам выполнен')).toBeNull()
  act(() => vi.advanceTimersByTime(65000))
  expect(screen.getByLabelText('Затраченное время')).toHaveTextContent('1:05')
  await act(async () => i18n.changeLanguage('en'))
  view.rerender(<TreeMergeThinking startedAt={Date.now() - 65000} progress={['tree_loaded', 'repairing']} />)
  expect(screen.getByRole('status')).toHaveTextContent('Thinking...')
  expect(screen.getByText('Correcting the proposal after validation')).toBeInTheDocument()
})
