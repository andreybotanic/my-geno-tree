import {
  type NodeProps,
  ViewportPortal,
} from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import {
  BLOOD_NETWORK_FAMILY_SIZE,
  BLOOD_NETWORK_PERSON_SIZE,
  type BloodNetworkFamilyNode,
  type BloodNetworkFlowEdge,
  type BloodNetworkFlowNode,
  type BloodNetworkPersonNode,
} from '../bloodNetwork'
import {
  formatLifePeriod,
  formatPersonName,
} from '../personFormatting'
import { AuthenticatedImage } from './AuthenticatedImage'
import { PersonPortraitPlaceholder } from './PersonPortraitPlaceholder'

const BLOOD_NETWORK_ARROW_MARKER_ID = 'blood-network-arrowhead'

function bloodNetworkNodeCenter(node: BloodNetworkFlowNode) {
  const size = node.type === 'bloodPerson'
    ? BLOOD_NETWORK_PERSON_SIZE
    : BLOOD_NETWORK_FAMILY_SIZE
  return {
    x: node.position.x + size / 2,
    y: node.position.y + size / 2,
  }
}

export function BloodNetworkEdgesOverlay({
  edges,
  nodes,
}: {
  edges: BloodNetworkFlowEdge[]
  nodes: BloodNetworkFlowNode[]
}) {
  const nodesById = new Map(nodes.map((node) => [node.id, node]))

  return (
    <ViewportPortal>
      <svg
        aria-hidden="true"
        className="blood-network-edge-overlay"
        height="1"
        width="1"
      >
        <defs>
          <marker
            id={BLOOD_NETWORK_ARROW_MARKER_ID}
            markerHeight="8"
            markerUnits="strokeWidth"
            markerWidth="8"
            orient="auto"
            refX="7"
            refY="4"
            viewBox="0 0 8 8"
          >
            <path d="M 0 0 L 8 4 L 0 8 z" fill="context-stroke" />
          </marker>
        </defs>
        {edges.map((edge) => {
          const source = nodesById.get(edge.source)
          const target = nodesById.get(edge.target)
          if (!source || !target) {
            return null
          }
          const sourceCenter = bloodNetworkNodeCenter(source)
          const targetCenter = bloodNetworkNodeCenter(target)
          const deltaX = targetCenter.x - sourceCenter.x
          const deltaY = targetCenter.y - sourceCenter.y
          const distance = Math.hypot(deltaX, deltaY)
          if (distance < 0.001) {
            return null
          }
          const directionX = deltaX / distance
          const directionY = deltaY / distance
          const sourceRadius = edge.data?.sourceRadius ?? 0
          const targetRadius = edge.data?.targetRadius ?? 0

          return (
            <line
              className={`blood-network-${edge.data?.relation ?? 'familyToChild'}-edge`}
              key={edge.id}
              markerEnd={`url(#${BLOOD_NETWORK_ARROW_MARKER_ID})`}
              x1={sourceCenter.x + directionX * sourceRadius}
              x2={targetCenter.x - directionX * targetRadius}
              y1={sourceCenter.y + directionY * sourceRadius}
              y2={targetCenter.y - directionY * targetRadius}
            />
          )
        })}
      </svg>
    </ViewportPortal>
  )
}

export function BloodNetworkPersonNode({
  data,
  selected,
}: NodeProps<BloodNetworkPersonNode>) {
  const { i18n, t } = useTranslation()
  const name = formatPersonName(data.person, t)
  const lifePeriod = formatLifePeriod(
    data.person,
    i18n.resolvedLanguage ?? 'en',
    t,
  )

  return (
    <article
      aria-label={name}
      className={`blood-network-person${selected ? ' blood-network-rotation-pivot' : ''}`}
    >
      <AuthenticatedImage
        alt=""
        className="blood-network-photo"
        fallback={(
          <span className="blood-network-photo-placeholder">
            <PersonPortraitPlaceholder />
          </span>
        )}
        url={data.person.photoThumbnailUrl}
      />
      <span className="blood-network-tooltip" role="tooltip">
        <strong>{name}</strong>
        <span>{lifePeriod}</span>
      </span>
    </article>
  )
}

export function BloodNetworkFamilyNode({
  data,
  selected,
}: NodeProps<BloodNetworkFamilyNode>) {
  return (
    <span
      aria-label={`Shared parents of ${data.childIds.length} children`}
      className={`blood-network-family${selected ? ' blood-network-rotation-pivot' : ''}`}
    >
    </span>
  )
}
