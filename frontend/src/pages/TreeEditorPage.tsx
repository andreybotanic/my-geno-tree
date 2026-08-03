import {
  useCallback,
  useEffect,
  useLayoutEffect,
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
import {
  BloodNetworkFamilyNode,
  BloodNetworkEdge,
  BloodNetworkPersonNode,
} from '../components/BloodNetworkNodes'
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
import { ToastNotice } from '../components/ToastNotice'
import { navigate } from '../routing'
import {
  buildTreeEdges,
  newRelativeCoordinates,
  PERSON_CARD_HEIGHT,
  PERSON_CARD_WIDTH,
  reflowTree,
  type TreeFlowEdge,
} from '../treeGraph'
import {
  BLOOD_NETWORK_FAMILY_SIZE,
  BLOOD_NETWORK_PERSON_SIZE,
  bloodNetworkAverageNodeMovement,
  buildBloodNetwork,
  createBloodNetworkSimulation,
  DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS,
  type BloodNetworkFlowEdge,
  type BloodNetworkForceSettings,
  type BloodNetworkFlowNode,
} from '../bloodNetwork'
import {
  LAYOUT_WARNING_THRESHOLD,
  LIMITED_LAYOUT_SEARCH,
  estimateLayoutCandidates,
  type LayoutOptimizationResult,
} from '../layoutOptimizer'

type TreeEditorPageProps = {
  treeId: string
}

const nodeTypes = {
  person: PersonCard,
  bloodPerson: BloodNetworkPersonNode,
  bloodFamily: BloodNetworkFamilyNode,
}
const edgeTypes = {
  bloodNetwork: BloodNetworkEdge,
  family: FamilyEdge,
  singleParent: SingleParentEdge,
}
const PREVIEW_PERSON_ID = '__new_person__'
const LAYOUT_PROGRESS_DELAY_MS = 2_500

function bloodNetworkBounds(nodes: BloodNetworkFlowNode[]) {
  const initialBounds = {
    maxX: Number.NEGATIVE_INFINITY,
    maxY: Number.NEGATIVE_INFINITY,
    minX: Number.POSITIVE_INFINITY,
    minY: Number.POSITIVE_INFINITY,
  }
  const bounds = nodes.reduce((result, node) => {
    const size = node.type === 'bloodPerson'
      ? BLOOD_NETWORK_PERSON_SIZE
      : BLOOD_NETWORK_FAMILY_SIZE
    result.minX = Math.min(result.minX, node.position.x)
    result.minY = Math.min(result.minY, node.position.y)
    result.maxX = Math.max(result.maxX, node.position.x + size)
    result.maxY = Math.max(result.maxY, node.position.y + size)
    return result
  }, initialBounds)

  return {
    height: bounds.maxY - bounds.minY,
    width: bounds.maxX - bounds.minX,
    x: bounds.minX,
    y: bounds.minY,
  }
}

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

type LayoutPreview = {
  coordinateUpdates: Array<{
    coordinates: { x: number; y: number }
    personId: string
  }>
  currentWidth: number
  limited: boolean
  optimizedWidth: number
  snapshot: TreeSnapshot
}

type LayoutSearch = {
  checked: bigint
  limited: boolean
  total: bigint
}

type LayoutToolbarProps = {
  isNetworkView: boolean
  onOptimize: () => void
  onRegenerateNetwork: () => void
  onToggleNetworkView: () => void
  unavailable: boolean
}

function LayoutToolbar({
  isNetworkView,
  onOptimize,
  onRegenerateNetwork,
  onToggleNetworkView,
  unavailable,
}: LayoutToolbarProps) {
  const { t } = useTranslation()

  return (
    <Panel className="editor-toolbar" position="top-right">
      <button
        aria-label={isNetworkView
          ? t('editor.showTreeView')
          : t('editor.showBloodNetwork')}
        aria-pressed={isNetworkView}
        className="editor-tool-button"
        onClick={onToggleNetworkView}
        title={isNetworkView
          ? t('editor.showTreeView')
          : t('editor.showBloodNetwork')}
        type="button"
      >
        <svg aria-hidden="true" viewBox="0 0 24 24">
          <path d="M6 7 12 4l6 3M6 7l-2 8m8-11v8m6-5 2 8M4 15l8 5 8-5" />
          <circle cx="6" cy="7" r="2.3" />
          <circle cx="12" cy="4" r="2.3" />
          <circle cx="18" cy="7" r="2.3" />
          <circle cx="4" cy="15" r="2.3" />
          <circle cx="12" cy="20" r="2.3" />
          <circle cx="20" cy="15" r="2.3" />
        </svg>
      </button>
      {isNetworkView && (
        <button
          aria-label={t('editor.regenerateBloodNetwork')}
          className="editor-tool-button"
          onClick={onRegenerateNetwork}
          title={t('editor.regenerateBloodNetwork')}
          type="button"
        >
          <svg aria-hidden="true" viewBox="0 0 24 24">
            <path d="M19 8a7.5 7.5 0 1 0 1 7" />
            <path d="M19 4v4h-4" />
          </svg>
        </button>
      )}
      {!isNetworkView && (
        <button
          aria-label={t('editor.layoutOptimize')}
          className="editor-tool-button"
          disabled={unavailable}
          onClick={onOptimize}
          title={t('editor.layoutOptimize')}
          type="button"
        >
          <span aria-hidden="true">↔</span>
        </button>
      )}
    </Panel>
  )
}

type BloodNetworkLegendProps = {
  forces: BloodNetworkForceSettings
  onForceChange: (key: keyof BloodNetworkForceSettings, value: number) => void
}

type BloodNetworkForceControlProps = {
  forceKey: keyof BloodNetworkForceSettings
  forces: BloodNetworkForceSettings
  label: string
  max: number
  min: number
  onForceChange: BloodNetworkLegendProps['onForceChange']
  step: number
}

function BloodNetworkForceControl({
  forceKey,
  forces,
  label,
  max,
  min,
  onForceChange,
  step,
}: BloodNetworkForceControlProps) {
  const value = forces[forceKey]
  const id = `blood-network-force-${forceKey}`

  return (
    <label className="blood-network-force-control" htmlFor={id}>
      <span>{label}<output>{value.toFixed(step < 0.1 ? 2 : 1)}</output></span>
      <input
        aria-label={label}
        id={id}
        max={max}
        min={min}
        onChange={(event) => onForceChange(forceKey, Number(event.target.value))}
        step={step}
        type="range"
        value={value}
      />
    </label>
  )
}

function BloodNetworkLegend({ forces, onForceChange }: BloodNetworkLegendProps) {
  const { t } = useTranslation()

  return (
    <Panel className="blood-network-legend" position="bottom-left">
      <strong>{t('editor.bloodNetworkLegendTitle')}</strong>
      <span>
        <i aria-hidden="true" className="blood-network-legend-parent" />
        {t('editor.bloodNetworkParentArrow')}
      </span>
      <span>
        <i aria-hidden="true" className="blood-network-legend-child" />
        {t('editor.bloodNetworkChildArrow')}
      </span>
      <small>{t('editor.bloodNetworkForces')}</small>
      <fieldset className="blood-network-force-controls">
        <legend>{t('editor.bloodNetworkForceControls')}</legend>
        <BloodNetworkForceControl
          forceKey="averageMovementPerSecondThreshold"
          forces={forces}
          label={t('editor.bloodNetworkSettlementThreshold')}
          max={5}
          min={0.1}
          onForceChange={onForceChange}
          step={0.1}
        />
        <BloodNetworkForceControl
          forceKey="parentPairSpringStrength"
          forces={forces}
          label={t('editor.bloodNetworkParentPairSpring')}
          max={0.5}
          min={0.04}
          onForceChange={onForceChange}
          step={0.01}
        />
        <BloodNetworkForceControl
          forceKey="parentHingeTargetAngle"
          forces={forces}
          label={t('editor.bloodNetworkParentHingeTargetAngle')}
          max={120}
          min={10}
          onForceChange={onForceChange}
          step={1}
        />
        <BloodNetworkForceControl
          forceKey="parentHingeSpringStrength"
          forces={forces}
          label={t('editor.bloodNetworkParentHingeSpring')}
          max={10}
          min={0}
          onForceChange={onForceChange}
          step={0.1}
        />
        <BloodNetworkForceControl
          forceKey="sharedParentSpringStrength"
          forces={forces}
          label={t('editor.bloodNetworkSharedParentSpring')}
          max={0.4}
          min={0.02}
          onForceChange={onForceChange}
          step={0.01}
        />
        <BloodNetworkForceControl
          forceKey="baseRepulsion"
          forces={forces}
          label={t('editor.bloodNetworkBaseRepulsion')}
          max={8}
          min={0.5}
          onForceChange={onForceChange}
          step={0.1}
        />
        <BloodNetworkForceControl
          forceKey="kinshipRepulsionStep"
          forces={forces}
          label={t('editor.bloodNetworkKinshipRepulsion')}
          max={0.6}
          min={0.05}
          onForceChange={onForceChange}
          step={0.05}
        />
        <BloodNetworkForceControl
          forceKey="unrelatedParentNodeRepulsion"
          forces={forces}
          label={t('editor.bloodNetworkUnrelatedParentNodeRepulsion')}
          max={12}
          min={0}
          onForceChange={onForceChange}
          step={0.1}
        />
      </fieldset>
    </Panel>
  )
}

type LayoutProgressProps = {
  onCancel: () => void
  search: LayoutSearch
}

function LayoutProgress({ onCancel, search }: LayoutProgressProps) {
  const { t } = useTranslation()
  const progress = search.total > 0n
    ? Number(search.checked * 100n / search.total)
    : 0

  return (
    <Panel className="layout-progress" position="bottom-center">
      <div role="status">
        <strong>{t('editor.layoutOptimizing')}</strong>
        <span>{progress}% · {t('editor.layoutChecked', {
          checked: search.checked.toString(),
          total: search.total.toString(),
        })}</span>
      </div>
      <button className="secondary-button compact-button" onClick={onCancel} type="button">
        {t('editor.layoutCancel')}
      </button>
    </Panel>
  )
}

type LayoutPreviewControlsProps = {
  onApply: () => void
  onReject: () => void
  onViewChange: (view: 'optimized' | 'original') => void
  preview: LayoutPreview
  view: 'optimized' | 'original'
}

function LayoutPreviewControls({
  onApply,
  onReject,
  onViewChange,
  preview,
  view,
}: LayoutPreviewControlsProps) {
  const { t } = useTranslation()

  return (
    <Panel className="layout-preview-controls" position="top-center">
      <strong>{t('editor.layoutPreview')}</strong>
      <span>{t('editor.layoutSavings', {
        current: preview.currentWidth,
        optimized: preview.optimizedWidth,
        saved: preview.currentWidth - preview.optimizedWidth,
      })}</span>
      <div className="layout-preview-view-switch" role="group" aria-label={t('editor.layoutPreview')}
      >
        <button
          aria-pressed={view === 'original'}
          className="secondary-button compact-button"
          onClick={() => onViewChange('original')}
          type="button"
        >
          {t('editor.layoutViewOriginal')}
        </button>
        <button
          aria-pressed={view === 'optimized'}
          className="secondary-button compact-button"
          onClick={() => onViewChange('optimized')}
          type="button"
        >
          {t('editor.layoutViewOptimized')}
        </button>
      </div>
      {preview.limited && <small>{t('editor.layoutIncomplete')}</small>}
      <div className="layout-preview-actions">
        <button className="secondary-button compact-button" onClick={onReject} type="button">
          {t('editor.layoutReject')}
        </button>
        <button className="primary-button compact-button" onClick={onApply} type="button">
          {t('editor.layoutApply')}
        </button>
      </div>
    </Panel>
  )
}

type TreeCanvasProps = {
  onClearSelection: () => void
  onApplyLayout: () => void
  onCancelLayout: () => void
  onChangeLayoutPreviewView: (view: 'optimized' | 'original') => void
  onOptimizeLayout: () => void
  onSelectPerson: (personId: string) => void
  layoutProgressVisible: boolean
  layoutPreview: LayoutPreview | null
  layoutPreviewView: 'optimized' | 'original'
  layoutSearch: LayoutSearch | null
  selectedPersonId: string | null
  snapshot: TreeSnapshot
  view: 'network' | 'tree'
  onToggleNetworkView: () => void
}

function TreeCanvas({
  onClearSelection,
  onApplyLayout,
  onCancelLayout,
  onChangeLayoutPreviewView,
  onOptimizeLayout,
  onSelectPerson,
  layoutProgressVisible,
  layoutPreview,
  layoutPreviewView,
  layoutSearch,
  selectedPersonId,
  snapshot,
  view,
  onToggleNetworkView,
}: TreeCanvasProps) {
  type EditorFlowNode = PersonFlowNode | BloodNetworkFlowNode
  type EditorFlowEdge = TreeFlowEdge | BloodNetworkFlowEdge
  const instance = useRef<
    ReactFlowInstance<EditorFlowNode, EditorFlowEdge> | null
  >(null)
  const centered = useRef(false)
  const [simulatedBloodNodes, setSimulatedBloodNodes] = useState<
    BloodNetworkFlowNode[]
  >([])
  const latestBloodNodes = useRef<BloodNetworkFlowNode[]>([])
  const activeBloodNetwork = useRef<{
    edges: BloodNetworkFlowEdge[]
    nodes: BloodNetworkFlowNode[]
  } | null>(null)
  const [bloodNetworkForces, setBloodNetworkForces] = useState(
    DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS,
  )
  const [networkGeneration, setNetworkGeneration] = useState(0)
  const treeNodes = useMemo<PersonFlowNode[]>(() => snapshot.people.map((person) => ({
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
  const bloodNetwork = useMemo(
    () => view === 'network'
      ? buildBloodNetwork(snapshot, networkGeneration)
      : { edges: [], nodes: [] },
    [networkGeneration, snapshot, view],
  )
  const nodes: EditorFlowNode[] = view === 'network'
    ? (simulatedBloodNodes.length > 0 || bloodNetwork.nodes.length === 0
      ? simulatedBloodNodes
      : bloodNetwork.nodes)
    : treeNodes
  const activeEdges: EditorFlowEdge[] = view === 'network'
    ? bloodNetwork.edges
    : edges

  const centerFirstPerson = useCallback((
    flow: ReactFlowInstance<EditorFlowNode, EditorFlowEdge>,
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
    if (view === 'tree' && instance.current) {
      centerFirstPerson(instance.current)
    }
  }, [centerFirstPerson, view])

  useLayoutEffect(() => {
    if (view !== 'network') {
      activeBloodNetwork.current = null
      latestBloodNodes.current = []
      setSimulatedBloodNodes([])
      return
    }
    const continueSimulation = activeBloodNetwork.current === bloodNetwork
      && latestBloodNodes.current.length === bloodNetwork.nodes.length
    const simulation = createBloodNetworkSimulation({
      edges: bloodNetwork.edges,
      nodes: continueSimulation ? latestBloodNodes.current : bloodNetwork.nodes,
    }, bloodNetworkForces)
    activeBloodNetwork.current = bloodNetwork
    let animationTimer: number | null = null
    let active = true
    let previousFrameTimestamp = performance.now()
    const initialNodes = simulation.getNodes()
    latestBloodNodes.current = initialNodes
    setSimulatedBloodNodes(initialNodes)
    // fitView waits for React Flow to measure every node. For a large graph
    // that postpones a camera jump until well after the force map opens.
    // The temporary nodes have known sizes, so their initial bounds can place
    // the viewport immediately and the camera remains under the user's control.
    if (!continueSimulation) {
      void instance.current?.fitBounds(bloodNetworkBounds(initialNodes), {
        duration: 0,
        padding: 0.2,
      })
    }

    const animate = () => {
      // A timer separates each state update into a browser task. React Flow
      // can therefore paint every force step instead of batching a whole
      // simulation into one visible jump on a dense map.
      const frameTimestamp = performance.now()
      simulation.tick()
      const nextNodes = simulation.getNodes()
      const averageMovementPerSecond = bloodNetworkAverageNodeMovement(
        latestBloodNodes.current,
        nextNodes,
      ) * 1_000 / Math.max(frameTimestamp - previousFrameTimestamp, 1)
      previousFrameTimestamp = frameTimestamp
      latestBloodNodes.current = nextNodes
      setSimulatedBloodNodes(nextNodes)
      if (
        active
        && averageMovementPerSecond > bloodNetworkForces.averageMovementPerSecondThreshold
      ) {
        animationTimer = window.setTimeout(animate, 16)
      }
    }
    animationTimer = window.setTimeout(animate, 16)

    return () => {
      active = false
      if (animationTimer !== null) {
        window.clearTimeout(animationTimer)
      }
    }
  }, [bloodNetwork, bloodNetworkForces, view])

  return (
    <ReactFlow<EditorFlowNode, EditorFlowEdge>
      defaultViewport={{ x: 0, y: 0, zoom: 1 }}
      edges={activeEdges}
      edgeTypes={edgeTypes}
      elementsSelectable={view === 'tree'}
      maxZoom={2}
      minZoom={0.1}
      nodeTypes={nodeTypes}
      nodes={nodes}
      nodesConnectable={false}
      nodesDraggable={false}
      onInit={(flow) => {
        instance.current = flow
        if (view === 'tree') {
          centerFirstPerson(flow)
        }
      }}
      onNodeClick={(_event, node) => {
        if (view === 'tree' && !layoutSearch) {
          onSelectPerson(node.id)
        }
      }}
      onPaneClick={() => {
        if (view === 'tree' && !layoutSearch) {
          onClearSelection()
        }
      }}
      panOnDrag={!layoutSearch}
      proOptions={{ hideAttribution: true }}
      zoomOnDoubleClick={false}
      zoomOnPinch={!layoutSearch}
      zoomOnScroll={!layoutSearch}
    >
      <Background color="var(--color-border)" gap={22} size={1} />
      <LayoutToolbar
        isNetworkView={view === 'network'}
        onOptimize={onOptimizeLayout}
        onRegenerateNetwork={() => setNetworkGeneration((generation) => generation + 1)}
        onToggleNetworkView={onToggleNetworkView}
        unavailable={layoutSearch !== null || layoutPreview !== null}
      />
      {view === 'network' && (
        <BloodNetworkLegend
          forces={bloodNetworkForces}
          onForceChange={(key, value) => {
            setBloodNetworkForces((current) => ({ ...current, [key]: value }))
          }}
        />
      )}
      {view === 'tree' && layoutSearch && layoutProgressVisible && (
        <LayoutProgress onCancel={onCancelLayout} search={layoutSearch} />
      )}
      {view === 'tree' && layoutPreview && (
        <LayoutPreviewControls
          onApply={onApplyLayout}
          onReject={onCancelLayout}
          onViewChange={onChangeLayoutPreviewView}
          preview={layoutPreview}
          view={layoutPreviewView}
        />
      )}
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
  const [layoutPreview, setLayoutPreview] = useState<LayoutPreview | null>(null)
  const [layoutPreviewView, setLayoutPreviewView] = useState<'optimized' | 'original'>(
    'optimized',
  )
  const [layoutProgressVisible, setLayoutProgressVisible] = useState(false)
  const [layoutSearch, setLayoutSearch] = useState<LayoutSearch | null>(null)
  const [layoutWarning, setLayoutWarning] = useState<bigint | null>(null)
  const [layoutNotice, setLayoutNotice] = useState<string | null>(null)
  const [view, setView] = useState<'network' | 'tree'>('tree')
  const layoutWorker = useRef<Worker | null>(null)
  const layoutProgressTimer = useRef<number | null>(null)

  const hideLayoutProgress = useCallback(() => {
    if (layoutProgressTimer.current !== null) {
      window.clearTimeout(layoutProgressTimer.current)
      layoutProgressTimer.current = null
    }
    setLayoutProgressVisible(false)
  }, [])

  const stopLayoutSearch = useCallback(() => {
    layoutWorker.current?.terminate()
    layoutWorker.current = null
    setLayoutSearch(null)
    hideLayoutProgress()
  }, [hideLayoutProgress])

  useEffect(() => () => {
    layoutWorker.current?.terminate()
    if (layoutProgressTimer.current !== null) {
      window.clearTimeout(layoutProgressTimer.current)
    }
  }, [])

  const closePersonDetails = useCallback(() => {
    setSelectedPerson(null)
    setEvents([])
    setEventEditing(null)
    setEventPendingDeletion(null)
    setPhotoEditing(false)
    setPhotoDeleting(false)
    setPhotoViewing(false)
  }, [])

  const toggleNetworkView = () => {
    if (view === 'network') {
      setView('tree')
      return
    }
    stopLayoutSearch()
    rejectLayoutPreview()
    setLayoutWarning(null)
    closePersonDetails()
    setView('network')
  }

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
      setLayoutPreview(null)
      setLayoutPreviewView('optimized')
      setLayoutSearch(null)
      hideLayoutProgress()
      setLayoutWarning(null)
      setLayoutNotice(null)
      setStatus('ready')
    } catch (error) {
      setStatus(
        error instanceof ApiRequestError && error.status === 404
          ? 'missing'
          : 'error',
      )
    }
  }, [hideLayoutProgress, request, treeId])

  const showLayoutOptimizer = () => {
    if (!snapshot || layoutSearch || layoutPreview) {
      return
    }
    setLayoutNotice(null)
    const candidates = estimateLayoutCandidates(snapshot)
    if (candidates > LAYOUT_WARNING_THRESHOLD) {
      setLayoutWarning(candidates)
      return
    }
    startLayoutSearch('full')
  }

  const startLayoutSearch = (mode: 'full' | 'limited') => {
    if (!snapshot) {
      return
    }
    setLayoutWarning(null)
    setLayoutNotice(null)
    hideLayoutProgress()
    const limit = mode === 'limited' ? LIMITED_LAYOUT_SEARCH : undefined
    const total = estimateLayoutCandidates(snapshot)
    setLayoutSearch({
      checked: 0n,
      limited: mode === 'limited' && total > LIMITED_LAYOUT_SEARCH,
      total: limit === undefined || total < limit ? total : limit,
    })
    layoutProgressTimer.current = window.setTimeout(() => {
      setLayoutProgressVisible(true)
      layoutProgressTimer.current = null
    }, LAYOUT_PROGRESS_DELAY_MS)
    const originalSnapshot = snapshot
    const worker = new Worker(
      new URL('../layoutOptimizer.worker.ts', import.meta.url),
      { type: 'module' },
    )
    layoutWorker.current = worker
    worker.onmessage = (event: MessageEvent<
      | { type: 'progress'; checked: bigint; total: bigint }
      | { type: 'result'; result: LayoutOptimizationResult | null }
    >) => {
      if (event.data.type === 'progress') {
        const progress = event.data
        setLayoutSearch((current) => current ? {
          ...current,
          checked: progress.checked,
          total: progress.total,
        } : current)
        return
      }
      worker.terminate()
      if (layoutWorker.current === worker) {
        layoutWorker.current = null
      }
      setLayoutSearch(null)
      hideLayoutProgress()
      const result = event.data.result
      if (!result) {
        return
      }
      if (result.coordinateUpdates.length === 0) {
        setLayoutNotice(t('editor.layoutNoImprovement'))
        return
      }
      const coordinates = new Map(result.coordinateUpdates.map((update) => [
        update.personId,
        update.coordinates,
      ]))
      setLayoutPreview({
        coordinateUpdates: result.coordinateUpdates,
        currentWidth: result.currentWidth,
        limited: result.limited,
        optimizedWidth: result.optimizedWidth,
        snapshot: {
          ...originalSnapshot,
          people: originalSnapshot.people.map((person) => ({
            ...person,
            coordinates: coordinates.get(person.id) ?? person.coordinates,
          })),
        },
      })
      setLayoutPreviewView('optimized')
    }
    worker.onerror = () => {
      worker.terminate()
      if (layoutWorker.current === worker) {
        layoutWorker.current = null
      }
      setLayoutSearch(null)
      hideLayoutProgress()
      setLayoutNotice(t('editor.layoutFailed'))
    }
    worker.postMessage({ type: 'start', snapshot: originalSnapshot, limit })
  }

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
    if (!snapshot || conflict || layoutSearch || (
      layoutPreview && mutation.type !== 'reflow_coordinates'
    )) {
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

  const applyLayoutPreview = async () => {
    if (!layoutPreview) {
      return
    }
    try {
      await applyMutation({
        type: 'reflow_coordinates',
        coordinateUpdates: layoutPreview.coordinateUpdates,
      })
      setLayoutPreview(null)
      setLayoutPreviewView('optimized')
      setLayoutNotice(t('editor.layoutApplied'))
    } catch (error) {
      // applyMutation already enters the standard ETag conflict state.
      if (error instanceof ApiRequestError && error.body.code === 'TREE_VERSION_CONFLICT') {
        setLayoutPreview(null)
      }
    }
  }

  const rejectLayoutPreview = () => {
    setLayoutPreview(null)
    setLayoutPreviewView('optimized')
    setLayoutNotice(null)
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
        view === 'tree' && selectedPerson ? ' editor-workspace-panel-open' : ''
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
                  onApplyLayout={() => void applyLayoutPreview()}
                  onCancelLayout={layoutSearch ? stopLayoutSearch : rejectLayoutPreview}
                  onChangeLayoutPreviewView={setLayoutPreviewView}
                  onOptimizeLayout={showLayoutOptimizer}
                  onSelectPerson={(personId) => void selectPerson(personId)}
                  layoutProgressVisible={layoutProgressVisible}
                  layoutPreview={layoutPreview}
                  layoutPreviewView={layoutPreviewView}
                  layoutSearch={layoutSearch}
                  selectedPersonId={selectedPerson?.id ?? null}
                  snapshot={layoutPreview?.snapshot && layoutPreviewView === 'optimized'
                    ? layoutPreview.snapshot
                    : snapshot}
                  view={view}
                  onToggleNetworkView={toggleNetworkView}
                />
              </ReactFlowProvider>
            )}
          </div>
        )}

        {view === 'tree' && selectedPerson && snapshot && (
          <PersonDetailsPanel
            conflict={conflict || layoutSearch !== null || layoutPreview !== null}
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

      {layoutNotice && (
        <ToastNotice
          closeLabel={t('editor.noticeClose')}
          message={layoutNotice}
          onClose={() => setLayoutNotice(null)}
        />
      )}

      {layoutWarning !== null && (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="layout-warning-title"
            aria-modal="true"
            className="dialog-card layout-warning-dialog"
            role="dialog"
          >
            <h2 id="layout-warning-title">{t('editor.layoutWarningTitle')}</h2>
            <p>{t('editor.layoutWarningDescription', {
              count: layoutWarning.toString(),
            })}</p>
            <div className="dialog-actions layout-warning-actions">
              <button className="secondary-button" onClick={() => setLayoutWarning(null)} type="button">
                {t('editor.layoutCancel')}
              </button>
              <button className="secondary-button" onClick={() => startLayoutSearch('limited')} type="button">
                {t('editor.layoutFirstTenThousand')}
              </button>
              <button className="primary-button" onClick={() => startLayoutSearch('full')} type="button">
                {t('editor.layoutCheckAll')}
              </button>
            </div>
          </section>
        </div>
      )}

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
