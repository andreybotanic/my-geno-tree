import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { GenealogicalEvent } from '../api/generated'
import { canMoveEvent } from '../eventTimeline'
import { formatGenealogicalDate } from '../personFormatting'
import { AddButton } from './AddButton'

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
  const move = async (event: GenealogicalEvent, offset: -1 | 1) => {
    const currentIndex = events.findIndex(
      (candidate) => candidate.id === event.id,
    )
    const targetIndex = currentIndex + offset
    const target = events[targetIndex]
    if (
      currentIndex === -1
      || !target
      || !canMoveEvent(event.date, target.date)
      || conflict
      || reordering
    ) {
      return
    }
    const eventIds = events.map((candidate) => candidate.id)
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
        <AddButton
          disabled={conflict}
          onClick={onCreate}
        />
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
              const eventIndex = events.findIndex(
                (candidate) => candidate.id === event.id,
              )
              const previous = events[eventIndex - 1]
              const next = events[eventIndex + 1]
              const canMoveUp = Boolean(
                previous && canMoveEvent(event.date, previous.date),
              )
              const canMoveDown = Boolean(
                next && canMoveEvent(event.date, next.date),
              )
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
                  {(event.source === 'manual' || canMoveUp || canMoveDown) && (
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
                      {(canMoveUp || canMoveDown) && (
                        <div className="event-order-actions">
                          <button
                            aria-label={t('events.moveUp', {
                              title: event.title
                                ?? t(`person.eventType.${event.type}`),
                            })}
                            disabled={conflict
                              || reordering
                              || !canMoveUp}
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
                              || !canMoveDown
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
