import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ToastNotice } from './ToastNotice'

describe('ToastNotice', () => {
  afterEach(() => vi.useRealTimers())

  it('can be closed manually', async () => {
    const onClose = vi.fn()
    render(
      <ToastNotice closeLabel="Close notification" message="Saved" onClose={onClose} timeoutMs={100_000} />,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Close notification' }))
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('closes itself after its timeout', () => {
    const onClose = vi.fn()
    vi.useFakeTimers()
    render(
      <ToastNotice closeLabel="Close notification" message="Saved" onClose={onClose} timeoutMs={20} />,
    )
    act(() => vi.advanceTimersByTime(20))
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
