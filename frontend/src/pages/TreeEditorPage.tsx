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
  type Viewport,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useTranslation } from 'react-i18next'
import type {
  BloodNetworkMapState,
  BloodNetworkPreset,
  GenealogicalEvent,
  GraphMutation,
  ManualEventRequest,
  MarriagePeriod,
  MarriagePeriodPatchRequest,
  ParentLink,
  ParentRole,
  ParentSpecification,
  Person,
  PersonDetails,
  PersonSearchResult,
  PersonInput,
  PersonPatchRequest,
  RelativePerson,
  ResolvedEventPlace,
  TreeSnapshot,
  TreeReference,
  SearchRoutePlan,
  User,
} from '../api/generated'
import {
  ApiRequestError,
  eventApi,
  marriageApi,
  personPhotoApi,
  personApi,
  treeApi,
  type AuthorizedJsonRequest,
} from '../api/client'
import { useAuth } from '../auth/AuthContext'
import {
  PersonCard,
  type PersonFlowNode,
} from '../components/PersonCard'
import {
  BloodNetworkFamilyNode,
  BloodNetworkEdgesOverlay,
  BloodNetworkPersonNode,
} from '../components/BloodNetworkNodes'
import { PersonDetailsPanel } from '../components/PersonDetailsPanel'
import { PersonSearchPanel } from '../components/PersonSearchPanel'
import { PersonFormDialog } from '../components/PersonFormDialog'
import {
  ParentsFormDialog,
  type ParentsFormValue,
} from '../components/ParentsFormDialog'
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
import { PlaceDialog } from '../components/PlaceDialog'
import { ImageCropDialog } from '../components/ImageCropDialog'
import { PhotoViewerDialog } from '../components/PhotoViewerDialog'
import { ToastNotice } from '../components/ToastNotice'
import { ExportTreeDialog } from '../components/TreeExchangeDialogs'
import {
  matchTreeEditorPath,
  navigate,
  treeEditorPath,
  usePath,
  type TreeEditorView,
} from '../routing'
import {
  buildTreeEdges,
  layoutTree,
  PERSON_CARD_HEIGHT,
  PERSON_CARD_WIDTH,
  type FamilyFlowEdge,
  type TreeFlowEdge,
} from '../treeGraph'
import { effectiveReference, focusedTreeSnapshot } from '../focusedTree'
import { formatPersonName } from '../personFormatting'
import { projectSearchRoute } from '../searchRouteProjection'
import { canDeletePerson } from '../relationshipPermissions'
import {
  BLOOD_NETWORK_FAMILY_SIZE,
  BLOOD_NETWORK_PERSON_SIZE,
  bloodNetworkAverageNodeMovement,
  buildBloodNetwork,
  createBloodNetworkSimulation,
  DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS,
  defaultBloodNetworkPresets,
  restoreBloodNetworkNodePositions,
  rotateBloodNetworkNodes,
  type BloodNetworkFlowEdge,
  type BloodNetworkForceSettings,
  type BloodNetworkFlowNode,
  type BloodNetworkSimulation,
} from '../bloodNetwork'

type TreeEditorPageProps = {
  importWarningCount?: number | null
  treeId: string
}

const nodeTypes = {
  person: PersonCard,
  bloodPerson: BloodNetworkPersonNode,
  bloodFamily: BloodNetworkFamilyNode,
}
const edgeTypes = {
  family: FamilyEdge,
  singleParent: SingleParentEdge,
}

type BloodNetworkNodeContextMenu = {
  nodeId: string
  x: number
  y: number
}

type TreeContextMenu = {
  kind: 'family' | 'person'
  married?: boolean
  personId?: string
  personIds?: [string, string]
  x: number
  y: number
}

type BloodNetworkRotationSession = {
  initialAngle: number
  nodes: BloodNetworkFlowNode[]
  pivot: { x: number; y: number }
  pivotNodeId: string
}

function haveBloodNetworkPresetsChanged(
  presets: BloodNetworkPreset[],
  savedPresets: BloodNetworkPreset[],
) {
  if (presets.length !== savedPresets.length) {
    return true
  }

  return presets.some((preset) => {
    const savedPreset = savedPresets.find((item) => item.id === preset.id)
    if (!savedPreset) {
      return true
    }

    return preset.name.trim() !== savedPreset.name
      || preset.isDefault !== savedPreset.isDefault
      || Object.entries(preset.forces).some(([key, value]) => (
        savedPreset.forces[key as keyof BloodNetworkForceSettings] !== value
      ))
  })
}

function bloodNetworkNodeCenter(node: BloodNetworkFlowNode) {
  const size = node.type === 'bloodPerson'
    ? BLOOD_NETWORK_PERSON_SIZE
    : BLOOD_NETWORK_FAMILY_SIZE
  return {
    x: node.position.x + size / 2,
    y: node.position.y + size / 2,
  }
}

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

function ZoomControls({ onReset }: { onReset: () => void }) {
  const { t } = useTranslation()
  const { zoomIn, zoomOut } = useReactFlow()
  const { zoom } = useViewport()

  return (
    <Panel className="camera-controls" position="bottom-right">
      <div className="camera-reset-controls">
        <button
          aria-label={t('editor.resetCamera')}
          className="editor-tool-button"
          onClick={onReset}
          title={t('editor.resetCamera')}
          type="button"
        >
          <svg aria-hidden="true" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="5" />
            <path d="M12 2v5M12 17v5M2 12h5M17 12h5" />
          </svg>
        </button>
      </div>
      <div className="zoom-controls">
        <button
          aria-label={t('editor.zoomOut')}
          className="editor-tool-button"
          onClick={() => void zoomOut({ duration: 150 })}
          title={t('editor.zoomOut')}
          type="button"
        >
          <svg aria-hidden="true" viewBox="0 0 24 24">
            <path d="M5 12h14" />
          </svg>
        </button>
        <output aria-label={t('editor.zoomLevel')}>
          {Math.round(zoom * 100)}%
        </output>
        <button
          aria-label={t('editor.zoomIn')}
          className="editor-tool-button"
          onClick={() => void zoomIn({ duration: 150 })}
          title={t('editor.zoomIn')}
          type="button"
        >
          <svg aria-hidden="true" viewBox="0 0 24 24">
            <path d="M5 12h14M12 5v14" />
          </svg>
        </button>
      </div>
    </Panel>
  )
}

type LayoutToolbarProps = {
  isNetworkView: boolean
  onExport: () => void
  onRegenerateNetwork: () => void
  onSaveNetworkState: () => void
  onToggleNetworkView: () => void
}

function LayoutToolbar({
  isNetworkView,
  onExport,
  onRegenerateNetwork,
  onSaveNetworkState,
  onToggleNetworkView,
}: LayoutToolbarProps) {
  const { t } = useTranslation()

  return (
    <Panel className="editor-toolbar" position="top-right">
      <button
        aria-label={t('treeExport.action')}
        className="editor-tool-button"
        onClick={onExport}
        title={t('treeExport.action')}
        type="button"
      >
        <svg aria-hidden="true" viewBox="0 0 24 24">
          <path d="M12 3v12M7 10l5 5 5-5M5 20h14" />
        </svg>
      </button>
      <button
        aria-label={isNetworkView
          ? t('editor.showTreeView')
          : t('editor.showBloodNetwork')}
        className="editor-tool-button"
        onClick={onToggleNetworkView}
        title={isNetworkView
          ? t('editor.showTreeView')
          : t('editor.showBloodNetwork')}
        type="button"
      >
        {isNetworkView
          ? (
              <svg aria-hidden="true" viewBox="0 0 16 16">
                <path d="M8 2v4M4 14v-3h8v3M4 11V8h8v3" />
                <circle cx="8" cy="2" r="1.25" />
                <circle cx="4" cy="14" r="1.25" />
                <circle cx="12" cy="14" r="1.25" />
              </svg>
            )
          : (
              <svg aria-hidden="true" viewBox="0 0 24 24">
                <path d="M6 7 12 4l6 3M6 7l-2 8m8-11v8m6-5 2 8M4 15l8 5 8-5" />
                <circle cx="6" cy="7" r="2.3" />
                <circle cx="12" cy="4" r="2.3" />
                <circle cx="18" cy="7" r="2.3" />
                <circle cx="4" cy="15" r="2.3" />
                <circle cx="12" cy="20" r="2.3" />
                <circle cx="20" cy="15" r="2.3" />
              </svg>
            )}
      </button>
      {isNetworkView && (
        <>
          <button
            aria-label={t('editor.saveBloodNetworkState')}
            className="editor-tool-button"
            onClick={onSaveNetworkState}
            title={t('editor.saveBloodNetworkState')}
            type="button"
          >
            <svg aria-hidden="true" viewBox="0 0 24 24">
              <path d="M5 4h12l2 2v14H5z" />
              <path d="M8 4v6h8V4M8 18h8v-5H8z" />
            </svg>
          </button>
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
        </>
      )}
    </Panel>
  )
}

type BloodNetworkSidebarProps = {
  activePresetId: BloodNetworkPreset['id']
  canSavePreset: boolean
  forces: BloodNetworkForceSettings
  onForceChange: (key: keyof BloodNetworkForceSettings, value: number) => void
  onPresetChange: (presetId: BloodNetworkPreset['id']) => void
  onPresetDefaultChange: (presetId: BloodNetworkPreset['id']) => void
  onPresetNameChange: (presetId: BloodNetworkPreset['id'], name: string) => void
  onPresetReload: () => void
  onSavePreset: () => void
  presets: BloodNetworkPreset[]
}

type BloodNetworkForceControlProps = {
  forceKey: keyof BloodNetworkForceSettings
  forces: BloodNetworkForceSettings
  label: string
  max: number
  min: number
  onForceChange: BloodNetworkSidebarProps['onForceChange']
  step: number
}

function normalizeBloodNetworkForceSettings(
  forces: Partial<BloodNetworkForceSettings>,
): BloodNetworkForceSettings {
  return {
    ...DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS,
    ...forces,
  }
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

function BloodNetworkSidebar({
  activePresetId,
  canSavePreset,
  forces,
  onForceChange,
  onPresetChange,
  onPresetDefaultChange,
  onPresetNameChange,
  onPresetReload,
  onSavePreset,
  presets,
}: BloodNetworkSidebarProps) {
  const { t } = useTranslation()
  const [presetNameEditing, setPresetNameEditing] = useState(false)
  const activePreset = presets.find((preset) => preset.id === activePresetId)
    ?? presets[0]

  if (!activePreset) {
    return null
  }

  return (
    <aside aria-label={t('editor.bloodNetworkForceControls')} className="blood-network-sidebar">
      <div className="blood-network-force-settings-content">
        <div className="blood-network-preset-controls">
          <div className="blood-network-preset-row">
            <label className="blood-network-preset-field">
              <span>{t('editor.bloodNetworkPreset')}</span>
              <select
                aria-label={t('editor.bloodNetworkPreset')}
                onChange={(event) => {
                  setPresetNameEditing(false)
                  onPresetChange(event.target.value as BloodNetworkPreset['id'])
                }}
                value={activePreset.id}
              >
                {presets.map((preset) => (
                  <option key={preset.id} value={preset.id}>{preset.name}</option>
                ))}
              </select>
            </label>
            <div className="blood-network-preset-actions">
              <button
                aria-label={t('editor.saveBloodNetworkPreset')}
                className="blood-network-preset-action"
                disabled={!canSavePreset}
                onClick={onSavePreset}
                title={t('editor.saveBloodNetworkPreset')}
                type="button"
              >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                  <path d="M5 3h12l3 3v15H5z" />
                  <path d="M8 3v6h8V3M8 21v-7h8v7" />
                </svg>
              </button>
              <button
                aria-label={t('editor.reloadBloodNetworkPreset')}
                className="blood-network-preset-action"
                onClick={onPresetReload}
                title={t('editor.reloadBloodNetworkPreset')}
                type="button"
              >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                  <path d="M19 8a7.5 7.5 0 1 0 1 7" />
                  <path d="M19 4v4h-4" />
                </svg>
              </button>
              <button
                aria-label={t('person.editField', {
                  field: t('editor.bloodNetworkPresetName'),
                })}
                aria-pressed={presetNameEditing}
                className="blood-network-preset-action"
                onClick={() => setPresetNameEditing((isEditing) => !isEditing)}
                title={t('person.editField', {
                  field: t('editor.bloodNetworkPresetName'),
                })}
                type="button"
              >
                <svg aria-hidden="true" viewBox="0 0 24 24">
                  <path d="m4 16.5-.8 4.3 4.3-.8L19 8.5 15.5 5z" />
                  <path d="m13.8 6.7 3.5 3.5" />
                </svg>
              </button>
            </div>
          </div>
          {presetNameEditing && (
            <label className="blood-network-preset-field">
              <span>{t('editor.bloodNetworkPresetName')}</span>
              <input
                aria-label={t('editor.bloodNetworkPresetName')}
                maxLength={80}
                onChange={(event) => onPresetNameChange(activePreset.id, event.target.value)}
                type="text"
                value={activePreset.name}
              />
            </label>
          )}
          <label className="blood-network-preset-default choice-control">
            <input
              aria-label={t('editor.bloodNetworkPresetDefault')}
              checked={activePreset.isDefault}
              onChange={() => onPresetDefaultChange(activePreset.id)}
              type="checkbox"
            />
            <span>{t('editor.bloodNetworkPresetDefault')}</span>
          </label>
        </div>
        <fieldset
          aria-label={t('editor.bloodNetworkForceControls')}
          className="blood-network-force-controls"
        >
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
          forceKey="parentChildPathLength"
          forces={forces}
          label={t('editor.bloodNetworkParentChildPathLength')}
          max={500}
          min={100}
          onForceChange={onForceChange}
          step={1}
        />
        <BloodNetworkForceControl
          forceKey="parentToFamilyLengthRatio"
          forces={forces}
          label={t('editor.bloodNetworkParentToFamilyLengthRatio')}
          max={1}
          min={0}
          onForceChange={onForceChange}
          step={0.01}
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
          forceKey="descendantSideForce"
          forces={forces}
          label={t('editor.bloodNetworkDescendantSideForce')}
          max={24}
          min={0}
          onForceChange={onForceChange}
          step={0.1}
        />
        <BloodNetworkForceControl
          forceKey="personFlowHingeStrength"
          forces={forces}
          label={t('editor.bloodNetworkPersonFlowHinge')}
          max={50}
          min={0}
          onForceChange={onForceChange}
          step={0.1}
        />
        <BloodNetworkForceControl
          forceKey="sharedParentSpringStrength"
          forces={forces}
          label={t('editor.bloodNetworkSharedParentSpring')}
          max={2}
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
          forceKey="baseRepulsionDistanceExponent"
          forces={forces}
          label={t('editor.bloodNetworkRepulsionDistanceExponent')}
          max={3}
          min={1}
          onForceChange={onForceChange}
          step={0.05}
        />
        <BloodNetworkForceControl
          forceKey="edgeNodeCapsuleThickness"
          forces={forces}
          label={t('editor.bloodNetworkEdgeNodeCapsuleThickness')}
          max={100}
          min={0}
          onForceChange={onForceChange}
          step={1}
        />
        <BloodNetworkForceControl
          forceKey="edgeNodeRepulsionStrength"
          forces={forces}
          label={t('editor.bloodNetworkEdgeNodeRepulsion')}
          max={30}
          min={0}
          onForceChange={onForceChange}
          step={0.1}
        />
        <BloodNetworkForceControl
          forceKey="kinshipRepulsionStep"
          forces={forces}
          label={t('editor.bloodNetworkKinshipRepulsion')}
          max={3}
          min={0}
          onForceChange={onForceChange}
          step={0.05}
        />
        <BloodNetworkForceControl
          forceKey="kinshipRepulsionDistanceExponent"
          forces={forces}
          label={t('editor.bloodNetworkKinshipRepulsionDistanceExponent')}
          max={3}
          min={1}
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
      </div>
    </aside>
  )
}

type TreeCanvasProps = {
  bloodNetworkSidebarContainer: HTMLElement | null
  onAddParents: (childId: string) => Promise<void>
  onClearSelection: () => void
  onClosePathDisplay: () => void
  onOpenRelativeDialog: (
    anchorId: string,
    action: RelativeAction,
    fixedParentIds?: readonly [string, string],
    fixedRelativeId?: string,
  ) => void
  onExport: () => void
  onChangeReference: (reference: TreeReference) => Promise<void>
  onSaveBloodNetworkState: (state: BloodNetworkMapState) => Promise<void>
  onShowPathDisplay: (personId: string) => Promise<boolean>
  request: AuthorizedJsonRequest
  pathDisplay: SearchRoutePlan | null
  onSelectPerson: (personId: string) => void
  onToggleNetworkView: () => void
  onUpdateUser: (user: User) => void
  selectedPersonId: string | null
  snapshot: TreeSnapshot
  user: User | null
  view: 'network' | 'tree'
}

function TreeCanvas({
  bloodNetworkSidebarContainer,
  onAddParents,
  onClearSelection,
  onClosePathDisplay,
  onExport,
  onChangeReference,
  onOpenRelativeDialog,
  onSaveBloodNetworkState,
  onShowPathDisplay,
  pathDisplay,
  request,
  onSelectPerson,
  onToggleNetworkView,
  onUpdateUser,
  selectedPersonId,
  snapshot,
  user,
  view,
}: TreeCanvasProps) {
  type EditorFlowNode = PersonFlowNode | BloodNetworkFlowNode
  type EditorFlowEdge = TreeFlowEdge | BloodNetworkFlowEdge
  const { t } = useTranslation()
  const instance = useRef<
    ReactFlowInstance<EditorFlowNode, EditorFlowEdge> | null
  >(null)
  const cameras = useRef<Record<TreeEditorView, Viewport | null>>({
    network: null,
    tree: null,
  })
  const [cameraReady, setCameraReady] = useState(false)
  const activeCameraView = useRef<TreeEditorView>(view)
  const centeredPersonId = useRef<string | null>(null)
  const [simulatedBloodNodes, setSimulatedBloodNodes] = useState<
    BloodNetworkFlowNode[]
  >([])
  const latestBloodNodes = useRef<BloodNetworkFlowNode[]>([])
  const activeBloodNetwork = useRef<{
    edges: BloodNetworkFlowEdge[]
    nodes: BloodNetworkFlowNode[]
  } | null>(null)
  const bloodNetworkSimulation = useRef<BloodNetworkSimulation | null>(null)
  const draggedBloodNodeId = useRef<string | null>(null)
  const bloodNetworkRotationSession = useRef<BloodNetworkRotationSession | null>(null)
  const [bloodNetworkContextMenu, setBloodNetworkContextMenu] = useState<
    BloodNetworkNodeContextMenu | null
  >(null)
  const [treeContextMenu, setTreeContextMenu] = useState<TreeContextMenu | null>(null)
  const [bloodNetworkRotationPivotNodeId, setBloodNetworkRotationPivotNodeId] = useState<
    string | null
  >(null)
  const [bloodNetworkSaveConfirmation, setBloodNetworkSaveConfirmation] = useState(false)
  const savedBloodNetworkPresets = useMemo(() => (
    user?.bloodNetworkPresets.map((preset) => ({
      ...preset,
      forces: normalizeBloodNetworkForceSettings(preset.forces),
    })) ?? defaultBloodNetworkPresets()
  ), [user?.bloodNetworkPresets])
  const [bloodNetworkPresets, setBloodNetworkPresets] = useState<
    BloodNetworkPreset[]
  >(() => savedBloodNetworkPresets.map((preset) => ({
    ...preset,
    forces: { ...preset.forces },
  })))
  const [activeBloodNetworkPresetId, setActiveBloodNetworkPresetId] = useState<
    BloodNetworkPreset['id']
  >(() => bloodNetworkPresets.find((preset) => preset.isDefault)?.id
    ?? bloodNetworkPresets[0]?.id
    ?? 'normal')
  const [bloodNetworkForces, setBloodNetworkForces] = useState(() => ({
    ...(bloodNetworkPresets.find((preset) => preset.isDefault)?.forces
      ?? DEFAULT_BLOOD_NETWORK_FORCE_SETTINGS),
  }))
  const bloodNetworkStopThresholdRef = useRef(
    bloodNetworkForces.averageMovementPerSecondThreshold,
  )
  const resumeBloodNetworkAnimation = useRef<(() => void) | null>(null)
  const bloodNetworkSimulationForces = useMemo(() => ({
    averageMovementPerSecondThreshold: 0,
    baseRepulsion: bloodNetworkForces.baseRepulsion,
    baseRepulsionDistanceExponent: bloodNetworkForces.baseRepulsionDistanceExponent,
    descendantSideForce: bloodNetworkForces.descendantSideForce,
    edgeNodeCapsuleThickness: bloodNetworkForces.edgeNodeCapsuleThickness,
    edgeNodeRepulsionStrength: bloodNetworkForces.edgeNodeRepulsionStrength,
    kinshipRepulsionStep: bloodNetworkForces.kinshipRepulsionStep,
    kinshipRepulsionDistanceExponent: bloodNetworkForces.kinshipRepulsionDistanceExponent,
    parentHingeSpringStrength: bloodNetworkForces.parentHingeSpringStrength,
    parentHingeTargetAngle: bloodNetworkForces.parentHingeTargetAngle,
    parentChildPathLength: bloodNetworkForces.parentChildPathLength,
    parentPairSpringStrength: bloodNetworkForces.parentPairSpringStrength,
    parentToFamilyLengthRatio: bloodNetworkForces.parentToFamilyLengthRatio,
    personFlowHingeStrength: bloodNetworkForces.personFlowHingeStrength,
    sharedParentSpringStrength: bloodNetworkForces.sharedParentSpringStrength,
    unrelatedParentNodeRepulsion: bloodNetworkForces.unrelatedParentNodeRepulsion,
  }), [
    bloodNetworkForces.baseRepulsion,
    bloodNetworkForces.baseRepulsionDistanceExponent,
    bloodNetworkForces.descendantSideForce,
    bloodNetworkForces.edgeNodeCapsuleThickness,
    bloodNetworkForces.edgeNodeRepulsionStrength,
    bloodNetworkForces.kinshipRepulsionStep,
    bloodNetworkForces.kinshipRepulsionDistanceExponent,
    bloodNetworkForces.parentHingeSpringStrength,
    bloodNetworkForces.parentHingeTargetAngle,
    bloodNetworkForces.parentChildPathLength,
    bloodNetworkForces.parentPairSpringStrength,
    bloodNetworkForces.parentToFamilyLengthRatio,
    bloodNetworkForces.personFlowHingeStrength,
    bloodNetworkForces.sharedParentSpringStrength,
    bloodNetworkForces.unrelatedParentNodeRepulsion,
  ])
  const [bloodNetworkMotionRevision, setBloodNetworkMotionRevision] = useState(0)
  const [networkGeneration, setNetworkGeneration] = useState(0)
  const focusedSnapshot = useMemo(() => focusedTreeSnapshot(snapshot), [snapshot])
  const treePositions = useMemo(() => layoutTree(focusedSnapshot), [focusedSnapshot])
  const pathProjection = useMemo(() => pathDisplay
    ? projectSearchRoute(pathDisplay, focusedSnapshot.people, treePositions)
    : null, [focusedSnapshot.people, pathDisplay, treePositions])
  const referencePersonIds = useMemo(() => {
    const reference = effectiveReference(snapshot)
    return new Set(reference?.type === 'person'
      ? [reference.personId]
      : reference?.personIds ?? [])
  }, [snapshot])
  const treeNodes = useMemo<PersonFlowNode[]>(() => focusedSnapshot.people.map((person) => ({
    id: person.id,
    type: 'person',
    position: treePositions.get(person.id) ?? { x: 0, y: 0 },
    height: PERSON_CARD_HEIGHT,
    width: PERSON_CARD_WIDTH,
    data: {
      isMuted: Boolean(
        pathDisplay
        && person.id !== selectedPersonId
        && !pathProjection?.routePersonIds.has(person.id),
      ),
      isReference: referencePersonIds.has(person.id),
      person,
    },
    selected: person.id === selectedPersonId,
  })), [focusedSnapshot.people, pathDisplay, pathProjection?.routePersonIds, referencePersonIds, selectedPersonId, treePositions])
  const pathNodes = useMemo<PersonFlowNode[]>(() => pathProjection?.overlayPeople.map((person) => ({
    id: person.id,
    type: 'person',
    position: pathProjection.positions.get(person.id) ?? { x: 0, y: 0 },
    height: PERSON_CARD_HEIGHT,
    width: PERSON_CARD_WIDTH,
    data: { isOverlay: true, isReference: false, person },
    selected: person.id === selectedPersonId,
  })) ?? [], [pathProjection, selectedPersonId])
  const edges = useMemo<TreeFlowEdge[]>(() => {
    const base = buildTreeEdges(focusedSnapshot, treePositions).map((edge) => (
      edge.type === 'family'
        ? {
            ...edge,
            data: { ...edge.data, ...(pathDisplay ? { visualState: 'muted' as const } : {}) },
          }
        : {
            ...edge,
            data: { ...edge.data, ...(pathDisplay ? { visualState: 'muted' as const } : {}) },
          }
    )) as TreeFlowEdge[]
    if (!pathDisplay || !pathProjection) return base

    const route = buildTreeEdges({
      tree: snapshot.tree,
      parentLinks: pathDisplay.parentLinks,
      marriages: pathDisplay.marriages,
    }, pathProjection.positions).map((edge) => (
      edge.type === 'family'
        ? {
            ...edge,
            id: `route:${edge.id}`,
            data: { ...edge.data, visualState: 'highlight' as const },
          }
        : {
            ...edge,
            id: `route:${edge.id}`,
            data: { ...edge.data, visualState: 'highlight' as const },
          }
    )) as TreeFlowEdge[]

    return [...base, ...route]
  }, [focusedSnapshot, pathDisplay, pathProjection, snapshot.tree, treePositions])
  const searchPeople = useCallback((query: string, signal: AbortSignal) => (
    personApi.search(request, snapshot.tree.id, query, signal)
      .then((result) => result.items)
  ), [request, snapshot.tree.id])
  const selectSearchResult = useCallback(async (person: PersonSearchResult) => {
    if (focusedSnapshot.people.some((item) => item.id === person.id)) {
      onSelectPerson(person.id)
      return
    }
    if (await onShowPathDisplay(person.id)) {
      onSelectPerson(person.id)
    }
  }, [focusedSnapshot.people, onSelectPerson, onShowPathDisplay])
  const generatedBloodNetwork = useMemo(
    () => view === 'network'
      ? buildBloodNetwork(snapshot, networkGeneration)
      : { edges: [], nodes: [] },
    [networkGeneration, snapshot, view],
  )
  const isSavedBloodNetworkStateCompatible = useMemo(() => {
    const state = snapshot.bloodNetworkState
    if (!state || networkGeneration !== 0) {
      return false
    }
    const savedIds = new Set(state.nodes.map((node) => node.id))
    return savedIds.size === generatedBloodNetwork.nodes.length
      && generatedBloodNetwork.nodes.every((node) => savedIds.has(node.id))
  }, [generatedBloodNetwork.nodes, networkGeneration, snapshot.bloodNetworkState])
  const bloodNetwork = useMemo(() => (
    isSavedBloodNetworkStateCompatible
      ? restoreBloodNetworkNodePositions(generatedBloodNetwork, snapshot.bloodNetworkState)
      : generatedBloodNetwork
  ), [generatedBloodNetwork, isSavedBloodNetworkStateCompatible, snapshot.bloodNetworkState])
  const activeBloodNodes = view === 'network'
    ? (simulatedBloodNodes.length > 0 || bloodNetwork.nodes.length === 0
      ? simulatedBloodNodes
      : bloodNetwork.nodes)
    : []
  const nodes: EditorFlowNode[] = view === 'network'
    ? activeBloodNodes.map((node) => ({
        ...node,
        selected: node.id === bloodNetworkRotationPivotNodeId,
      }))
    : [...treeNodes, ...pathNodes]
  const activeEdges: EditorFlowEdge[] = view === 'network' ? [] : edges
  const treeMenuSpouses = useMemo(() => {
    if (treeContextMenu?.kind !== 'person' || !treeContextMenu.personId) return []
    const spouseIds = new Set<string>()
    for (const marriage of snapshot.marriages) {
      if (marriage.malePersonId === treeContextMenu.personId) {
        spouseIds.add(marriage.femalePersonId)
      } else if (marriage.femalePersonId === treeContextMenu.personId) {
        spouseIds.add(marriage.malePersonId)
      }
    }
    return snapshot.people
      .filter((person) => spouseIds.has(person.id))
      .sort((left, right) => left.id.localeCompare(right.id))
  }, [snapshot.marriages, snapshot.people, treeContextMenu])
  const treeMenuPerson = useMemo(() => (
    treeContextMenu?.kind === 'person' && treeContextMenu.personId
      ? snapshot.people.find((person) => person.id === treeContextMenu.personId) ?? null
      : null
  ), [snapshot.people, treeContextMenu])
  const treeMenuParentRoles = useMemo(() => new Set(
    treeMenuPerson
      ? snapshot.parentLinks
        .filter((link) => link.childId === treeMenuPerson.id)
        .map((link) => link.role)
      : [],
  ), [snapshot.parentLinks, treeMenuPerson])
  const isReferenceMarriage = (personIds: readonly [string, string]) => {
    const reference = snapshot.tree.reference
    return reference?.type === 'marriage'
      && personIds.every((personId) => reference.personIds.includes(personId))
  }

  useEffect(() => {
    const presets = savedBloodNetworkPresets.map((preset) => ({
      ...preset,
      forces: { ...preset.forces },
    }))
    const defaultPreset = presets.find((preset) => preset.isDefault) ?? presets[0]
    if (!defaultPreset) {
      return
    }
    setBloodNetworkPresets(presets)
    setActiveBloodNetworkPresetId(defaultPreset.id)
    setBloodNetworkForces({ ...defaultPreset.forces })
  }, [savedBloodNetworkPresets])

  useEffect(() => {
    if (view !== 'network' || !snapshot.bloodNetworkState) {
      return
    }
    setBloodNetworkForces(normalizeBloodNetworkForceSettings(snapshot.bloodNetworkState.forces))
  }, [snapshot.bloodNetworkState, view])

  useEffect(() => {
    bloodNetworkStopThresholdRef.current = bloodNetworkForces.averageMovementPerSecondThreshold
    resumeBloodNetworkAnimation.current?.()
  }, [bloodNetworkForces.averageMovementPerSecondThreshold])

  const bloodNetworkPresetsChanged = useMemo(() => haveBloodNetworkPresetsChanged(
    bloodNetworkPresets,
    savedBloodNetworkPresets,
  ), [bloodNetworkPresets, savedBloodNetworkPresets])

  useEffect(() => {
    if (view === 'network') {
      setTreeContextMenu(null)
      return
    }
    bloodNetworkRotationSession.current = null
    setBloodNetworkContextMenu(null)
    setBloodNetworkRotationPivotNodeId(null)
  }, [view])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') {
        return
      }
      setBloodNetworkContextMenu(null)
      setTreeContextMenu(null)
      if (!bloodNetworkRotationPivotNodeId) {
        return
      }
      event.preventDefault()
      bloodNetworkRotationSession.current = null
      setBloodNetworkRotationPivotNodeId(null)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [bloodNetworkRotationPivotNodeId])

  const rememberCamera = useCallback((
    cameraView: TreeEditorView,
    viewport: Viewport,
  ) => {
    cameras.current[cameraView] = { ...viewport }
  }, [cameras])

  useEffect(() => {
    if (!selectedPersonId) {
      centeredPersonId.current = null
      return
    }
    if (
      view !== 'tree'
      || !cameraReady
      || centeredPersonId.current === selectedPersonId
    ) {
      return
    }
    const position = pathProjection?.positions.get(selectedPersonId)
      ?? treePositions.get(selectedPersonId)
    const flow = instance.current
    if (!position || !flow) {
      return
    }
    const frame = requestAnimationFrame(() => {
      centeredPersonId.current = selectedPersonId
      void flow.setCenter(
        position.x + PERSON_CARD_WIDTH / 2,
        position.y + PERSON_CARD_HEIGHT / 2,
        { duration: 150, zoom: flow.getViewport().zoom },
      ).then(() => rememberCamera('tree', flow.getViewport()))
    })
    return () => cancelAnimationFrame(frame)
  }, [cameraReady, pathProjection?.positions, rememberCamera, selectedPersonId, treePositions, view])

  const resetCamera = useCallback(async (
    cameraView: TreeEditorView = view,
    flow = instance.current,
    duration = 150,
  ) => {
    if (!flow) {
      return
    }
    if (cameraView === 'tree') {
      await flow.setCenter(0, 0, { duration, zoom: 1 })
    } else if (snapshot.bloodNetworkState) {
      await flow.setViewport(snapshot.bloodNetworkState.viewport, { duration })
    } else if (bloodNetwork.nodes.length > 0) {
      await flow.fitBounds(bloodNetworkBounds(bloodNetwork.nodes), {
        duration,
        padding: 0.2,
      })
    } else {
      await flow.setViewport({ x: 0, y: 0, zoom: 1 }, { duration })
    }
    rememberCamera(cameraView, flow.getViewport())
  }, [bloodNetwork.nodes, rememberCamera, snapshot.bloodNetworkState, view])
  const referenceKey = JSON.stringify(snapshot.tree.reference)
  const previousReferenceKey = useRef(referenceKey)

  useEffect(() => {
    if (previousReferenceKey.current === referenceKey) return
    previousReferenceKey.current = referenceKey
    if (view === 'tree') void resetCamera('tree')
  }, [referenceKey, resetCamera, view])

  const restoreCamera = useCallback(async (
    flow: ReactFlowInstance<EditorFlowNode, EditorFlowEdge>,
    cameraView: TreeEditorView,
  ) => {
    const remembered = cameras.current[cameraView]
    if (remembered) {
      await flow.setViewport(remembered, { duration: 0 })
      return
    }
    await resetCamera(cameraView, flow, 0)
  }, [cameras, resetCamera])

  useLayoutEffect(() => {
    const previousView = activeCameraView.current
    if (previousView === view) {
      return
    }
    const flow = instance.current
    if (flow) {
      rememberCamera(previousView, flow.getViewport())
    }
    activeCameraView.current = view
    if (flow) {
      void restoreCamera(flow, view)
    }
  }, [rememberCamera, restoreCamera, view])

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
    }, bloodNetworkSimulationForces)
    if (draggedBloodNodeId.current) {
      simulation.setNodeDragging(draggedBloodNodeId.current, true)
    }
    bloodNetworkSimulation.current = simulation
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
      if (cameras.current.network) {
        void instance.current?.setViewport(cameras.current.network, { duration: 0 })
      } else if (isSavedBloodNetworkStateCompatible && snapshot.bloodNetworkState) {
        void instance.current?.setViewport(snapshot.bloodNetworkState.viewport, {
          duration: 0,
        })
      } else {
        void instance.current?.fitBounds(bloodNetworkBounds(initialNodes), {
          duration: 0,
          padding: 0.2,
        })
      }
    }

    if (bloodNetworkRotationPivotNodeId) {
      return () => {
        if (bloodNetworkSimulation.current === simulation) {
          bloodNetworkSimulation.current = null
        }
      }
    }

    let animationScheduled = false
    const scheduleAnimation = () => {
      if (!active || animationScheduled) {
        return
      }
      animationScheduled = true
      animationTimer = window.setTimeout(() => {
        animationScheduled = false
        animate()
      }, 16)
    }
    function animate() {
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
        && averageMovementPerSecond > bloodNetworkStopThresholdRef.current
      ) {
        scheduleAnimation()
      }
    }
    resumeBloodNetworkAnimation.current = () => {
      previousFrameTimestamp = performance.now()
      scheduleAnimation()
    }
    scheduleAnimation()

    return () => {
      active = false
      resumeBloodNetworkAnimation.current = null
      if (bloodNetworkSimulation.current === simulation) {
        bloodNetworkSimulation.current = null
      }
      if (animationTimer !== null) {
        window.clearTimeout(animationTimer)
      }
    }
  }, [
    bloodNetwork,
    bloodNetworkSimulationForces,
    bloodNetworkMotionRevision,
    bloodNetworkRotationPivotNodeId,
    cameras,
    isSavedBloodNetworkStateCompatible,
    snapshot.bloodNetworkState,
    view,
  ])

  const saveBloodNetworkState = async () => {
    const viewport = instance.current?.getViewport()
    if (!viewport || latestBloodNodes.current.length === 0) {
      throw new Error('Blood network state is unavailable')
    }
    await onSaveBloodNetworkState({
      forces: { ...bloodNetworkForces },
      nodes: latestBloodNodes.current.map((node) => ({
        coordinates: { ...node.position },
        id: node.id,
      })),
      viewport,
    })
  }

  const requestBloodNetworkSave = () => {
    if (snapshot.bloodNetworkState) {
      setBloodNetworkSaveConfirmation(true)
      return
    }
    void saveBloodNetworkState()
  }

  const pathDisplayTargetName = pathDisplay
    ? (() => {
        const target = pathDisplay.people.find((person) => person.id === pathDisplay.targetPersonId)
        return target ? formatPersonName(target, t) : t('person.unknown')
      })()
    : null

  return (
    <>
    <div
      aria-busy={!cameraReady}
      className={`blood-network-canvas${cameraReady ? '' : ' editor-camera-pending'}${bloodNetworkRotationPivotNodeId
        ? ' blood-network-rotation-mode'
        : ''}`}
      onPointerDownCapture={(event) => {
        if (event.button !== 0 || !bloodNetworkRotationPivotNodeId) {
          return
        }
        event.preventDefault()
        event.stopPropagation()
        const pivot = latestBloodNodes.current.find((node) => (
          node.id === bloodNetworkRotationPivotNodeId
        ))
        const flow = instance.current
        if (!pivot || !flow) {
          return
        }
        const pointer = flow.screenToFlowPosition({ x: event.clientX, y: event.clientY })
        const pivotCenter = bloodNetworkNodeCenter(pivot)
        if (Math.hypot(pointer.x - pivotCenter.x, pointer.y - pivotCenter.y) < 1) {
          return
        }
        bloodNetworkRotationSession.current = {
          initialAngle: Math.atan2(pointer.y - pivotCenter.y, pointer.x - pivotCenter.x),
          nodes: latestBloodNodes.current,
          pivot: pivotCenter,
          pivotNodeId: bloodNetworkRotationPivotNodeId,
        }
        event.currentTarget.setPointerCapture(event.pointerId)
      }}
      onPointerMove={(event) => {
        const session = bloodNetworkRotationSession.current
        if (!session || (event.buttons & 1) === 0) {
          return
        }
        event.preventDefault()
        event.stopPropagation()
        const flow = instance.current
        if (!flow) {
          return
        }
        const pointer = flow.screenToFlowPosition({ x: event.clientX, y: event.clientY })
        const angle = Math.atan2(pointer.y - session.pivot.y, pointer.x - session.pivot.x)
          - session.initialAngle
        const rotatedNodes = rotateBloodNetworkNodes(
          session.nodes,
          session.pivotNodeId,
          angle,
        )
        latestBloodNodes.current = rotatedNodes
        setSimulatedBloodNodes(rotatedNodes)
      }}
      onPointerUpCapture={(event) => {
        if (event.button !== 0 || !bloodNetworkRotationSession.current) {
          return
        }
        event.preventDefault()
        event.stopPropagation()
        bloodNetworkRotationSession.current = null
        event.currentTarget.releasePointerCapture(event.pointerId)
      }}
    >
    <ReactFlow<EditorFlowNode, EditorFlowEdge>
      defaultViewport={{ x: 0, y: 0, zoom: 1 }}
      edges={activeEdges}
      edgeTypes={edgeTypes}
      elementsSelectable={view === 'tree' || bloodNetworkRotationPivotNodeId !== null}
      maxZoom={2}
      minZoom={0.1}
      nodeTypes={nodeTypes}
      nodes={nodes}
      nodesConnectable={false}
      nodesDraggable={view === 'network' && bloodNetworkRotationPivotNodeId === null}
      onInit={(flow) => {
        instance.current = flow
        activeCameraView.current = view
        void restoreCamera(flow, view).finally(() => setCameraReady(true))
      }}
      onMoveEnd={(_event, viewport) => rememberCamera(
        activeCameraView.current,
        viewport,
      )}
      onNodeClick={(_event, node) => {
        if (view === 'tree') {
          onSelectPerson(node.id)
        }
      }}
      onNodeContextMenu={(event, node) => {
        event.preventDefault()
        if (view === 'tree') {
          setTreeContextMenu({
            kind: 'person',
            personId: node.id,
            x: event.clientX,
            y: event.clientY,
          })
          return
        }
        setBloodNetworkContextMenu({
          nodeId: node.id,
          x: event.clientX,
          y: event.clientY,
        })
      }}
      onEdgeContextMenu={(event, edge) => {
        if (
          view !== 'tree'
          || edge.type !== 'family'
        ) return
        const data = (edge as FamilyFlowEdge).data
        if (!data?.personIds) return
        event.preventDefault()
        setTreeContextMenu({
          kind: 'family',
          married: data.married,
          personIds: data.personIds,
          x: event.clientX,
          y: event.clientY,
        })
      }}
      onNodeDrag={(_event, node) => {
        if (view !== 'network') {
          return
        }
        bloodNetworkSimulation.current?.moveNode(node.id, node.position)
        const nextNodes = bloodNetworkSimulation.current?.getNodes()
        if (!nextNodes) {
          return
        }
        latestBloodNodes.current = nextNodes
        setSimulatedBloodNodes(nextNodes)
        setBloodNetworkMotionRevision((revision) => revision + 1)
      }}
      onNodeDragStart={(_event, node) => {
        if (view !== 'network') {
          return
        }
        draggedBloodNodeId.current = node.id
        bloodNetworkSimulation.current?.setNodeDragging(node.id, true)
      }}
      onNodeDragStop={(_event, node) => {
        if (view !== 'network') {
          return
        }
        bloodNetworkSimulation.current?.moveNode(node.id, node.position)
        bloodNetworkSimulation.current?.setNodeDragging(node.id, false)
        draggedBloodNodeId.current = null
        const nextNodes = bloodNetworkSimulation.current?.getNodes()
        if (!nextNodes) {
          return
        }
        latestBloodNodes.current = nextNodes
        setSimulatedBloodNodes(nextNodes)
        setBloodNetworkMotionRevision((revision) => revision + 1)
      }}
      onPaneClick={() => {
        setBloodNetworkContextMenu(null)
        setTreeContextMenu(null)
        if (view === 'tree') {
          onClearSelection()
        }
      }}
      onPaneContextMenu={(event) => {
        event.preventDefault()
        setBloodNetworkContextMenu(null)
      }}
      panOnDrag={bloodNetworkRotationPivotNodeId ? [1] : true}
      proOptions={{ hideAttribution: true }}
      zoomOnDoubleClick={false}
      zoomOnPinch
      zoomOnScroll
    >
      {view === 'network' && (
        <BloodNetworkEdgesOverlay
          edges={bloodNetwork.edges}
          nodes={activeBloodNodes}
        />
      )}
      <Background color="var(--color-border)" gap={22} size={1} />
      {view === 'tree' && (
        <Panel position="top-right">
          <PersonSearchPanel
            onSearch={searchPeople}
            onSelect={(person) => void selectSearchResult(person)}
          />
        </Panel>
      )}
      {view === 'tree' && pathDisplay && (
        <Panel className="path-display-panel" position="top-left">
          <span className="path-display-title">
            {t('editor.pathDisplayTitle', {
              name: pathDisplayTargetName,
            })}
          </span>
          <button
            aria-label={t('editor.closePathDisplay')}
            className="editor-tool-button path-display-close"
            onClick={onClosePathDisplay}
            title={t('editor.closePathDisplay')}
            type="button"
          >
            <svg aria-hidden="true" viewBox="0 0 24 24">
              <path d="m7 7 10 10M17 7 7 17" />
            </svg>
          </button>
        </Panel>
      )}
      <LayoutToolbar
        isNetworkView={view === 'network'}
        onExport={onExport}
        onRegenerateNetwork={() => {
          cameras.current.network = null
          setNetworkGeneration((generation) => generation + 1)
        }}
        onSaveNetworkState={requestBloodNetworkSave}
        onToggleNetworkView={onToggleNetworkView}
      />
      {view === 'network' && bloodNetworkSidebarContainer && createPortal(
        <BloodNetworkSidebar
          activePresetId={activeBloodNetworkPresetId}
          canSavePreset={
            bloodNetworkPresetsChanged
            && bloodNetworkPresets.every((preset) => preset.name.trim().length > 0)
          }
          forces={bloodNetworkForces}
          onForceChange={(key, value) => {
            const forces = { ...bloodNetworkForces, [key]: value }
            setBloodNetworkForces(forces)
            setBloodNetworkPresets((presets) => presets.map((preset) => (
              preset.id === activeBloodNetworkPresetId
                ? { ...preset, forces }
                : preset
            )))
          }}
          onPresetChange={(presetId) => {
            const preset = bloodNetworkPresets.find((item) => item.id === presetId)
            if (!preset) {
              return
            }
            setActiveBloodNetworkPresetId(presetId)
            setBloodNetworkForces({ ...preset.forces })
          }}
          onPresetDefaultChange={(presetId) => {
            setBloodNetworkPresets((presets) => presets.map((preset) => ({
              ...preset,
              isDefault: preset.id === presetId,
            })))
          }}
          onPresetNameChange={(presetId, name) => {
            setBloodNetworkPresets((presets) => presets.map((preset) => (
              preset.id === presetId ? { ...preset, name } : preset
            )))
          }}
          onPresetReload={() => {
            const savedPreset = savedBloodNetworkPresets.find((preset) => (
              preset.id === activeBloodNetworkPresetId
            ))
            if (!savedPreset) {
              return
            }
            const forces = { ...savedPreset.forces }
            setBloodNetworkForces(forces)
            setBloodNetworkPresets((presets) => presets.map((preset) => (
              preset.id === savedPreset.id ? { ...preset, forces } : preset
            )))
          }}
          onSavePreset={() => {
            if (!user || !bloodNetworkPresetsChanged) {
              return
            }
            const presets = bloodNetworkPresets.map((preset) => ({
              ...preset,
              name: preset.name.trim(),
            }))
            if (presets.some((preset) => !preset.name)) {
              return
            }
            setBloodNetworkPresets(presets)
            void request<User>('/me/blood-network-presets', {
              method: 'PATCH',
              body: JSON.stringify({ presets }),
            }).then((updatedUser) => {
              onUpdateUser(updatedUser)
            }).catch(() => undefined)
          }}
          presets={bloodNetworkPresets}
        />,
        bloodNetworkSidebarContainer,
      )}
      <ZoomControls onReset={() => void resetCamera()} />
    </ReactFlow>
    </div>
    {treeContextMenu && createPortal(
      <menu
        aria-label={t('editor.personContextMenu')}
        className="blood-network-node-menu tree-context-menu"
        role="menu"
        style={{ left: treeContextMenu.x, top: treeContextMenu.y }}
      >
        {treeContextMenu.kind === 'person' && treeContextMenu.personId && (
          <>
            {treeMenuPerson && !treeMenuParentRoles.has('father')
              && !treeMenuParentRoles.has('mother') && (
                <li>
                  <button
                    onClick={() => {
                      void onAddParents(treeMenuPerson.id).catch(() => undefined)
                      setTreeContextMenu(null)
                    }}
                    role="menuitem"
                    type="button"
                  >
                    {t('relationships.add.parents')}
                  </button>
                </li>
              )}
            {!treeMenuParentRoles.has('father') && (
              <li>
                <button
                  onClick={() => {
                    onOpenRelativeDialog(treeContextMenu.personId!, 'father')
                    setTreeContextMenu(null)
                  }}
                  role="menuitem"
                  type="button"
                >
                  {t('relationships.add.father')}
                </button>
              </li>
            )}
            {!treeMenuParentRoles.has('mother') && (
              <li>
                <button
                  onClick={() => {
                    onOpenRelativeDialog(treeContextMenu.personId!, 'mother')
                    setTreeContextMenu(null)
                  }}
                  role="menuitem"
                  type="button"
                >
                  {t('relationships.add.mother')}
                </button>
              </li>
            )}
            {treeMenuPerson?.gender === 'female' && (
              <li>
                <button
                  onClick={() => {
                    onOpenRelativeDialog(treeMenuPerson.id, 'spouse')
                    setTreeContextMenu(null)
                  }}
                  role="menuitem"
                  type="button"
                >
                  {t('relationships.add.husband')}
                </button>
              </li>
            )}
            {treeMenuPerson?.gender === 'male' && (
              <li>
                <button
                  onClick={() => {
                    onOpenRelativeDialog(treeMenuPerson.id, 'spouse')
                    setTreeContextMenu(null)
                  }}
                  role="menuitem"
                  type="button"
                >
                  {t('relationships.add.wife')}
                </button>
              </li>
            )}
            <li>
              <button
                onClick={() => {
                  onOpenRelativeDialog(treeContextMenu.personId!, 'child')
                  setTreeContextMenu(null)
                }}
                role="menuitem"
                type="button"
              >
                {t('relationships.add.child')}
              </button>
            </li>
            {snapshot.tree.reference?.type !== 'person'
              || snapshot.tree.reference.personId !== treeContextMenu.personId ? (
                <li>
                  <button
                    onClick={() => {
                      void onChangeReference({
                        type: 'person',
                        personId: treeContextMenu.personId!,
                      }).catch(() => undefined)
                      setTreeContextMenu(null)
                    }}
                    role="menuitem"
                    type="button"
                  >
                    {t('editor.setReferencePerson')}
                  </button>
                </li>
              ) : null}
            {treeMenuSpouses.map((spouse) => (
              !isReferenceMarriage([treeContextMenu.personId!, spouse.id]) && (
                <li key={spouse.id}>
                  <button
                    onClick={() => {
                      void onChangeReference({
                        type: 'marriage',
                        personIds: [treeContextMenu.personId!, spouse.id],
                      }).catch(() => undefined)
                      setTreeContextMenu(null)
                    }}
                    role="menuitem"
                    type="button"
                  >
                    {t('editor.setReferenceMarriage', {
                      name: formatPersonName(spouse, t),
                    })}
                  </button>
                </li>
              )
            ))}
          </>
        )}
        {treeContextMenu.kind === 'family' && treeContextMenu.personIds && (
          <>
            <li>
              <button
                onClick={() => {
                  onOpenRelativeDialog(
                    treeContextMenu.personIds![0],
                    'child',
                    treeContextMenu.personIds,
                  )
                  setTreeContextMenu(null)
                }}
                role="menuitem"
                type="button"
              >
                {t('relationships.add.child')}
              </button>
            </li>
            {!treeContextMenu.married && (
              <li>
                <button
                  onClick={() => {
                    onOpenRelativeDialog(
                      treeContextMenu.personIds![0],
                      'spouse',
                      undefined,
                      treeContextMenu.personIds![1],
                    )
                    setTreeContextMenu(null)
                  }}
                  role="menuitem"
                  type="button"
                >
                  {t('relationships.createMarriage')}
                </button>
              </li>
            )}
            {treeContextMenu.married
              && !isReferenceMarriage(treeContextMenu.personIds) && (
                <li>
                  <button
                    onClick={() => {
                      void onChangeReference({
                        type: 'marriage',
                        personIds: treeContextMenu.personIds!,
                      }).catch(() => undefined)
                      setTreeContextMenu(null)
                    }}
                    role="menuitem"
                    type="button"
                  >
                    {t('editor.setReferenceMarriageFromBracket')}
                  </button>
                </li>
              )}
          </>
        )}
      </menu>,
      document.body,
    )}
    {bloodNetworkContextMenu && createPortal(
      <menu
        aria-label={t('editor.bloodNetworkNodeMenu')}
        className="blood-network-node-menu"
        role="menu"
        style={{ left: bloodNetworkContextMenu.x, top: bloodNetworkContextMenu.y }}
      >
        <li>
          <button
            onClick={() => {
              bloodNetworkRotationSession.current = null
              setBloodNetworkRotationPivotNodeId(bloodNetworkContextMenu.nodeId)
              setBloodNetworkContextMenu(null)
            }}
            role="menuitem"
            type="button"
          >
            {t('editor.rotateBloodNetwork')}
          </button>
        </li>
      </menu>,
      document.body,
    )}
    {bloodNetworkSaveConfirmation && (
      <ConfirmRelationshipDialog
        confirmClassName="primary-button"
        confirmLabel={t('common.save')}
        description={t('editor.bloodNetworkSaveChangesDescription')}
        errorMessage={t('editor.bloodNetworkSaveFailed')}
        onCancel={() => setBloodNetworkSaveConfirmation(false)}
        onConfirm={async () => {
          await saveBloodNetworkState()
          setBloodNetworkSaveConfirmation(false)
        }}
        title={t('editor.bloodNetworkSaveChangesTitle')}
      />
    )}
    </>
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

type RelativeDialogState = {
  action: RelativeAction
  anchorId: string
  fixedParentIds?: readonly [string, string]
  fixedRelativeId?: string
  initialSecondParentId?: string
}

type PersonDetailsCacheEntry = {
  details?: PersonDetails
  events: GenealogicalEvent[]
  person: Person
}

function personDetailsCacheKey(personId: string, etag: string) {
  return `${etag}:${personId}`
}

function fallbackPersonDetails(
  snapshot: TreeSnapshot,
  person: Person,
  events: GenealogicalEvent[],
): PersonDetails {
  return {
    etag: snapshot.etag,
    person,
    events,
    relationships: {
      people: snapshot.people,
      parentLinks: snapshot.parentLinks,
      marriages: snapshot.marriages,
    },
    permissions: {
      canEdit: true,
      canDelete: canDeletePerson(snapshot, person.id),
      removableMarriageIds: snapshot.marriages.map((marriage) => marriage.id),
      removableParentLinkIds: snapshot.parentLinks.map((link) => link.id),
    },
  }
}

function isPersonDetails(value: unknown): value is PersonDetails {
  return typeof value === 'object'
    && value !== null
    && 'person' in value
    && 'relationships' in value
    && 'permissions' in value
    && 'events' in value
}

export function TreeEditorPage({ importWarningCount = null, treeId }: TreeEditorPageProps) {
  const { t } = useTranslation()
  const { request, requestBlob, updateUser, user } = useAuth()
  const path = usePath()
  const treeRoute = matchTreeEditorPath(path)
  const isCurrentTreeRoute = treeRoute?.treeId === treeId
  const routeView = isCurrentTreeRoute ? treeRoute.view : null
  const view: TreeEditorView = routeView ?? 'tree'
  const [snapshot, setSnapshot] = useState<TreeSnapshot | null>(null)
  const snapshotRef = useRef<TreeSnapshot | null>(null)
  const personDetailsCache = useRef(new Map<string, PersonDetailsCacheEntry>())
  const [status, setStatus] = useState<'error' | 'loading' | 'missing' | 'ready'>(
    'loading',
  )
  const [placeRecognitionNotice, setPlaceRecognitionNotice] = useState(importWarningCount)

  useEffect(() => {
    setPlaceRecognitionNotice(importWarningCount)
    if (importWarningCount) {
      window.history.replaceState({}, '', window.location.href)
    }
  }, [importWarningCount])
  const [creating, setCreating] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [selectedPerson, setSelectedPerson] = useState<Person | null>(null)
  const [selectedDetails, setSelectedDetails] = useState<PersonDetails | null>(null)
  const [pathDisplay, setPathDisplay] = useState<SearchRoutePlan | null>(null)
  const [events, setEvents] = useState<GenealogicalEvent[]>([])
  const [conflict, setConflict] = useState(false)
  const [relativeDialog, setRelativeDialog] = useState<RelativeDialogState | null>(
    null,
  )
  const [parentsDialogChildId, setParentsDialogChildId] = useState<string | null>(
    null,
  )
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
  const [placeEditing, setPlaceEditing] = useState<GenealogicalEvent | null>(
    null,
  )
  const [photoEditing, setPhotoEditing] = useState(false)
  const [photoDeleting, setPhotoDeleting] = useState(false)
  const [photoViewing, setPhotoViewing] = useState(false)
  const [bloodNetworkSidebarContainer, setBloodNetworkSidebarContainer] = useState<
    HTMLElement | null
  >(null)

  useEffect(() => {
    if (isCurrentTreeRoute && treeRoute.view === null) {
      navigate(treeEditorPath(treeId, 'tree'), { replace: true })
    }
  }, [isCurrentTreeRoute, treeId, treeRoute?.view])

  const closePersonDetails = useCallback(() => {
    setSelectedPerson(null)
    setSelectedDetails(null)
    setEvents([])
    setEventEditing(null)
    setEventPendingDeletion(null)
    setPlaceEditing(null)
    setPhotoEditing(false)
    setPhotoDeleting(false)
    setPhotoViewing(false)
  }, [])

  const closePathDisplay = useCallback(() => {
    if (
      pathDisplay
      && selectedPerson
      && snapshot
      && !focusedTreeSnapshot(snapshot).people.some((person) => person.id === selectedPerson.id)
    ) {
      closePersonDetails()
    }
    setPathDisplay(null)
  }, [closePersonDetails, pathDisplay, selectedPerson, snapshot])

  const replaceSnapshot = useCallback((
    nextSnapshot: TreeSnapshot,
    preserveIfUnchanged = false,
  ) => {
    if (preserveIfUnchanged && snapshotRef.current?.etag === nextSnapshot.etag) {
      return false
    }

    snapshotRef.current = nextSnapshot
    personDetailsCache.current.clear()
    setPathDisplay(null)
    setSnapshot(nextSnapshot)

    return true
  }, [])

  const cachePersonDetails = useCallback((
    etag: string,
    person: Person,
    personEvents: GenealogicalEvent[],
    details?: PersonDetails,
  ) => {
    personDetailsCache.current.set(personDetailsCacheKey(person.id, etag), {
      ...(details ? { details } : {}),
      events: personEvents,
      person,
    })
  }, [])

  const toggleNetworkView = () => {
    if (view === 'network') {
      navigate(treeEditorPath(treeId, 'tree'))
      return
    }
    closePersonDetails()
    navigate(treeEditorPath(treeId, 'network'))
  }

  const loadSnapshot = useCallback(async (resetUi = false) => {
    if (!snapshotRef.current) {
      setStatus('loading')
    }
    try {
      const loaded = await treeApi.snapshot(request, treeId)
      const changed = replaceSnapshot(loaded, true)
      setConflict(false)
      if (changed || resetUi) {
        setSelectedPerson(null)
        setEvents([])
        setRelativeDialog(null)
        setParentsDialogChildId(null)
        setMarriageEditing(null)
        setPendingRemoval(null)
        setEventEditing(null)
        setEventPendingDeletion(null)
        setPlaceEditing(null)
        setPhotoEditing(false)
        setPhotoDeleting(false)
        setPhotoViewing(false)
      }
      setStatus('ready')
    } catch (error) {
      setStatus(
        error instanceof ApiRequestError && error.status === 404
          ? 'missing'
          : 'error',
      )
    }
  }, [replaceSnapshot, request, treeId])

  useEffect(() => {
    if (conflict) {
      setPathDisplay(null)
    }
  }, [conflict])

  const showPathDisplay = useCallback(async (personId: string) => {
    if (!snapshot || conflict) return false
    try {
      const route = await personApi.routeToReference(request, treeId, personId)
      if (route.etag !== snapshot.etag) {
        setPathDisplay(null)
        await loadSnapshot(true)
        return false
      }
      setPathDisplay(route)

      return true
    } catch (error) {
      if (error instanceof ApiRequestError && error.body.code === 'TREE_VERSION_CONFLICT') {
        setConflict(true)
      }

      return false
    }
  }, [conflict, loadSnapshot, request, snapshot, treeId])

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
      )
      replaceSnapshot(updated)
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
    if (!snapshot) {
      return
    }
    const cacheKey = personDetailsCacheKey(personId, snapshot.etag)
    const cached = personDetailsCache.current.get(cacheKey)
    if (cached?.details) {
      setSelectedPerson(cached.person)
      setEvents(cached.events)
      setSelectedDetails(cached.details)
      return
    }

    const person = snapshot.people.find((item) => item.id === personId)
    const openSnapshotDetails = async () => {
      if (!person) return
      const timeline = await personApi.events(request, treeId, personId)
      const fallback = fallbackPersonDetails(snapshot, person, timeline.items)
      cachePersonDetails(snapshot.etag, person, timeline.items, fallback)
      setSelectedPerson(person)
      setEvents(timeline.items)
      setSelectedDetails(fallback)
    }

    try {
      const details = await personApi.details(request, treeId, personId)
      if (isPersonDetails(details)) {
        cachePersonDetails(details.etag, details.person, details.events, details)
        setSelectedPerson(details.person)
        setEvents(details.events)
        setSelectedDetails(details)
        return
      }
      await openSnapshotDetails()
    } catch (error) {
      if (
        error instanceof ApiRequestError
        && error.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setConflict(true)
        return
      }
      await openSnapshotDetails().catch(() => undefined)
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
      const timeline = await personApi.events(
        request,
        treeId,
        selectedPerson.id,
      )
      setSelectedPerson(result.person)
      replaceSnapshot({
        ...snapshot,
        etag: result.etag,
        people: snapshot.people.map((person) => (
          person.id === result.person.id ? result.person : person
        )),
      })
      setEvents(timeline.items)
      setSelectedDetails((current) => (
        current?.person.id === result.person.id
          ? {
              ...current,
              etag: result.etag,
              events: timeline.items,
              person: result.person,
            }
          : current
      ))
      cachePersonDetails(result.etag, result.person, timeline.items)
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
      replaceSnapshot(updated)
      const selectedId = selectedPerson?.id
      const nextSelected = selectedId
        ? updated.people.find((person) => person.id === selectedId) ?? null
        : null
      setSelectedPerson(nextSelected)
      if (nextSelected) {
        const timeline = await personApi.events(
          request,
          treeId,
          nextSelected.id,
        )
        setEvents(timeline.items)
        const nextDetails = fallbackPersonDetails(updated, nextSelected, timeline.items)
        setSelectedDetails(nextDetails)
        cachePersonDetails(updated.etag, nextSelected, timeline.items, nextDetails)
      } else {
        setSelectedDetails(null)
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

  const saveBloodNetworkState = async (state: BloodNetworkMapState) => {
    if (!snapshot || conflict) {
      throw new Error('Blood network state is unavailable')
    }
    try {
      const updated = await treeApi.saveBloodNetworkState(
        request,
        treeId,
        snapshot.etag,
        state,
      )
      replaceSnapshot(updated)
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

  const changeReference = async (reference: TreeReference) => {
    if (!snapshot || conflict) return
    try {
      const updated = await treeApi.changeReference(
        request,
        treeId,
        snapshot.etag,
        reference,
      )
      replaceSnapshot(updated)
      if (
        selectedPerson
        && !focusedTreeSnapshot(updated).people.some((person) => person.id === selectedPerson.id)
      ) {
        closePersonDetails()
      }
    } catch (error) {
      if (error instanceof ApiRequestError && error.body.code === 'TREE_VERSION_CONFLICT') {
        setConflict(true)
      }
      throw error
    }
  }

  const addRelative = async (
    relative: RelativePerson,
    options: RelativeOptions,
  ) => {
    if (!snapshot || !relativeDialog) return
    const anchor = snapshot.people.find((person) => person.id === relativeDialog.anchorId)
    if (!anchor) return
    const parents: ParentSpecification[] = relativeDialog.action === 'child'
      ? relativeDialog.fixedParentIds
        ? relativeDialog.fixedParentIds.map((personId) => {
            const person = snapshot.people.find((item) => item.id === personId)
            if (!person || person.gender === 'unknown') {
              throw new Error('Family parents must have known genders')
            }

            return {
              personId,
              role: person.gender === 'male' ? 'father' : 'mother',
            }
          })
        : [{
            personId: anchor.id,
            role: options.parentRole,
          }, ...(options.secondParentId ? [{
            personId: options.secondParentId,
            role: (options.parentRole === 'father' ? 'mother' : 'father') as ParentRole,
          }] : [])]
      : []
    const mutation: GraphMutation = relativeDialog.action === 'father'
      || relativeDialog.action === 'mother'
      ? {
          type: 'add_parent',
          childId: anchor.id,
          role: relativeDialog.action,
          relative,
        }
      : relativeDialog.action === 'child'
        ? { type: 'add_child', parents, relative }
        : {
            type: 'add_marriage_period',
            personId: anchor.id,
            relative,
            marriageDate: options.marriageDate,
            dissolutionDate: options.dissolutionDate,
            marriagePlace: options.marriagePlace,
            dissolutionPlace: options.dissolutionPlace,
            maleNewSurname: options.maleNewSurname,
            femaleNewSurname: options.femaleNewSurname,
          }
    await applyMutation(mutation)
    setRelativeDialog(null)
  }

  const addParents = async (childId: string) => {
    setParentsDialogChildId(childId)
  }

  const saveParents = async (value: ParentsFormValue) => {
    if (!parentsDialogChildId) {
      throw new Error('Parent creation is unavailable')
    }
    await applyMutation({
      type: 'add_parents',
      childId: parentsDialogChildId,
      ...value,
    })
    setParentsDialogChildId(null)
  }

  const openRelativeDialog = (
    anchorId: string,
    action: RelativeAction,
    fixedParentIds?: readonly [string, string],
    fixedRelativeId?: string,
    initialSecondParentId?: string,
  ) => {
    setRelativeDialog({
      action,
      anchorId,
      ...(fixedParentIds ? { fixedParentIds } : {}),
      ...(fixedRelativeId ? { fixedRelativeId } : {}),
      ...(initialSecondParentId ? { initialSecondParentId } : {}),
    })
  }

  const confirmRemoval = async () => {
    if (!pendingRemoval) return
    const mutation: GraphMutation = pendingRemoval.kind === 'parent'
      ? { type: 'remove_parent_link', parentLinkId: pendingRemoval.link.id }
      : pendingRemoval.kind === 'marriage'
        ? { type: 'remove_marriage_period', marriageId: pendingRemoval.marriage.id }
        : { type: 'delete_person', personId: pendingRemoval.person.id }
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
      replaceSnapshot({
        ...snapshot,
        etag: result.etag,
        marriages: snapshot.marriages.map((marriage) => (
          marriage.id === result.marriage.id ? result.marriage : marriage
        )),
      })
      if (selectedPerson) {
        const timeline = await personApi.events(
          request,
          treeId,
          selectedPerson.id,
        )
        setEvents(timeline.items)
        cachePersonDetails(result.etag, selectedPerson, timeline.items)
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
      replaceSnapshot({ ...snapshot, etag: result.etag })
      cachePersonDetails(result.etag, selectedPerson, timeline.items)
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
      replaceSnapshot(updated)
      const nextSelected = updated.people.find((person) => person.id === selectedPerson.id)
        ?? null
      setSelectedPerson(nextSelected)
      setEvents(timeline.items)
      if (nextSelected) {
        cachePersonDetails(updated.etag, nextSelected, timeline.items)
      }
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

  const refreshAfterPlaceMutation = async () => {
    if (!selectedPerson) return
    const [updated, timeline] = await Promise.all([
      treeApi.snapshot(request, treeId),
      personApi.events(request, treeId, selectedPerson.id),
    ])
    replaceSnapshot(updated)
    if (marriageEditing) {
      setMarriageEditing(
        updated.marriages.find((item) => item.id === marriageEditing.id)
          ?? null,
      )
    }
    const nextSelected = updated.people.find((person) => person.id === selectedPerson.id)
      ?? null
    setSelectedPerson(nextSelected)
    setEvents(timeline.items)
    if (nextSelected) {
      cachePersonDetails(updated.etag, nextSelected, timeline.items)
    }
    setPlaceEditing(null)
  }

  const saveEventPlace = async (place: ResolvedEventPlace) => {
    if (!snapshot || !placeEditing || conflict) {
      throw new Error('Event place mutation is unavailable')
    }
    try {
      await eventApi.savePlace(
        request,
        treeId,
        placeEditing.id,
        snapshot.etag,
        place,
      )
      await refreshAfterPlaceMutation()
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

  const deleteEventPlace = async () => {
    if (!snapshot || !placeEditing || conflict) {
      throw new Error('Event place deletion is unavailable')
    }
    try {
      await eventApi.removePlace(
        request,
        treeId,
        placeEditing.id,
        snapshot.etag,
      )
      await refreshAfterPlaceMutation()
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
      replaceSnapshot({ ...snapshot, etag: result.etag })
      cachePersonDetails(result.etag, selectedPerson, result.items)
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
    replaceSnapshot({
      ...snapshot,
      etag: result.etag,
      people: snapshot.people.map((person) => (
        person.id === result.person.id ? result.person : person
      )),
    })
    cachePersonDetails(result.etag, result.person, events)
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
      {placeRecognitionNotice && (
        <ToastNotice
          closeLabel={t('common.close')}
          message={t('treeImport.warning.place_recognition_incomplete', { count: placeRecognitionNotice })}
          onClose={() => setPlaceRecognitionNotice(null)}
        />
      )}
      <EditorBreadcrumbs
        status={status}
        treeName={snapshot?.tree.name}
      />

      {conflict && (
        <div className="version-conflict-banner" role="alert">
          <span>{t('editor.versionConflict')}</span>
          <button
            className="secondary-button compact-button"
            onClick={() => void loadSnapshot(true)}
            type="button"
          >
            {t('editor.reload')}
          </button>
        </div>
      )}

      <section className={`editor-workspace${
        view === 'network'
          ? ' editor-workspace-network-sidebar-open'
          : selectedPerson ? ' editor-workspace-panel-open' : ''
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
                  bloodNetworkSidebarContainer={bloodNetworkSidebarContainer}
                  onAddParents={addParents}
                  onClearSelection={closePersonDetails}
                  onClosePathDisplay={closePathDisplay}
                  onExport={() => setExporting(true)}
                  onChangeReference={changeReference}
                  onOpenRelativeDialog={openRelativeDialog}
                  onSaveBloodNetworkState={saveBloodNetworkState}
                  onShowPathDisplay={showPathDisplay}
                  onSelectPerson={(personId) => void selectPerson(personId)}
                  selectedPersonId={selectedPerson?.id ?? null}
                  snapshot={snapshot}
                  view={view}
                  onToggleNetworkView={toggleNetworkView}
                  onUpdateUser={updateUser}
                  request={request}
                  pathDisplay={pathDisplay}
                  user={user}
                />
              </ReactFlowProvider>
            )}
          </div>
        )}

        {view === 'tree' && selectedPerson && selectedDetails && (
          <PersonDetailsPanel
            conflict={conflict}
            events={events}
            onAddRelative={(action, secondParentId) => {
              openRelativeDialog(
                selectedPerson.id,
                action,
                undefined,
                undefined,
                secondParentId,
              )
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
            onEditEventPlace={setPlaceEditing}
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
            onSelectPerson={(personId) => void selectPerson(personId)}
            onUploadPhoto={() => setPhotoEditing(true)}
            person={selectedPerson}
            details={selectedDetails}
            {...(snapshot ? { snapshot } : {})}
          />
        )}
        {view === 'network' && snapshot && (
          <div
            className="blood-network-sidebar-slot"
            ref={setBloodNetworkSidebarContainer}
          />
        )}
      </section>

      {creating && (
        <PersonFormDialog
          onCancel={() => setCreating(false)}
          onSave={createFirstPerson}
        />
      )}
      {exporting && snapshot && (
        <ExportTreeDialog
          onCancel={() => setExporting(false)}
          requestBlob={requestBlob}
          treeId={treeId}
          treeName={snapshot.tree.name}
        />
      )}
      {relativeDialog && snapshot && (
        <RelativeDialog
          action={relativeDialog.action}
          anchor={snapshot.people.find((person) => person.id === relativeDialog.anchorId)!}
          fixedParentIds={relativeDialog.fixedParentIds}
          fixedRelativeId={relativeDialog.fixedRelativeId}
          initialSecondParentId={relativeDialog.initialSecondParentId}
          onCancel={() => {
            setRelativeDialog(null)
          }}
          onSave={addRelative}
          snapshot={snapshot}
        />
      )}
      {parentsDialogChildId && (
        <ParentsFormDialog
          onCancel={() => setParentsDialogChildId(null)}
          onSave={saveParents}
        />
      )}
      {marriageEditing && (
        <MarriagePeriodDialog
          marriage={marriageEditing}
          onCancel={() => setMarriageEditing(null)}
          onEditPlace={(type) => {
            const event = events.find((item) => (
              item.type === type
              && item.sourceEntityId === marriageEditing.id
            ))
            if (event) setPlaceEditing(event)
          }}
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
      {placeEditing && (
        <PlaceDialog
          onCancel={() => setPlaceEditing(null)}
          {...(placeEditing.place ? {
            onDelete: deleteEventPlace,
          } : {})}
          onSave={saveEventPlace}
          place={placeEditing.place}
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
