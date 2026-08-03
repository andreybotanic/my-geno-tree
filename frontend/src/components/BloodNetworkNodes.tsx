import type { NodeProps } from '@xyflow/react'
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
