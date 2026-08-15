import { Handle, Position, type Node, type NodeProps } from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import ReferenceStarIcon from '../assets/icons/reference-star.svg?react'
import {
  formatLifePeriod,
  formatPersonName,
  formatPersonNameParts,
} from '../personFormatting'
import { AuthenticatedImage } from './AuthenticatedImage'
import { PersonPortraitPlaceholder } from './PersonPortraitPlaceholder'
import type { PersonCardPerson } from '../pathDisplayPlan'

export type PersonNodeData = {
  isReference: boolean
  isMuted?: boolean
  isOverlay?: boolean
  person: PersonCardPerson
}

export type PersonFlowNode = Node<PersonNodeData, 'person'>

export function PersonCard({
  data,
  selected,
}: NodeProps<PersonFlowNode>) {
  const { t, i18n } = useTranslation()
  const name = formatPersonName(data.person, t)
  const nameParts = formatPersonNameParts(data.person, t)

  return (
    <article
      aria-label={name}
      className={`person-card person-card-${data.person.gender}${selected ? ' person-card-selected' : ''}${data.isReference ? ' person-card-reference' : ''}${data.isMuted ? ' person-card-muted' : ''}${data.isOverlay ? ' person-card-overlay' : ''}`}
      title={name}
    >
      {data.isReference && (
        <span
          aria-label={t('editor.referenceMarker')}
          className="person-reference-marker"
        >
          <ReferenceStarIcon aria-hidden="true" focusable="false" />
        </span>
      )}
      <Handle
        className="person-handle"
        id="top-target"
        position={Position.Top}
        type="target"
      />
      <div className="person-photo-placeholder" aria-hidden="true">
        <AuthenticatedImage
          alt=""
          fallback={<PersonPortraitPlaceholder />}
          url={data.person.photoThumbnailUrl}
        />
      </div>
      <div className="person-card-details">
        <strong>
          {nameParts.map((part, index) => (
            <span key={`${part}-${index}`}>{part}</span>
          ))}
        </strong>
        <span>
          {formatLifePeriod(
            data.person,
            i18n.resolvedLanguage ?? 'en',
            t,
          )}
        </span>
      </div>
      <Handle
        className="person-handle"
        id="bottom-source"
        position={Position.Bottom}
        type="source"
      />
      <Handle
        className="person-handle"
        id="bottom-target"
        position={Position.Bottom}
        type="target"
      />
    </article>
  )
}
