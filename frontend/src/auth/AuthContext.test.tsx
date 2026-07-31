import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useAuth } from './AuthContext'

describe('useAuth', () => {
  it('requires the provider boundary', () => {
    expect(() => renderHook(useAuth)).toThrow(
      'useAuth must be used inside AuthProvider',
    )
  })
})
