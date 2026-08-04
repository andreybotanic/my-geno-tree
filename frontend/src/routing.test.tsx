import { beforeEach, describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import {
  matchTreeEditorPath,
  navigate,
  treeEditorPath,
  usePath,
} from './routing'

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

  it('builds and recognizes mode-specific tree editor paths', () => {
    expect(treeEditorPath('tree-id', 'tree')).toBe('/trees/tree-id/tree')
    expect(treeEditorPath('tree-id', 'network')).toBe('/trees/tree-id/network')
    expect(matchTreeEditorPath('/trees/tree-id/network')).toEqual({
      treeId: 'tree-id',
      view: 'network',
    })
  })

  it('recognizes the legacy editor path without assigning a mode', () => {
    expect(matchTreeEditorPath('/trees/tree-id')).toEqual({
      treeId: 'tree-id',
      view: null,
    })
    expect(matchTreeEditorPath('/trees/tree-id/unknown')).toBeNull()
  })
})
