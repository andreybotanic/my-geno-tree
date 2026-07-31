import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
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
  Person,
  PersonInput,
  PersonPatchRequest,
  TreeSnapshot,
} from '../api/generated'
import { ApiRequestError, personApi, treeApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import {
  PersonCard,
  type PersonFlowNode,
} from '../components/PersonCard'
import { PersonDetailsPanel } from '../components/PersonDetailsPanel'
import { PersonFormDialog } from '../components/PersonFormDialog'
import { navigate } from '../routing'

type TreeEditorPageProps = {
  treeId: string
}

const nodeTypes = { person: PersonCard }

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
  onSelectPerson: (personId: string) => void
  people: Person[]
  selectedPersonId: string | null
}

function TreeCanvas({
  onSelectPerson,
  people,
  selectedPersonId,
}: TreeCanvasProps) {
  const instance = useRef<ReactFlowInstance<PersonFlowNode> | null>(null)
  const centered = useRef(false)
  const nodes = useMemo<PersonFlowNode[]>(() => people.map((person) => ({
    id: person.id,
    type: 'person',
    position: person.coordinates,
    data: { person },
    selected: person.id === selectedPersonId,
  })), [people, selectedPersonId])

  useEffect(() => {
    const first = people[0]
    if (!first || centered.current || !instance.current) {
      return
    }
    centered.current = true
    void instance.current.setCenter(
      first.coordinates.x + 110,
      first.coordinates.y + 72,
      { duration: 0, zoom: 1 },
    )
  }, [people])

  return (
    <ReactFlow<PersonFlowNode>
      defaultViewport={{ x: 0, y: 0, zoom: 1 }}
      edges={[]}
      maxZoom={2}
      minZoom={0.35}
      nodeTypes={nodeTypes}
      nodes={nodes}
      nodesConnectable={false}
      nodesDraggable={false}
      onInit={(flow) => {
        instance.current = flow
      }}
      onNodeClick={(_event, node) => onSelectPerson(node.id)}
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

  const loadSnapshot = useCallback(async () => {
    setStatus('loading')
    try {
      const loaded = await treeApi.snapshot(request, treeId)
      setSnapshot(loaded)
      setConflict(false)
      setSelectedPerson(null)
      setEvents([])
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

  return (
    <main className="tree-editor-page">
      <div className="editor-toolbar">
        <button
          className="back-button"
          onClick={() => navigate('/trees')}
          type="button"
        >
          ← {t('trees.back')}
        </button>
        <strong>
          {snapshot?.tree.name
            ?? (status === 'loading' ? t('trees.loading') : 'MyGenoTree')}
        </strong>
      </div>

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
                  onSelectPerson={(personId) => void selectPerson(personId)}
                  people={snapshot.people}
                  selectedPersonId={selectedPerson?.id ?? null}
                />
              </ReactFlowProvider>
            )}
          </div>
        )}

        {selectedPerson && (
          <PersonDetailsPanel
            conflict={conflict}
            events={events}
            onClose={() => {
              setSelectedPerson(null)
              setEvents([])
            }}
            onPatch={patchPerson}
            person={selectedPerson}
          />
        )}
      </section>

      {creating && (
        <PersonFormDialog
          onCancel={() => setCreating(false)}
          onSave={createFirstPerson}
        />
      )}
    </main>
  )
}
