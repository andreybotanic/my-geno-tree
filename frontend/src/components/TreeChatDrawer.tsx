import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { AuthorizedJsonRequest } from '../api/client'
import type { TreeMergePendingProposal, TreeMergeResult, TreeSnapshot } from '../api/generated'
import { TreeChatPanel } from './TreeChatPanel'

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  snapshot: TreeSnapshot
  request: AuthorizedJsonRequest
  onApplied: (result: TreeMergeResult) => Promise<void>
  onRefreshTree?: () => Promise<void>
  onProposalChange?: (draft: TreeMergePendingProposal | null) => void
  previewContainer?: HTMLElement | null
}

export function TreeChatDrawer({ open, onOpenChange, ...panel }: Props) {
  const { t } = useTranslation()
  const [visited, setVisited] = useState(open)
  const tab = useRef<HTMLButtonElement>(null)

  function toggle(next: boolean) {
    setVisited(true)
    onOpenChange(next)
    if (!next) {
      tab.current?.focus({ preventScroll: true })
      const url = new URL(window.location.href)
      url.searchParams.delete('chat')
      window.history.replaceState(window.history.state, '', url)
    }
  }

  return <div className="tree-chat-overlay"><div className={`tree-chat-drawer${open ? ' is-open' : ''}`}>
    <button ref={tab} type="button" className="tree-chat-tab" aria-expanded={open} aria-controls="tree-chat"
      aria-label={t(open ? 'chat.close' : 'chat.action')} title={t(open ? 'chat.close' : 'chat.action')}
      onClick={() => toggle(!open)}>
      <svg viewBox="0 0 24 24" width="24" height="24" fill="none" aria-hidden="true">
        <path d="m11 3 2.3 6.7L20 12l-6.7 2.3L11 21l-2.3-6.7L2 12l6.7-2.3L11 3Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
        <path d="m20 2 .8 2.2L23 5l-2.2.8L20 8l-.8-2.2L17 5l2.2-.8L20 2Z" fill="currentColor" />
      </svg>
    </button>
    <div id="tree-chat" className="tree-chat-drawer-body" inert={!open} aria-hidden={!open}>
      {(visited || open) && <TreeChatPanel {...panel} open={open} onClose={() => toggle(false)} />}
    </div>
  </div></div>
}
