import type { ChatConversation, TreeMergePendingProposal, TreeSnapshot } from '../api/generated'

export const conversation: ChatConversation = {
  id: 'chat', profile: 'tree', version: 1, status: 'idle', tools: {},
  attemptId: null, attemptStartedAt: null, messages: [], progress: [],
  tokenUsage: { input: 0, output: 0, total: 0 }, tokenBudget: { remaining: 1000, limit: 1000 },
  createdAt: '2026-11-05T10:00:00Z', updatedAt: '2026-11-05T10:00:00Z',
}
export const mergeProposal: TreeMergePendingProposal = {
  id: 'proposal', treeId: 'tree', version: 1, state: 'ready', treeEtag: 'etag',
  proposal: { people: [
      { id: 'v', existingPersonId: null, resolution: 'new', lifeStatus: 'alive',
        fields: { givenName: { state: 'known', value: 'Vera' }, gender: 'female' }, evidence: [] }
    ],
    parents: [], marriages: [], questions: [] },
}
export const readyConversation: ChatConversation = { ...conversation, tools: { merge: mergeProposal } }
export const emptySnapshot: TreeSnapshot = {
  tree: { id: 'tree', name: 'Family', reference: null, createdAt: 'now', updatedAt: 'now' },
  people: [], parentLinks: [], marriages: [], etag: 'etag', layoutRevision: 'layout', layout: null, bloodNetworkState: null,
}
