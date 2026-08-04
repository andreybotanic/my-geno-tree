import { beforeEach, describe, expect, it } from 'vitest'
import {
  clearEditorCamera,
  loadEditorCamera,
  saveEditorCamera,
} from './editorCamera'

describe('editor camera storage', () => {
  beforeEach(() => window.localStorage.clear())

  it('persists independent tree and network cameras for each tree', () => {
    saveEditorCamera('tree-a', 'tree', { x: 10, y: 20, zoom: 0.8 })
    saveEditorCamera('tree-a', 'network', { x: -30, y: 40, zoom: 1.3 })
    saveEditorCamera('tree-b', 'tree', { x: 50, y: -60, zoom: 1.1 })

    expect(loadEditorCamera('tree-a', 'tree')).toEqual({ x: 10, y: 20, zoom: 0.8 })
    expect(loadEditorCamera('tree-a', 'network')).toEqual({ x: -30, y: 40, zoom: 1.3 })
    expect(loadEditorCamera('tree-b', 'tree')).toEqual({ x: 50, y: -60, zoom: 1.1 })
  })

  it('ignores malformed data and clears one mode without touching the other', () => {
    window.localStorage.setItem(
      'my-geno-tree:editor-camera:v1:tree-a:tree',
      JSON.stringify({ x: 0, y: 0, zoom: 0 }),
    )
    saveEditorCamera('tree-a', 'network', { x: 1, y: 2, zoom: 1 })

    expect(loadEditorCamera('tree-a', 'tree')).toBeNull()
    clearEditorCamera('tree-a', 'tree')
    expect(loadEditorCamera('tree-a', 'network')).toEqual({ x: 1, y: 2, zoom: 1 })
  })
})
