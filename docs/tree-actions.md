# Tree actions

## Manual event batches and text-only places

ManualEventService::createMany creates independent events for a list of people
in one transaction. It validates every participant and date before persisting
events, rejects repeated or foreign participants and updates the tree revision
once. Single-person REST creation delegates to this same operation. The core
operation can also support a future participant-selection UI;
no shared-event entity or new UI is introduced.

Person and event place inputs accept coordinates=null alongside a nonempty name.
Existing geocoding responses continue to return resolved coordinates. Text-only
places preserve historical or unresolved names without inventing a map position.

## Single tree actions and confirmations

TreeWriteTools exposes create_person, update_person, delete_person,
add_parent_link, remove_parent_link, create_marriage_period,
update_marriage_period, remove_marriage_period, create_event, update_event,
delete_event, set_event_place and reorder_events to Symfony AI and MCP.
TreeActionService delegates each action to existing application services.
Each successful action commits independently, synchronizing automatic events,
reference changes, ETag and layout invalidation within its own transaction.
A later rejection does not undo prior actions. Physical media cleanup follows commit.

Read the initial ETag once. Every successful mutation returns its own etag and
real resource IDs directly in the tool result; pass that etag as the next ifMatch.
There is no intermediate snapshot/read requirement after an agent's own writes.
All normal ownership, connectivity, date and deletion rules still apply.
A nonempty tree requires create_person to attach the new person through an
initial parent, child or spouse connection. Parent connections create both parent
links atomically. If only one parent is specified, the operation creates an unnamed
second parent in the same transaction. Supply otherParentId to reuse an existing
second parent. The result identifies the requested person separately and returns
all created people with their IDs; update the unnamed person's fields when more
facts become available. Read again when facts are missing,
a version conflict occurs or a mutation response is lost; never replay blindly.

Creating and deleting a person require a separate confirmation for each call.
The website stores the immutable call and its decision with native MessageBag
history in the existing one-hour tmpfs cache. ToolCallRequested intercepts the
call, ToolCallsExecuted finishes the native round, and the chat enters
awaiting_confirmation without holding a database transaction or HTTP request.

POST /api/v1/chats/{chatId}/confirmations/{confirmationId}/decision accepts
the conversation version, decision and optional autoApprovePersonActions.
PATCH /api/v1/chats/{chatId}/permissions changes that mode between executions.
Approval executes exactly the stored arguments and feeds the real tool result
back to the configured Symfony AI agent, continuing the same user request.
An external tree change invalidates the saved ifMatch. Repeated decisions
cannot repeat the mutation. Stop or a new message cancels a pending call.
Pending requests survive reloads and synchronize through private Mercure events.
Decisions remain in server-side conversation metadata without visual history cards.

MCP clients must support form elicitation for person creation/deletion.
Legacy clients receive elicitation/create; modern clients receive input_required
and return their answer with the original call. Signed request state binds the
call arguments and OAuth identity, expires after 120 seconds and is consumed once.
Decline, cancellation, timeout, replay or unsupported elicitation cannot apply
the action. Website automatic permissions never bypass MCP elicitation.

ChatService and ChatPanel remain reusable across profiles. The configured
Symfony AI agent owns inference, progress, token accounting and cancellation.
ChatToolExecutor permits multiple read calls but rejects any round containing
a mutation alongside another call before side effects. Mutations therefore
consume the actual result and ETag of their predecessor.
The client refreshes committed data without switching representation or framing
new people, retaining camera, selection and composer state. Reconnect reloads
the snapshot to recover missed notifications.

## Permissions and atomic parent creation

Permission requests replace the composer with the action text, Allow, Reject,
Clarify and the circular Stop button. A decision or Stop restores the composer;
resolved requests are absent from the message log. Clarify opens the input without
deciding the action; returning to approval preserves the draft, and sending the
clarification cancels the old call. In the input's bottom control row, the permission menu sits on the
left; the token allowance, microphone and Send/Stop buttons sit on the right.
The menu uses icons and short labels: Ask / Auto-approve in English and
Спрашивать / Автоподтверждение in Russian. Tooltips explain each mode. While
recording, the elapsed-time indicator replaces the permission menu in the same
row. The normal circular Stop button handles
both inference and an outstanding permission request; entering a clarification
restores Send.

Parent/child creation persists both parent links together. A missing second parent
is created unnamed, with its own returned UUID. When both parents exist, the call
uses otherParentId and creates only the requested person. Failed validation rolls
back all new people and links. The same behavior applies to website and MCP tools
and structural REST creation.

Symfony AI's supported tools.execution_strategy option configures ChatToolExecutor
in ai.yaml; Kernel contains no custom compiler pass. Canonical place changes now
stay within the existing person and marriage application services; the extra
SourcePlaceService has been removed.

Calls saved before the parent contract changed are cancelled during chat-state
migration. Visible messages are preserved. MCP approval state also binds the
contract version, so an older permission cannot authorize the new behavior.
