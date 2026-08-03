import {
  BaseEdge,
  type EdgeProps,
  type NodeProps,
} from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import {
  type BloodNetworkFamilyNode,
  type BloodNetworkFlowEdge,
  type BloodNetworkPersonNode,
} from '../bloodNetwork'
import {
  formatLifePeriod,
  formatPersonName,
} from '../personFormatting'
import { AuthenticatedImage } from './AuthenticatedImage'

export function BloodNetworkPersonNode({
  data,
}: NodeProps<BloodNetworkPersonNode>) {
  const { i18n, t } = useTranslation()
  const name = formatPersonName(data.person, t)
  const lifePeriod = formatLifePeriod(
    data.person,
    i18n.resolvedLanguage ?? 'en',
    t,
  )

  return (
    <article aria-label={name} className="blood-network-person">
      <AuthenticatedImage
        alt=""
        className="blood-network-photo"
        fallback={<span aria-hidden="true">?</span>}
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
}: NodeProps<BloodNetworkFamilyNode>) {
  return (
    <span
      aria-label={`Shared parents of ${data.childIds.length} children`}
      className="blood-network-family"
    >
    </span>
  )
}

export function BloodNetworkEdge({
  data,
  id,
  markerEnd,
  sourceX,
  sourceY,
  targetX,
  targetY,
}: EdgeProps<BloodNetworkFlowEdge>) {
  const deltaX = targetX - sourceX
  const deltaY = targetY - sourceY
  const distance = Math.hypot(deltaX, deltaY)
  if (distance < 0.001) {
    return null
  }
  const directionX = deltaX / distance
  const directionY = deltaY / distance
  const sourceRadius = data?.sourceRadius ?? 0
  const targetRadius = data?.targetRadius ?? 0
  const path = [
    `M ${sourceX + directionX * sourceRadius} ${sourceY + directionY * sourceRadius}`,
    `L ${targetX - directionX * targetRadius} ${targetY - directionY * targetRadius}`,
  ].join(' ')

  const className = `blood-network-${data?.relation ?? 'familyToChild'}-edge`
  return markerEnd
    ? <BaseEdge className={className} id={id} markerEnd={markerEnd} path={path} />
    : <BaseEdge className={className} id={id} path={path} />
}
