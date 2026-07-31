import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { GenealogicalEvent } from '../api/generated'
import { ambiguousEventGroups } from '../eventTimeline'
import { formatGenealogicalDate } from '../personFormatting'

type EventTimelineProps = {
  conflict: boolean
  events: GenealogicalEvent[]
  onCreate: () => void
  onDelete: (event: GenealogicalEvent) => void
  onEdit: (event: GenealogicalEvent) => void
  onReorder: (eventIds: string[]) => Promise<void>
}

export function EventTimeline({
  conflict,
  events,
  onCreate,
  onDelete,
  onEdit,
  onReorder,
}: EventTimelineProps) {
  const { t, i18n } = useTranslation()
  const [expanded, setExpanded] = useState<Set<string>>(new Set())
  const [orderError, setOrderError] = useState('')
  const [reordering, setReordering] = useState(false)
  const language = i18n.resolvedLanguage ?? 'en'
  const groupsByEvent = useMemo(() => {
    const result = new Map<string, GenealogicalEvent[]>()
    for (const group of ambiguousEventGroups(events)) {
      for (const event of group) {
        result.set(event.id, group)
      }
    }

    return result
  }, [events])

  const move = async (event: GenealogicalEvent, offset: -1 | 1) => {
    const group = groupsByEvent.get(event.id)
    if (!group || conflict || reordering) {
      return
    }
    const currentIndex = group.findIndex((candidate) => candidate.id === event.id)
    const targetIndex = currentIndex + offset
    if (targetIndex < 0 || targetIndex >= group.length) {
      return
    }
    const eventIds = group.map((candidate) => candidate.id)
    const currentId = eventIds[currentIndex]
    const targetId = eventIds[targetIndex]
    if (!currentId || !targetId) {
      return
    }
    eventIds[currentIndex] = targetId
    eventIds[targetIndex] = currentId
    setReordering(true)
    setOrderError('')
    try {
      await onReorder(eventIds)
    } catch {
      setOrderError(t('events.orderFailed'))
    } finally {
      setReordering(false)
    }
  }

  return (
    <section className="person-details-section">
      <div className="event-heading">
        <h3>{t('person.events')}</h3>
        <button
          className="secondary-button compact-button"
          disabled={conflict}
          onClick={onCreate}
          type="button"
        >
          {t('events.add')}
        </button>
      </div>
      {events.length === 0 ? (
        <p className="panel-empty">{t('person.noEvents')}</p>
      ) : (
        <>
          {orderError && (
            <p className="form-error" role="alert">{orderError}</p>
          )}
          <ol className="event-timeline">
            {events.map((event) => {
              const group = groupsByEvent.get(event.id)
              const groupIndex = group?.findIndex(
                (candidate) => candidate.id === event.id,
              ) ?? -1
              const isExpanded = expanded.has(event.id)
              const surname = typeof event.data.newSurname === 'string'
                ? event.data.newSurname
                : null

              return (
                <li key={event.id}>
                  <span>
                    {formatGenealogicalDate(event.date, language, t)}
                  </span>
                  <strong>
                    {event.type === 'custom' && event.title
                      ? event.title
                      : t(`person.eventType.${event.type}`)}
                  </strong>
                  {surname && (
                    <span>{t('events.newSurnameValue', { surname })}</span>
                  )}
                  {event.source === 'automatic' && (
                    <small>{t('person.automaticEvent')}</small>
                  )}
                  {event.type === 'custom' && event.description && (
                    <>
                      <button
                        aria-expanded={isExpanded}
                        className="event-summary-button"
                        onClick={() => setExpanded((current) => {
                          const next = new Set(current)
                          if (next.has(event.id)) {
                            next.delete(event.id)
                          } else {
                            next.add(event.id)
                          }
                          return next
                        })}
                        type="button"
                      >
                        {t(isExpanded ? 'events.collapse' : 'events.expand')}
                      </button>
                      {isExpanded && (
                        <p className="event-description">
                          {event.description}
                        </p>
                      )}
                    </>
                  )}
                  {(event.source === 'manual' || group) && (
                    <div className="event-actions">
                      {event.source === 'manual' && (
                        <>
                          <button
                            disabled={conflict}
                            onClick={() => onEdit(event)}
                            type="button"
                          >
                            {t('common.edit')}
                          </button>
                          <button
                            className="danger-link"
                            disabled={conflict}
                            onClick={() => onDelete(event)}
                            type="button"
                          >
                            {t('common.remove')}
                          </button>
                        </>
                      )}
                      {group && (
                        <div className="event-order-actions">
                          <button
                            aria-label={t('events.moveUp', {
                              title: event.title
                                ?? t(`person.eventType.${event.type}`),
                            })}
                            disabled={conflict
                              || reordering
                              || groupIndex === 0}
                            onClick={() => void move(event, -1)}
                            type="button"
                          >
                            ↑
                          </button>
                          <button
                            aria-label={t('events.moveDown', {
                              title: event.title
                                ?? t(`person.eventType.${event.type}`),
                            })}
                            disabled={
                              conflict
                              || reordering
                              || groupIndex === group.length - 1
                            }
                            onClick={() => void move(event, 1)}
                            type="button"
                          >
                            ↓
                          </button>
                        </div>
                      )}
                    </div>
                  )}
                </li>
              )
            })}
          </ol>
        </>
      )}
    </section>
  )
}
