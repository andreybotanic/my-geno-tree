import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../i18n'
import { PersonSearchPanel } from './PersonSearchPanel'

describe('PersonSearchPanel', () => {
  beforeEach(async () => {
    vi.useFakeTimers()
    await i18n.changeLanguage('en')
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('expands, debounces a request, supports the keyboard and hides technical ids', async () => {
    const onSelect = vi.fn()
    const onSearch = vi.fn(async () => [{
      id: 'technical-id',
      fullName: 'Ivanov Ivan',
      birthYear: 1900,
      deathYear: 1980,
    }])
    render(<PersonSearchPanel onSearch={onSearch} onSelect={onSelect} />)

    fireEvent.click(screen.getByRole('button', { name: 'Search people' }))
    const input = screen.getByRole('combobox', { name: 'Search by full name' })
    expect(input).toHaveFocus()
    fireEvent.change(input, { target: { value: 'Ivan' } })
    await act(async () => vi.advanceTimersByTimeAsync(250))

    expect(onSearch).toHaveBeenCalledWith('Ivan', expect.any(AbortSignal))
    expect(screen.getByRole('option', { name: /Ivanov Ivan/ })).toHaveTextContent('1900 — 1980')
    expect(screen.queryByText('technical-id')).not.toBeInTheDocument()
    fireEvent.keyDown(input, { key: 'ArrowDown' })
    fireEvent.keyDown(input, { key: 'ArrowUp' })
    fireEvent.keyDown(input, { key: 'Enter' })
    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 'technical-id' }))
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('cancels an obsolete request and closes with Escape', async () => {
    let aborted = false
    const onSearch = vi.fn((_query: string, signal: AbortSignal) => new Promise<never>((_resolve, reject) => {
      signal.addEventListener('abort', () => {
        aborted = true
        reject(new DOMException('Aborted', 'AbortError'))
      })
    }))
    render(<PersonSearchPanel onSearch={onSearch} onSelect={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Search people' }))
    const input = screen.getByRole('combobox')
    fireEvent.change(input, { target: { value: 'Ivan' } })
    await act(async () => vi.advanceTimersByTimeAsync(250))
    fireEvent.change(input, { target: { value: 'Ivanov' } })
    expect(aborted).toBe(true)
    fireEvent.keyDown(input, { key: 'Escape' })
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('shows distinct empty and failed states', async () => {
    const onSearch = vi.fn(async (query: string) => {
      if (query === 'Missing') return []
      throw new Error('Network error')
    })
    render(<PersonSearchPanel onSearch={onSearch} onSelect={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Search people' }))
    const input = screen.getByRole('combobox')
    fireEvent.change(input, { target: { value: 'Missing' } })
    await act(async () => vi.advanceTimersByTimeAsync(250))
    expect(screen.getByText('No people found.')).toBeInTheDocument()

    fireEvent.change(input, { target: { value: 'Failure' } })
    await act(async () => vi.advanceTimersByTimeAsync(250))
    expect(screen.getByRole('alert')).toHaveTextContent('Search could not be completed.')
  })
})
