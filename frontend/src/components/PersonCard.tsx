import { Handle, Position, type Node, type NodeProps } from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import type { Person } from '../api/generated'
import {
  formatLifePeriod,
  formatPersonName,
} from '../personFormatting'
import { AuthenticatedImage } from './AuthenticatedImage'
import { PersonPortraitPlaceholder } from './PersonPortraitPlaceholder'

export type PersonNodeData = {
  isReference: boolean
  person: Person
}

export type PersonFlowNode = Node<PersonNodeData, 'person'>

export function PersonCard({
  data,
  selected,
}: NodeProps<PersonFlowNode>) {
  const { t, i18n } = useTranslation()
  const name = formatPersonName(data.person, t)

  return (
    <article
      aria-label={name}
      className={`person-card${selected ? ' person-card-selected' : ''}${data.isReference ? ' person-card-reference' : ''}`}
      title={name}
    >
      {data.isReference && (
        <span
          aria-label={t('editor.referenceMarker')}
          className="person-reference-marker"
        >
          <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24">
            <path d="m12 2.5 2.92 5.92 6.53.95-4.73 4.61 1.12 6.5L12 17.4l-5.84 3.07 1.12-6.5-4.73-4.61 6.53-.95Z" />
          </svg>
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
        <strong>{name}</strong>
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
