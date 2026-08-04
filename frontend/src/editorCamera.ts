import type { Viewport } from '@xyflow/react'
import type { TreeEditorView } from './routing'

const STORAGE_PREFIX = 'my-geno-tree:editor-camera-reload:v1'
const HISTORY_STATE_KEY = '__myGenoTreeEditorCameraReload'
const loadedCameras = new Map<string, EditorCameras>()

export type EditorCameras = Record<TreeEditorView, Viewport | null>

type ReloadMarker = {
  path: string
  treeId: string
}

type StoredEditorCameras = ReloadMarker & {
  cameras: EditorCameras
}

function emptyCameras(): EditorCameras {
  return { network: null, tree: null }
}

function storageKey(treeId: string): string {
  return `${STORAGE_PREFIX}:${treeId}`
}

function loadKey(treeId: string, path: string): string {
  return `${treeId}:${path}`
}

function cloneCameras(cameras: EditorCameras): EditorCameras {
  return {
    network: cameras.network ? { ...cameras.network } : null,
    tree: cameras.tree ? { ...cameras.tree } : null,
  }
}

function cacheLoadedCameras(
  treeId: string,
  path: string,
  cameras: EditorCameras,
): EditorCameras {
  loadedCameras.set(loadKey(treeId, path), cloneCameras(cameras))

  return cloneCameras(cameras)
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

function isReloadMarker(value: unknown): value is ReloadMarker {
  if (!value || typeof value !== 'object') {
    return false
  }
  const marker = value as Partial<ReloadMarker>

  return typeof marker.path === 'string' && typeof marker.treeId === 'string'
}

function isStoredEditorCameras(value: unknown): value is StoredEditorCameras {
  if (!isReloadMarker(value)) {
    return false
  }
  const cameras = (value as Partial<StoredEditorCameras>).cameras

  return Boolean(cameras)
    && (cameras?.network === null || isViewport(cameras?.network))
    && (cameras?.tree === null || isViewport(cameras?.tree))
}

function currentHistoryState(): Record<string, unknown> {
  const state: unknown = window.history.state

  return state && typeof state === 'object'
    ? { ...(state as Record<string, unknown>) }
    : {}
}

function consumeReloadMarker(): ReloadMarker | null {
  const state = currentHistoryState()
  const marker = state[HISTORY_STATE_KEY]
  delete state[HISTORY_STATE_KEY]
  window.history.replaceState(state, '', window.location.href)

  return isReloadMarker(marker) ? marker : null
}

function isReloadOfPath(path: string): boolean {
  const navigation = window.performance.getEntriesByType('navigation')[0] as
    | Partial<PerformanceNavigationTiming>
    | undefined
  if (navigation?.type !== 'reload' || !navigation.name) {
    return false
  }
  try {
    return new URL(navigation.name, window.location.origin).pathname === path
  } catch {
    return false
  }
}

export function loadEditorCamerasForReload(
  treeId: string,
  path: string,
): EditorCameras {
  const cached = loadedCameras.get(loadKey(treeId, path))
  if (cached) {
    return cloneCameras(cached)
  }
  const marker = consumeReloadMarker()
  let stored: string | null
  try {
    stored = window.sessionStorage.getItem(storageKey(treeId))
    window.sessionStorage.removeItem(storageKey(treeId))
  } catch {
    return cacheLoadedCameras(treeId, path, emptyCameras())
  }
  if (
    !marker
    || marker.treeId !== treeId
    || marker.path !== path
    || !isReloadOfPath(path)
    || !stored
  ) {
    return cacheLoadedCameras(treeId, path, emptyCameras())
  }
  try {
    const value: unknown = JSON.parse(stored)
    if (!isStoredEditorCameras(value) || value.treeId !== treeId || value.path !== path) {
      return cacheLoadedCameras(treeId, path, emptyCameras())
    }

    return cacheLoadedCameras(treeId, path, {
      network: value.cameras.network ? { ...value.cameras.network } : null,
      tree: value.cameras.tree ? { ...value.cameras.tree } : null,
    })
  } catch {
    return cacheLoadedCameras(treeId, path, emptyCameras())
  }
}

export function saveEditorCamerasForReload(
  treeId: string,
  path: string,
  cameras: EditorCameras,
): void {
  loadedCameras.delete(loadKey(treeId, path))
  const value: StoredEditorCameras = {
    cameras: {
      network: cameras.network && isViewport(cameras.network)
        ? { ...cameras.network }
        : null,
      tree: cameras.tree && isViewport(cameras.tree) ? { ...cameras.tree } : null,
    },
    path,
    treeId,
  }
  try {
    window.sessionStorage.setItem(storageKey(treeId), JSON.stringify(value))
    window.history.replaceState({
      ...currentHistoryState(),
      [HISTORY_STATE_KEY]: { path, treeId } satisfies ReloadMarker,
    }, '', window.location.href)
  } catch {
    // Camera persistence is an enhancement; storage restrictions must not break the editor.
  }
}

export function clearEditorCameras(treeId: string): void {
  for (const key of loadedCameras.keys()) {
    if (key.startsWith(`${treeId}:`)) {
      loadedCameras.delete(key)
    }
  }
  try {
    window.sessionStorage.removeItem(storageKey(treeId))
  } catch {
    // Keep the editor usable when browser storage is unavailable.
  }
  const state = currentHistoryState()
  const marker = state[HISTORY_STATE_KEY]
  if (isReloadMarker(marker) && marker.treeId === treeId) {
    delete state[HISTORY_STATE_KEY]
    window.history.replaceState(state, '', window.location.href)
  }
}
