import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from 'react'
import { useTranslation } from 'react-i18next'
import type { PersonSearchResult } from '../api/generated'

type PersonSearchPanelProps = {
  visiblePersonIds?: ReadonlySet<string>
  onSearch: (query: string, signal: AbortSignal) => Promise<PersonSearchResult[]>
  onSelect: (person: PersonSearchResult) => void
}

export function PersonSearchPanel({
  visiblePersonIds = new Set<string>(),
  onSearch,
  onSelect,
}: PersonSearchPanelProps) {
  const { t } = useTranslation()
  const panel = useRef<HTMLDivElement | null>(null)
  const input = useRef<HTMLInputElement | null>(null)
  const [expanded, setExpanded] = useState(false)
  const [query, setQuery] = useState('')
  const [items, setItems] = useState<PersonSearchResult[]>([])
  const [activeIndex, setActiveIndex] = useState(-1)
  const [status, setStatus] = useState<'error' | 'idle' | 'loading'>('idle')

  const close = useCallback(() => {
    setExpanded(false)
    setQuery('')
    setItems([])
    setActiveIndex(-1)
    setStatus('idle')
  }, [])

  useEffect(() => {
    if (!expanded) return undefined
    input.current?.focus()

    const closeOnOutsidePointer = (event: PointerEvent) => {
      if (!panel.current?.contains(event.target as Node)) {
        close()
      }
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        close()
      }
    }
    document.addEventListener('pointerdown', closeOnOutsidePointer)
    document.addEventListener('keydown', closeOnEscape)

    return () => {
      document.removeEventListener('pointerdown', closeOnOutsidePointer)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [close, expanded])

  useEffect(() => {
    const value = query.trim()
    if (value.length < 2) {
      setItems([])
      setActiveIndex(-1)
      setStatus('idle')
      return undefined
    }
    const controller = new AbortController()
    setItems([])
    setActiveIndex(-1)
    setStatus('loading')
    const timeout = window.setTimeout(() => {
      void onSearch(value, controller.signal)
        .then((next) => {
          if (controller.signal.aborted) return
          setItems(next)
          setActiveIndex(next.length === 0 ? -1 : 0)
          setStatus('idle')
        })
        .catch((error: unknown) => {
          if (controller.signal.aborted || (error instanceof DOMException && error.name === 'AbortError')) {
            return
          }
          setItems([])
          setActiveIndex(-1)
          setStatus('error')
        })
    }, 250)

    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [onSearch, query])

  const select = (person: PersonSearchResult) => {
    close()
    onSelect(person)
  }

  return (
    <div
      className={`person-search-panel${expanded ? ' person-search-panel-expanded' : ''}`}
      ref={panel}
    >
      <button
        aria-expanded={expanded}
        aria-label={t('editor.personSearch')}
        className="editor-tool-button"
        onClick={() => setExpanded((value) => !value)}
        title={t('editor.personSearch')}
        type="button"
      >
        <svg aria-hidden="true" viewBox="0 0 24 24">
          <circle cx="10.5" cy="10.5" r="5.75" />
          <path d="m15 15 5 5" />
        </svg>
      </button>
      {expanded && (
        <div className="person-search-content">
          <input
            aria-autocomplete="list"
            aria-controls="person-search-results"
            aria-expanded={items.length > 0}
            aria-label={t('editor.personSearchField')}
            autoComplete="off"
            maxLength={255}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown' && items.length > 0) {
                event.preventDefault()
                setActiveIndex((index) => (index + 1) % items.length)
              } else if (event.key === 'ArrowUp' && items.length > 0) {
                event.preventDefault()
                setActiveIndex((index) => (index - 1 + items.length) % items.length)
              } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault()
                const item = items[activeIndex]
                if (item) select(item)
              }
            }}
            placeholder={t('editor.personSearchField')}
            ref={input}
            role="combobox"
            value={query}
          />
          {status === 'loading' && <span className="person-search-status">{t('common.loading')}</span>}
          {status === 'error' && <span className="person-search-status" role="alert">{t('editor.personSearchFailed')}</span>}
          {query.trim().length >= 2 && status === 'idle' && items.length === 0 && (
            <span className="person-search-status">{t('editor.personSearchEmpty')}</span>
          )}
          {items.length > 0 && (
            <ul id="person-search-results" role="listbox">
              {items.map((person, index) => (
                <li key={person.id}>
                  <button
                    aria-selected={activeIndex === index}
                    className={activeIndex === index ? 'person-search-result-active' : undefined}
                    onClick={() => select(person)}
                    role="option"
                    type="button"
                  >
                    <span className="person-search-result-name">
                      <strong>{person.fullName}</strong>
                      {visiblePersonIds.has(person.id) && (
                        <span
                          aria-label={t('editor.personSearchVisible')}
                          className="person-search-result-visible"
                          title={t('editor.personSearchVisible')}
                        >
                          <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M2.5 12s3.5-5.5 9.5-5.5S21.5 12 21.5 12s-3.5 5.5-9.5 5.5S2.5 12 2.5 12Z" />
                            <circle cx="12" cy="12" r="2.5" />
                          </svg>
                        </span>
                      )}
                    </span>
                    <span>{formatYears(person.birthYear, person.deathYear, t('person.unknown'))}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}

function formatYears(
  birthYear: number | null,
  deathYear: number | null,
  unknown: string,
) {
  return `${birthYear ?? unknown} — ${deathYear ?? unknown}`
}
