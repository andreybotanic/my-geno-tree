import type { ChatConversation, ToolConfirmation, TreeSnapshot } from '../api/generated'

export const conversation: ChatConversation = {
  id: 'chat', profile: 'tree', version: 1, status: 'idle', tools: {}, confirmations: [], autoApprovePersonActions: false,
  attemptId: null, attemptStartedAt: null, messages: [], commentary: null, progressSequence: 0,
  tokenUsage: { input: 0, output: 0, total: 0 }, tokenBudget: { remaining: 1000, limit: 1000 },
  access: { status: 'allowed', warnings: 0, warningLimit: 3, banCount: 0,
    bannedUntil: null, unavailableUntil: null, serverTime: '2026-11-05T10:00:00Z' },
  createdAt: '2026-11-05T10:00:00Z', updatedAt: '2026-11-05T10:00:00Z',
}
export const confirmation: ToolConfirmation = {
  id: 'confirmation', requestId: 'request', tool: 'create_person', status: 'pending',
  summary: { fields: { givenName: { state: 'known', value: 'Vera' }, gender: 'female' } },
  result: null, createdAt: conversation.createdAt,
}
export const readyConversation: ChatConversation = {
  ...conversation, status: 'awaiting_confirmation', confirmations: [confirmation],
  messages: [{ id: 'user', requestId: 'request', role: 'user', content: 'Add Vera.', createdAt: conversation.createdAt }],
}
export const emptySnapshot: TreeSnapshot = {
  tree: { id: 'tree', name: 'Family', reference: null, createdAt: 'now', updatedAt: 'now' },
  people: [], parentLinks: [], marriages: [], etag: 'etag', layoutRevision: 'layout', layout: null, bloodNetworkState: null,
}
