import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import type { TreeMergeDraft, TreeMergeResult, TreeSnapshot } from '../api/generated'
import { TreeMergePanel } from './TreeMergePanel'

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onApplied: (result: TreeMergeResult) => Promise<void>
  onRefreshTree?: () => Promise<void>
  onDraftChange?: (draft: TreeMergeDraft | null) => void
  previewContainer?: HTMLElement | null
}

export function TreeMergeDrawer({ open, onOpenChange, ...panel }: Props) {
  const { t } = useTranslation()
  const [visited, setVisited] = useState(open)
  const tab = useRef<HTMLButtonElement>(null)

  function toggle(next: boolean) {
    setVisited(true)
    onOpenChange(next)
    if (!next) {
      tab.current?.focus({ preventScroll: true })
      const url = new URL(window.location.href)
      url.searchParams.delete('merge')
      window.history.replaceState(window.history.state, '', url)
    }
  }

  return <div className="tree-merge-overlay"><div className={`tree-merge-drawer${open ? ' is-open' : ''}`}>
    <button ref={tab} type="button" className="tree-merge-tab" aria-expanded={open} aria-controls="tree-merge-chat"
      aria-label={t(open ? 'treeMerge.close' : 'treeMerge.action')} onClick={() => toggle(!open)}>
      <svg viewBox="0 0 24 24" width="24" height="24" fill="none" aria-hidden="true">
        <path d="m11 3 2.3 6.7L20 12l-6.7 2.3L11 21l-2.3-6.7L2 12l6.7-2.3L11 3Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
        <path d="m20 2 .8 2.2L23 5l-2.2.8L20 8l-.8-2.2L17 5l2.2-.8L20 2Z" fill="currentColor" />
      </svg>
      <span aria-hidden="true">AI</span>
    </button>
    <div id="tree-merge-chat" className="tree-merge-drawer-body" inert={!open} aria-hidden={!open}>
      {(visited || open) && <TreeMergePanel {...panel} open={open} onClose={() => toggle(false)} />}
    </div>
  </div></div>
}
