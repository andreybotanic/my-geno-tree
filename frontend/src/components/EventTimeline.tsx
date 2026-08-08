import { useCallback, useLayoutEffect, useRef, useState } from 'react'
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
  const deathIndex = events.findIndex((event) => event.type === 'death')
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
        <AddButton disabled={conflict} onClick={onCreate} />
      </div>
      {events.length === 0 ? (
        <p className="panel-empty">{t('person.noEvents')}</p>
      ) : (
        <>
          {orderError && (
            <p className="form-error" role="alert">{orderError}</p>
          )}
          <ol className="event-timeline">
            {events.map((event, eventIndex) => {
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
              const title = event.title ?? t(`person.eventType.${event.type}`)

              return (
                <li
                  className={eventIndex >= deathIndex && deathIndex !== -1
                    ? 'event-after-death'
                    : undefined}
                  key={event.id}
                >
                  <div className="event-card-content">
                    <span className="event-date">
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
                      <EventDescription
                        description={event.description}
                        expanded={isExpanded}
                        onToggle={() => setExpanded((current) => {
                          const nextExpanded = new Set(current)
                          if (nextExpanded.has(event.id)) {
                            nextExpanded.delete(event.id)
                          } else {
                            nextExpanded.add(event.id)
                          }
                          return nextExpanded
                        })}
                      />
                    )}
                    {event.source === 'manual' && (
                      <div className="event-actions">
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
                      </div>
                    )}
                  </div>
                  <div className="event-order-actions">
                    <button
                      aria-label={t('events.moveUp', { title })}
                      disabled={conflict || reordering || !canMoveUp}
                      onClick={() => void move(event, -1)}
                      type="button"
                    >
                      ↑
                    </button>
                    <button
                      aria-label={t('events.moveDown', { title })}
                      disabled={conflict || reordering || !canMoveDown}
                      onClick={() => void move(event, 1)}
                      type="button"
                    >
                      ↓
                    </button>
                  </div>
                </li>
              )
            })}
          </ol>
        </>
      )}
    </section>
  )
}

type EventDescriptionProps = {
  description: string
  expanded: boolean
  onToggle: () => void
}

function EventDescription({
  description,
  expanded,
  onToggle,
}: EventDescriptionProps) {
  const { t } = useTranslation()
  const container = useRef<HTMLDivElement>(null)
  const [fitsOneLine, setFitsOneLine] = useState(false)
  const measure = useCallback(() => {
    const element = container.current
    if (!element || /\r?\n/.test(description)) {
      setFitsOneLine(false)
      return
    }
    const context = document.createElement('canvas').getContext('2d')
    if (!context) {
      setFitsOneLine(false)
      return
    }
    context.font = getComputedStyle(element).font
    const availableWidth = element.clientWidth || element.offsetWidth
    setFitsOneLine(context.measureText(description).width <= availableWidth)
  }, [description])

  useLayoutEffect(() => {
    measure()
    const observer = new ResizeObserver(measure)
    const element = container.current
    if (element) {
      observer.observe(element)
    }
    return () => observer.disconnect()
  }, [measure])

  return (
    <div className="event-description-area" ref={container}>
      {fitsOneLine || expanded ? (
        <p className="event-description">{description}</p>
      ) : (
        <button
          aria-expanded={false}
          className="event-summary-button"
          onClick={onToggle}
          type="button"
        >
          {t('events.expand')}
        </button>
      )}
      {expanded && !fitsOneLine && (
        <button
          aria-expanded
          className="event-summary-button"
          onClick={onToggle}
          type="button"
        >
          {t('events.collapse')}
        </button>
      )}
    </div>
  )
}
