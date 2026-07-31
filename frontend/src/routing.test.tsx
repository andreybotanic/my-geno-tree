import { beforeEach, describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { navigate, usePath } from './routing'

describe('client routing', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/')
  })

  it('navigates and updates subscribed views', () => {
    const { result } = renderHook(usePath)

    act(() => navigate('/profile'))

    expect(result.current).toBe('/profile')
    expect(window.location.pathname).toBe('/profile')
  })

  it('does not add another history entry for the current path', () => {
    const length = window.history.length

    navigate('/')

    expect(window.history.length).toBe(length)
  })
})
