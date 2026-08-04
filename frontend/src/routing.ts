import { useEffect, useState } from 'react'

export type TreeEditorView = 'network' | 'tree'

export function treeEditorPath(treeId: string, view: TreeEditorView): string {
  return `/trees/${treeId}/${view}`
}

export function matchTreeEditorPath(path: string): {
  treeId: string
  view: TreeEditorView | null
} | null {
  const match = path.match(/^\/trees\/([^/]+)(?:\/(network|tree))?$/)
  if (!match?.[1]) {
    return null
  }

  return {
    treeId: match[1],
    view: (match[2] as TreeEditorView | undefined) ?? null,
  }
}

export function navigate(path: string, options: { replace?: boolean } = {}): void {
  if (window.location.pathname === path) {
    return
  }
  if (options.replace) {
    window.history.replaceState({}, '', path)
  } else {
    window.history.pushState({}, '', path)
  }
  window.dispatchEvent(new PopStateEvent('popstate'))
}

export function usePath(): string {
  const [path, setPath] = useState(window.location.pathname)

  useEffect(() => {
    const update = () => setPath(window.location.pathname)
    window.addEventListener('popstate', update)

    return () => window.removeEventListener('popstate', update)
  }, [])

  return path
}
