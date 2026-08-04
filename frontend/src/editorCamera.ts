import type { Viewport } from '@xyflow/react'
import type { TreeEditorView } from './routing'

const STORAGE_PREFIX = 'my-geno-tree:editor-camera:v1'

function storageKey(treeId: string, view: TreeEditorView): string {
  return `${STORAGE_PREFIX}:${treeId}:${view}`
}

function isViewport(value: unknown): value is Viewport {
  if (!value || typeof value !== 'object') {
    return false
  }
  const viewport = value as Partial<Viewport>

  return Number.isFinite(viewport.x)
    && Number.isFinite(viewport.y)
    && Number.isFinite(viewport.zoom)
    && Number(viewport.zoom) > 0
}

export function loadEditorCamera(
  treeId: string,
  view: TreeEditorView,
): Viewport | null {
  try {
    const stored = window.localStorage.getItem(storageKey(treeId, view))
    if (!stored) {
      return null
    }
    const viewport: unknown = JSON.parse(stored)

    return isViewport(viewport) ? viewport : null
  } catch {
    return null
  }
}

export function saveEditorCamera(
  treeId: string,
  view: TreeEditorView,
  viewport: Viewport,
): void {
  if (!isViewport(viewport)) {
    return
  }
  try {
    window.localStorage.setItem(storageKey(treeId, view), JSON.stringify(viewport))
  } catch {
    // Camera persistence is an enhancement; storage restrictions must not break the editor.
  }
}

export function clearEditorCamera(treeId: string, view: TreeEditorView): void {
  try {
    window.localStorage.removeItem(storageKey(treeId, view))
  } catch {
    // Keep the editor usable when browser storage is unavailable.
  }
}
