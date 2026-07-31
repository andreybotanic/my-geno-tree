import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import App from './App'

describe('App', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('{"status":"ok"}', { status: 200 })),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the product shell and successful API status', async () => {
    render(<App />)

    expect(
      screen.getByRole('heading', {
        name: 'Keep every family story connected.',
      }),
    ).toBeInTheDocument()

    await waitFor(() => {
      expect(screen.getByText('Local API connected')).toBeInTheDocument()
    })
  })

  it('shows an unavailable state for an unsuccessful API response', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(null, { status: 503 }))

    render(<App />)

    await waitFor(() => {
      expect(screen.getByText('Local API is unavailable')).toBeInTheDocument()
    })
  })

  it('shows an unavailable state for a network error', async () => {
    vi.mocked(fetch).mockRejectedValue(new TypeError('Network unavailable'))

    render(<App />)

    await waitFor(() => {
      expect(screen.getByText('Local API is unavailable')).toBeInTheDocument()
    })
  })

  it('keeps checking when the request is intentionally aborted', async () => {
    vi.mocked(fetch).mockRejectedValue(
      new DOMException('Request aborted', 'AbortError'),
    )

    render(<App />)

    await waitFor(() => {
      expect(screen.getByText('Checking the local API…')).toBeInTheDocument()
    })
  })
})
