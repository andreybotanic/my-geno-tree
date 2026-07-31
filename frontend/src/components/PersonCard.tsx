import { Handle, Position, type Node, type NodeProps } from '@xyflow/react'
import { useTranslation } from 'react-i18next'
import type { Person } from '../api/generated'
import {
  formatLifePeriod,
  formatPersonName,
} from '../personFormatting'
import { AuthenticatedImage } from './AuthenticatedImage'

export type PersonNodeData = {
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
      className={`person-card${selected ? ' person-card-selected' : ''}`}
      title={name}
    >
      <Handle
        className="person-handle"
        id="top-target"
        position={Position.Top}
        type="target"
      />
      <Handle
        className="person-handle"
        id="left-target"
        position={Position.Left}
        type="target"
      />
      <div className="person-photo-placeholder" aria-hidden="true">
        <AuthenticatedImage
          alt=""
          fallback={<span>?</span>}
          url={data.person.photoThumbnailUrl}
        />
      </div>
      <div className="person-card-copy">
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
        id="right-source"
        position={Position.Right}
        type="source"
      />
    </article>
  )
}
