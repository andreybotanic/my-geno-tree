import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import { createPortal } from 'react-dom'
import {
  Background,
  Panel,
  ReactFlow,
  ReactFlowProvider,
  useReactFlow,
  useViewport,
  type ReactFlowInstance,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalEvent,
  GraphMutation,
  ManualEventRequest,
  MarriagePeriod,
  MarriagePeriodPatchRequest,
  ParentLink,
  ParentRole,
  ParentSpecification,
  Person,
  PersonInput,
  PersonPatchRequest,
  RelativePerson,
  TreeSnapshot,
} from '../api/generated'
import {
  ApiRequestError,
  eventApi,
  marriageApi,
  personPhotoApi,
  personApi,
  treeApi,
} from '../api/client'
import { useAuth } from '../auth/AuthContext'
import {
  PersonCard,
  type PersonFlowNode,
} from '../components/PersonCard'
import { PersonDetailsPanel } from '../components/PersonDetailsPanel'
import { PersonFormDialog } from '../components/PersonFormDialog'
import {
  RelativeDialog,
  type RelativeAction,
  type RelativeOptions,
} from '../components/RelativeDialog'
import {
  FamilyEdge,
  SingleParentEdge,
} from '../components/TreeEdges'
import { ConfirmRelationshipDialog } from '../components/ConfirmRelationshipDialog'
import { MarriagePeriodDialog } from '../components/MarriagePeriodDialog'
import { EventDialog } from '../components/EventDialog'
import { ImageCropDialog } from '../components/ImageCropDialog'
import { PhotoViewerDialog } from '../components/PhotoViewerDialog'
import { navigate } from '../routing'
import {
  buildTreeEdges,
  newRelativeCoordinates,
  PERSON_CARD_HEIGHT,
  PERSON_CARD_WIDTH,
  reflowTree,
  type TreeFlowEdge,
} from '../treeGraph'

type TreeEditorPageProps = {
  treeId: string
}

const nodeTypes = { person: PersonCard }
const edgeTypes = {
  family: FamilyEdge,
  singleParent: SingleParentEdge,
}
const PREVIEW_PERSON_ID = '__new_person__'

function ZoomControls() {
  const { t } = useTranslation()
  const { zoomIn, zoomOut } = useReactFlow()
  const { zoom } = useViewport()

  return (
    <Panel className="zoom-controls" position="bottom-right">
      <button
        aria-label={t('editor.zoomOut')}
        onClick={() => void zoomOut({ duration: 150 })}
        type="button"
      >
        −
      </button>
      <output aria-label={t('editor.zoomLevel')}>
        {Math.round(zoom * 100)}%
      </output>
      <button
        aria-label={t('editor.zoomIn')}
        onClick={() => void zoomIn({ duration: 150 })}
        type="button"
      >
        +
      </button>
    </Panel>
  )
}

type TreeCanvasProps = {
  onClearSelection: () => void
  onSelectPerson: (personId: string) => void
  selectedPersonId: string | null
  snapshot: TreeSnapshot
}

function TreeCanvas({
  onClearSelection,
  onSelectPerson,
  selectedPersonId,
  snapshot,
}: TreeCanvasProps) {
  const instance = useRef<
    ReactFlowInstance<PersonFlowNode, TreeFlowEdge> | null
  >(null)
  const centered = useRef(false)
  const nodes = useMemo<PersonFlowNode[]>(() => snapshot.people.map((person) => ({
    id: person.id,
    type: 'person',
    position: person.coordinates,
    data: { person },
    selected: person.id === selectedPersonId,
  })), [selectedPersonId, snapshot.people])
  const edges = useMemo<TreeFlowEdge[]>(
    () => buildTreeEdges(snapshot),
    [snapshot],
  )

  const centerFirstPerson = useCallback((
    flow: ReactFlowInstance<PersonFlowNode, TreeFlowEdge>,
  ) => {
    const first = snapshot.people[0]
    if (!first || centered.current) {
      return
    }
    centered.current = true
    void flow.setCenter(
      first.coordinates.x + PERSON_CARD_WIDTH / 2,
      first.coordinates.y + PERSON_CARD_HEIGHT / 2,
      { duration: 0, zoom: 1 },
    )
  }, [snapshot.people])

  useEffect(() => {
    if (instance.current) {
      centerFirstPerson(instance.current)
    }
  }, [centerFirstPerson])

  return (
    <ReactFlow<PersonFlowNode, TreeFlowEdge>
      defaultViewport={{ x: 0, y: 0, zoom: 1 }}
      edges={edges}
      edgeTypes={edgeTypes}
      maxZoom={2}
      minZoom={0.35}
      nodeTypes={nodeTypes}
      nodes={nodes}
      nodesConnectable={false}
      nodesDraggable={false}
      onInit={(flow) => {
        instance.current = flow
        centerFirstPerson(flow)
      }}
      onNodeClick={(_event, node) => onSelectPerson(node.id)}
      onPaneClick={onClearSelection}
      panOnDrag
      proOptions={{ hideAttribution: true }}
      zoomOnDoubleClick={false}
      zoomOnPinch
      zoomOnScroll
    >
      <Background color="var(--color-border)" gap={22} size={1} />
      <ZoomControls />
    </ReactFlow>
  )
}

type EditorBreadcrumbsProps = {
  status: 'error' | 'loading' | 'missing' | 'ready'
  treeName: string | undefined
}

function EditorBreadcrumbs({
  status,
  treeName,
}: EditorBreadcrumbsProps) {
  const { t } = useTranslation()
  const [container, setContainer] = useState<HTMLElement | null>(null)

  useEffect(() => {
    setContainer(document.getElementById('header-breadcrumbs'))
  }, [])

  if (!container) {
    return null
  }

  return createPortal(
    <nav
      aria-label={t('editor.breadcrumbs')}
      className="editor-breadcrumbs"
    >
      <button onClick={() => navigate('/trees')} type="button">
        {t('trees.title')}
      </button>
      <span aria-hidden="true">/</span>
      <strong>
        {treeName
          ?? (status === 'loading' ? t('trees.loading') : 'MyGenoTree')}
      </strong>
    </nav>,
    container,
  )
}

type PendingRemoval =
  | { kind: 'marriage'; marriage: MarriagePeriod }
  | { kind: 'parent'; link: ParentLink }
  | { kind: 'person'; person: Person }

export function TreeEditorPage({ treeId }: TreeEditorPageProps) {
  const { t } = useTranslation()
  const { request } = useAuth()
  const [snapshot, setSnapshot] = useState<TreeSnapshot | null>(null)
  const [status, setStatus] = useState<'error' | 'loading' | 'missing' | 'ready'>(
    'loading',
  )
  const [creating, setCreating] = useState(false)
  const [selectedPerson, setSelectedPerson] = useState<Person | null>(null)
  const [events, setEvents] = useState<GenealogicalEvent[]>([])
  const [conflict, setConflict] = useState(false)
  const [relativeAction, setRelativeAction] = useState<RelativeAction | null>(
    null,
  )
  const [relativeSecondParentId, setRelativeSecondParentId] = useState<
    string | null
  >(null)
  const [marriageEditing, setMarriageEditing] = useState<
    MarriagePeriod | null
  >(null)
  const [pendingRemoval, setPendingRemoval] = useState<PendingRemoval | null>(
    null,
  )
  const [eventEditing, setEventEditing] = useState<
    GenealogicalEvent | 'new' | null
  >(null)
  const [eventPendingDeletion, setEventPendingDeletion] = useState<
    GenealogicalEvent | null
  >(null)
  const [photoEditing, setPhotoEditing] = useState(false)
  const [photoDeleting, setPhotoDeleting] = useState(false)
  const [photoViewing, setPhotoViewing] = useState(false)

  const closePersonDetails = useCallback(() => {
    setSelectedPerson(null)
    setEvents([])
    setEventEditing(null)
    setEventPendingDeletion(null)
    setPhotoEditing(false)
    setPhotoDeleting(false)
    setPhotoViewing(false)
  }, [])

  const loadSnapshot = useCallback(async () => {
    setStatus('loading')
    try {
      const loaded = await treeApi.snapshot(request, treeId)
      const coordinateUpdates = reflowTree(loaded)
      const normalized = coordinateUpdates.length === 0
        ? loaded
        : await treeApi.mutate(request, treeId, loaded.etag, {
          type: 'reflow_coordinates',
          coordinateUpdates,
        })
      setSnapshot(normalized)
      setConflict(false)
      setSelectedPerson(null)
      setEvents([])
      setRelativeAction(null)
      setRelativeSecondParentId(null)
      setMarriageEditing(null)
      setPendingRemoval(null)
      setEventEditing(null)
      setEventPendingDeletion(null)
      setPhotoEditing(false)
      setPhotoDeleting(false)
      setPhotoViewing(false)
      setStatus('ready')
    } catch (error) {
      setStatus(
        error instanceof ApiRequestError && error.status === 404
          ? 'missing'
          : 'error',
      )
    }
  }, [request, treeId])

  useEffect(() => {
    void loadSnapshot()
  }, [loadSnapshot])

  const createFirstPerson = async (person: PersonInput) => {
    if (!snapshot) {
      return
    }
    try {
      const updated = await treeApi.createFirstPerson(
        request,
        treeId,
        snapshot.etag,
        person,
        { x: 0, y: 0 },
      )
      setSnapshot(updated)
      setCreating(false)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const selectPerson = async (personId: string) => {
    try {
      const [person, timeline] = await Promise.all([
        personApi.get(request, treeId, personId),
        personApi.events(request, treeId, personId),
      ])
      setSelectedPerson(person)
      setEvents(timeline.items)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
    }
  }

  const patchPerson = async (patch: PersonPatchRequest) => {
    if (!snapshot || !selectedPerson || conflict) {
      throw new Error('Person autosave is unavailable')
    }
    try {
      const result = await personApi.update(
        request,
        treeId,
        selectedPerson.id,
        snapshot.etag,
        patch,
      )
      setSelectedPerson(result.person)
      setSnapshot({
        ...snapshot,
        etag: result.etag,
        people: snapshot.people.map((person) => (
          person.id === result.person.id ? result.person : person
        )),
      })
      setEvents((await personApi.events(
        request,
        treeId,
        selectedPerson.id,
      )).items)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const applyMutation = async (mutation: GraphMutation) => {
    if (!snapshot || conflict) {
      throw new Error('Tree mutation is unavailable')
    }
    try {
      const updated = await treeApi.mutate(
        request,
        treeId,
        snapshot.etag,
        mutation,
      )
      setSnapshot(updated)
      const selectedId = selectedPerson?.id
      const nextSelected = selectedId
        ? updated.people.find((person) => person.id === selectedId) ?? null
        : null
      setSelectedPerson(nextSelected)
      if (nextSelected) {
        setEvents((await personApi.events(
          request,
          treeId,
          nextSelected.id,
        )).items)
      } else {
        setEvents([])
      }

      return updated
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const addRelative = async (
    relative: RelativePerson,
    options: RelativeOptions,
  ) => {
    if (!snapshot || !selectedPerson || !relativeAction) {
      return
    }
    const initialCoordinates = newRelativeCoordinates(
      snapshot,
      selectedPerson.id,
      relativeAction,
      options.secondParentId ?? undefined,
    )
    const relativeId = 'person' in relative
      ? PREVIEW_PERSON_ID
      : relative.personId
    const people = 'person' in relative
      ? [...snapshot.people, {
        ...relative.person,
        id: PREVIEW_PERSON_ID,
        photoThumbnailUrl: null,
        coordinates: initialCoordinates,
        createdAt: '',
        updatedAt: '',
      }]
      : snapshot.people
    const parents: ParentSpecification[] = relativeAction === 'child'
      ? [{
        personId: selectedPerson.id,
        role: options.parentRole,
      }, ...(options.secondParentId ? [{
        personId: options.secondParentId,
          role: (options.parentRole === 'father' ? 'mother' : 'father') as ParentRole,
      }] : [])]
      : []
    const parentLinks = relativeAction === 'child'
      ? [...snapshot.parentLinks, ...parents.map((parent, index) => ({
        id: `preview-child-parent-${index}`,
        parentId: parent.personId,
        childId: relativeId,
        role: parent.role,
      }))]
      : relativeAction === 'father' || relativeAction === 'mother'
        ? [...snapshot.parentLinks, {
          id: 'preview-parent-child',
          parentId: relativeId,
          childId: selectedPerson.id,
          role: relativeAction,
        }]
        : snapshot.parentLinks
    const reflowSnapshot = {
      people,
      parentLinks,
      marriages: snapshot.marriages,
      ...(relativeAction === 'spouse'
        ? { partnerPairs: [[selectedPerson.id, relativeId] as [string, string]] }
        : {}),
    }
    const coordinateUpdates = reflowTree(reflowSnapshot)
    const coordinates = coordinateUpdates.find((update) => (
      update.personId === PREVIEW_PERSON_ID
    ))?.coordinates ?? initialCoordinates
    const positionedRelative: RelativePerson = 'person' in relative
      ? { ...relative, coordinates }
      : relative
    const existingCoordinateUpdates = coordinateUpdates.filter((update) => (
      update.personId !== PREVIEW_PERSON_ID
    ))
    let mutation: GraphMutation
    if (relativeAction === 'father' || relativeAction === 'mother') {
      mutation = {
        type: 'add_parent',
        childId: selectedPerson.id,
        role: relativeAction,
        relative: positionedRelative,
        coordinateUpdates: existingCoordinateUpdates,
      }
    } else if (relativeAction === 'child') {
      mutation = {
        type: 'add_child',
        parents,
        relative: positionedRelative,
        coordinateUpdates: existingCoordinateUpdates,
      }
    } else {
      mutation = {
        type: 'add_marriage_period',
        personId: selectedPerson.id,
        relative: positionedRelative,
        marriageDate: options.marriageDate,
        dissolutionDate: options.dissolutionDate,
        maleNewSurname: options.maleNewSurname,
        femaleNewSurname: options.femaleNewSurname,
        coordinateUpdates: existingCoordinateUpdates,
      }
    }
    await applyMutation(mutation)
    setRelativeAction(null)
    setRelativeSecondParentId(null)
  }

  const confirmRemoval = async () => {
    if (!pendingRemoval || !snapshot) {
      return
    }
    const coordinateUpdates = pendingRemoval.kind === 'parent'
      ? reflowTree({
          people: snapshot.people,
          parentLinks: snapshot.parentLinks.filter((link) => (
            link.id !== pendingRemoval.link.id
          )),
          marriages: snapshot.marriages,
        })
      : pendingRemoval.kind === 'person'
        ? reflowTree({
            people: snapshot.people.filter((person) => (
              person.id !== pendingRemoval.person.id
            )),
            parentLinks: snapshot.parentLinks.filter((link) => (
              link.parentId !== pendingRemoval.person.id
              && link.childId !== pendingRemoval.person.id
            )),
            marriages: snapshot.marriages.filter((marriage) => (
              marriage.malePersonId !== pendingRemoval.person.id
              && marriage.femalePersonId !== pendingRemoval.person.id
            )),
          }, { compact: true })
        : reflowTree({
            people: snapshot.people,
            parentLinks: snapshot.parentLinks,
            marriages: snapshot.marriages.filter((marriage) => (
              marriage.id !== pendingRemoval.marriage.id
            )),
          })
    const mutation: GraphMutation = pendingRemoval.kind === 'parent'
      ? {
          type: 'remove_parent_link',
          parentLinkId: pendingRemoval.link.id,
          coordinateUpdates,
        }
      : pendingRemoval.kind === 'marriage'
        ? {
            type: 'remove_marriage_period',
            marriageId: pendingRemoval.marriage.id,
            coordinateUpdates,
          }
        : {
            type: 'delete_person',
            personId: pendingRemoval.person.id,
            coordinateUpdates,
          }
    await applyMutation(mutation)
    setPendingRemoval(null)
  }

  const editMarriage = async (
    patch: MarriagePeriodPatchRequest,
  ) => {
    if (!snapshot || !marriageEditing || conflict) {
      throw new Error('Marriage update is unavailable')
    }
    try {
      const result = await marriageApi.update(
        request,
        treeId,
        marriageEditing.id,
        snapshot.etag,
        patch,
      )
      setSnapshot({
        ...snapshot,
        etag: result.etag,
        marriages: snapshot.marriages.map((marriage) => (
          marriage.id === result.marriage.id ? result.marriage : marriage
        )),
      })
      if (selectedPerson) {
        setEvents((await personApi.events(
          request,
          treeId,
          selectedPerson.id,
        )).items)
      }
      setMarriageEditing(null)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const saveEvent = async (payload: ManualEventRequest) => {
    if (!snapshot || !selectedPerson || !eventEditing || conflict) {
      throw new Error('Event mutation is unavailable')
    }
    try {
      const result = eventEditing === 'new'
        ? await eventApi.create(
            request,
            treeId,
            selectedPerson.id,
            snapshot.etag,
            payload,
          )
        : await eventApi.update(
            request,
            treeId,
            selectedPerson.id,
            eventEditing.id,
            snapshot.etag,
            payload,
          )
      const timeline = await personApi.events(
        request,
        treeId,
        selectedPerson.id,
      )
      setEvents(timeline.items)
      setSnapshot({ ...snapshot, etag: result.etag })
      setEventEditing(null)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const deleteEvent = async () => {
    if (!snapshot || !selectedPerson || !eventPendingDeletion || conflict) {
      throw new Error('Event deletion is unavailable')
    }
    try {
      await eventApi.remove(
        request,
        treeId,
        selectedPerson.id,
        eventPendingDeletion.id,
        snapshot.etag,
      )
      const [updated, timeline] = await Promise.all([
        treeApi.snapshot(request, treeId),
        personApi.events(request, treeId, selectedPerson.id),
      ])
      setSnapshot(updated)
      setSelectedPerson(
        updated.people.find((person) => person.id === selectedPerson.id)
          ?? null,
      )
      setEvents(timeline.items)
      setEventPendingDeletion(null)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const reorderEvents = async (eventIds: string[]) => {
    if (!snapshot || !selectedPerson || conflict) {
      throw new Error('Event ordering is unavailable')
    }
    try {
      const result = await eventApi.reorder(
        request,
        treeId,
        selectedPerson.id,
        snapshot.etag,
        eventIds,
      )
      setEvents(result.items)
      setSnapshot({ ...snapshot, etag: result.etag })
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const applyPhotoResult = (result: {
    person: Person
    etag: string
  }) => {
    if (!snapshot) {
      return
    }
    setSelectedPerson(result.person)
    setSnapshot({
      ...snapshot,
      etag: result.etag,
      people: snapshot.people.map((person) => (
        person.id === result.person.id ? result.person : person
      )),
    })
  }

  const uploadPhoto = async (
    file: File,
    crop: import('../api/client').CropPixels,
  ) => {
    if (!snapshot || !selectedPerson || conflict) {
      throw new Error('Photo upload is unavailable')
    }
    try {
      const result = await personPhotoApi.upload(
        request,
        treeId,
        selectedPerson.id,
        snapshot.etag,
        file,
        crop,
      )
      applyPhotoResult(result)
      setPhotoEditing(false)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  const deletePhoto = async () => {
    if (!snapshot || !selectedPerson || conflict) {
      throw new Error('Photo deletion is unavailable')
    }
    try {
      const result = await personPhotoApi.remove(
        request,
        treeId,
        selectedPerson.id,
        snapshot.etag,
      )
      applyPhotoResult(result)
      setPhotoDeleting(false)
      setPhotoViewing(false)
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
      }
      throw error
    }
  }

  return (
    <main className="tree-editor-page">
      <EditorBreadcrumbs
        status={status}
        treeName={snapshot?.tree.name}
      />

      {conflict && (
        <div className="version-conflict-banner" role="alert">
          <span>{t('editor.versionConflict')}</span>
          <button
            className="secondary-button compact-button"
            onClick={() => void loadSnapshot()}
            type="button"
          >
            {t('editor.reload')}
          </button>
        </div>
      )}

      <section className={`editor-workspace${
        selectedPerson ? ' editor-workspace-panel-open' : ''
      }`}>
        {status === 'loading' && (
          <div className="editor-state">{t('editor.loading')}</div>
        )}
        {status === 'missing' && (
          <div className="editor-state">
            <p role="alert">{t('trees.notFound')}</p>
          </div>
        )}
        {status === 'error' && (
          <div className="editor-state">
            <p role="alert">{t('editor.loadFailed')}</p>
            <button
              className="secondary-button"
              onClick={() => void loadSnapshot()}
              type="button"
            >
              {t('trees.retry')}
            </button>
          </div>
        )}
        {status === 'ready' && snapshot && (
          <div className="tree-canvas">
            {snapshot.people.length === 0 ? (
              <div className="empty-tree-action">
                <p>{t('trees.editorEmpty')}</p>
                <button
                  className="primary-button"
                  disabled={conflict}
                  onClick={() => setCreating(true)}
                  type="button"
                >
                  {t('person.create')}
                </button>
              </div>
            ) : (
              <ReactFlowProvider>
                <TreeCanvas
                  onClearSelection={closePersonDetails}
                  onSelectPerson={(personId) => void selectPerson(personId)}
                  selectedPersonId={selectedPerson?.id ?? null}
                  snapshot={snapshot}
                />
              </ReactFlowProvider>
            )}
          </div>
        )}

        {selectedPerson && snapshot && (
          <PersonDetailsPanel
            conflict={conflict}
            events={events}
            onAddRelative={(action, secondParentId) => {
              setRelativeAction(action)
              setRelativeSecondParentId(secondParentId ?? null)
            }}
            onClose={closePersonDetails}
            onCreateEvent={() => setEventEditing('new')}
            onDeleteEvent={setEventPendingDeletion}
            onDeletePerson={() => setPendingRemoval({
              kind: 'person',
              person: selectedPerson,
            })}
            onDeletePhoto={() => setPhotoDeleting(true)}
            onEditEvent={setEventEditing}
            onEditMarriage={setMarriageEditing}
            onOpenPhoto={() => setPhotoViewing(true)}
            onPatch={patchPerson}
            onReorderEvents={reorderEvents}
            onRemoveMarriage={(marriage) => setPendingRemoval({
              kind: 'marriage',
              marriage,
            })}
            onRemoveParentLink={(link) => setPendingRemoval({
              kind: 'parent',
              link,
            })}
            onUploadPhoto={() => setPhotoEditing(true)}
            person={selectedPerson}
            snapshot={snapshot}
          />
        )}
      </section>

      {creating && (
        <PersonFormDialog
          onCancel={() => setCreating(false)}
          onSave={createFirstPerson}
        />
      )}
      {relativeAction && selectedPerson && snapshot && (
        <RelativeDialog
          action={relativeAction}
          anchor={selectedPerson}
          initialSecondParentId={relativeSecondParentId ?? undefined}
          onCancel={() => {
            setRelativeAction(null)
            setRelativeSecondParentId(null)
          }}
          onSave={addRelative}
          snapshot={snapshot}
        />
      )}
      {marriageEditing && (
        <MarriagePeriodDialog
          marriage={marriageEditing}
          onCancel={() => setMarriageEditing(null)}
          onSave={editMarriage}
        />
      )}
      {eventEditing && (
        <EventDialog
          {...(eventEditing === 'new' ? {} : { event: eventEditing })}
          onCancel={() => setEventEditing(null)}
          onSave={saveEvent}
        />
      )}
      {eventPendingDeletion && (
        <ConfirmRelationshipDialog
          description={t('events.deleteDescription')}
          errorMessage={t('events.removeFailed')}
          onCancel={() => setEventPendingDeletion(null)}
          onConfirm={deleteEvent}
          title={t('events.deleteTitle')}
        />
      )}
      {photoEditing && (
        <ImageCropDialog
          aspect={3 / 4}
          onCancel={() => setPhotoEditing(false)}
          onSave={uploadPhoto}
          title={t('media.photoTitle')}
        />
      )}
      {photoViewing && selectedPerson?.photoThumbnailUrl && (
        <PhotoViewerDialog
          onClose={() => setPhotoViewing(false)}
          title={t('media.fullPhoto')}
          url={`/trees/${treeId}/people/${selectedPerson.id}/photo${
            selectedPerson.photoThumbnailUrl.includes('?')
              ? `?${selectedPerson.photoThumbnailUrl.split('?')[1]}`
              : ''
          }`}
        />
      )}
      {photoDeleting && (
        <ConfirmRelationshipDialog
          description={t('media.deletePhotoDescription')}
          errorMessage={t('media.removeFailed')}
          onCancel={() => setPhotoDeleting(false)}
          onConfirm={deletePhoto}
          title={t('media.deletePhotoTitle')}
        />
      )}
      {pendingRemoval && (
        <ConfirmRelationshipDialog
          description={t(`relationships.confirm.${pendingRemoval.kind}.description`)}
          onCancel={() => setPendingRemoval(null)}
          onConfirm={confirmRemoval}
          title={t(`relationships.confirm.${pendingRemoval.kind}.title`)}
        />
      )}
    </main>
  )
}
