import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  clearEditorCameras,
  loadEditorCamerasForReload,
  saveEditorCamerasForReload,
} from './editorCamera'

function mockNavigation(type: PerformanceNavigationTiming['type'], path: string): void {
  vi.spyOn(window.performance, 'getEntriesByType').mockImplementation((entryType) => (
    entryType === 'navigation'
      ? [{
          name: new URL(path, window.location.origin).href,
          type,
        } as PerformanceNavigationTiming]
      : []
  ))
}

describe('editor camera reload storage', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/trees/tree-a/tree')
    window.sessionStorage.clear()
  })

  afterEach(() => vi.restoreAllMocks())

  it('restores both mode cameras during StrictMode initialization after reload', () => {
    mockNavigation('reload', '/trees/tree-a/tree')
    saveEditorCamerasForReload('tree-a', '/trees/tree-a/tree', {
      network: { x: -30, y: 40, zoom: 1.3 },
      tree: { x: 10, y: 20, zoom: 0.8 },
    })

    expect(loadEditorCamerasForReload('tree-a', '/trees/tree-a/tree')).toEqual({
      network: { x: -30, y: 40, zoom: 1.3 },
      tree: { x: 10, y: 20, zoom: 0.8 },
    })
    expect(loadEditorCamerasForReload('tree-a', '/trees/tree-a/tree')).toEqual({
      network: { x: -30, y: 40, zoom: 1.3 },
      tree: { x: 10, y: 20, zoom: 0.8 },
    })
    clearEditorCameras('tree-a')
    expect(loadEditorCamerasForReload('tree-a', '/trees/tree-a/tree')).toEqual({
      network: null,
      tree: null,
    })
  })

  it('discards cameras when the editor is opened by regular navigation', () => {
    mockNavigation('navigate', '/trees/tree-a/tree')
    saveEditorCamerasForReload('tree-a', '/trees/tree-a/tree', {
      network: null,
      tree: { x: 10, y: 20, zoom: 0.8 },
    })

    expect(loadEditorCamerasForReload('tree-a', '/trees/tree-a/tree')).toEqual({
      network: null,
      tree: null,
    })
  })

  it('discards cameras for another URL and when explicitly cleared', () => {
    mockNavigation('reload', '/trees/tree-a/network')
    saveEditorCamerasForReload('tree-a', '/trees/tree-a/tree', {
      network: { x: -30, y: 40, zoom: 1.3 },
      tree: { x: 10, y: 20, zoom: 0.8 },
    })

    expect(loadEditorCamerasForReload('tree-a', '/trees/tree-a/tree')).toEqual({
      network: null,
      tree: null,
    })

    saveEditorCamerasForReload('tree-a', '/trees/tree-a/tree', {
      network: null,
      tree: { x: 10, y: 20, zoom: 0.8 },
    })
    clearEditorCameras('tree-a')
    expect(loadEditorCamerasForReload('tree-a', '/trees/tree-a/tree')).toEqual({
      network: null,
      tree: null,
    })
  })
})
