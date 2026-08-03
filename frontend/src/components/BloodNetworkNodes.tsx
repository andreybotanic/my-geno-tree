import { Handle, Position, type NodeProps } from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import {
  type BloodNetworkFamilyNode,
  type BloodNetworkPersonNode,
} from '../bloodNetwork'
import {
  formatLifePeriod,
  formatPersonName,
} from '../personFormatting'
import { AuthenticatedImage } from './AuthenticatedImage'

function NetworkHandles() {
  return (
    <>
      <Handle className="blood-network-handle" id="network-target" position={Position.Top} type="target" />
      <Handle className="blood-network-handle" id="network-source" position={Position.Bottom} type="source" />
    </>
  )
}

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
      <NetworkHandles />
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
      <NetworkHandles />
    </span>
  )
}
