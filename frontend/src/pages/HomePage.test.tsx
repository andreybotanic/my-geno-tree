import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, within } from '@testing-library/react'
import i18n from '../i18n'
import { HomePage } from './HomePage'

describe('HomePage statistics', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('keeps placeholders while loading and renders zero as a real count', async () => {
    let resolve!: (response: Response) => void
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((done) => { resolve = done })))
    render(<HomePage />)

    expect(screen.getAllByText('—')).toHaveLength(3)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    await act(async () => {
      resolve(Response.json({ accountCount: 0, treeCount: 0, personCount: 0 }))
    })

    expect(screen.getAllByText('0')).toHaveLength(3)
    expect(screen.queryByText('—')).not.toBeInTheDocument()
  })

  it('localizes the global person counter in Russian', async () => {
    await i18n.changeLanguage('ru')
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({
      accountCount: 2, treeCount: 4, personCount: 1234,
    })))
    render(<HomePage />)

    const statistics = screen.getByRole('region', { name: 'Текущая статистика MyGenoTree' })
    expect(await within(statistics).findByText('1234')).toBeInTheDocument()
    expect(within(statistics).getByText('Общее число персон')).toBeInTheDocument()
  })

  it.each(['http', 'network'])('shows unavailable statistics on %s errors', async (failure) => {
    vi.stubGlobal('fetch', failure === 'http'
      ? vi.fn().mockResolvedValue(new Response(null, { status: 503 }))
      : vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    render(<HomePage />)

    expect(await screen.findByRole('alert')).toHaveTextContent('Local API is unavailable')
    expect(screen.getAllByText('—')).toHaveLength(3)
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })
})
