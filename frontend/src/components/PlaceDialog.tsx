import {
  useEffect,
  useRef,
  useState,
} from 'react'
import type {
  Map as MapLibreMap,
  MapMouseEvent,
  Marker as MapLibreMarker,
} from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { useTranslation } from 'react-i18next'
import type {
  EventPlace,
  GeoCoordinates,
  ResolvedEventPlace,
} from '../api/generated'
import { mapApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'

type PlaceDialogProps = {
  onCancel: () => void
  onDelete?: (() => Promise<void> | void) | undefined
  onSave: (place: ResolvedEventPlace) => Promise<void> | void
  place: EventPlace | null
}

export function PlaceDialog({
  onCancel,
  onDelete,
  onSave,
  place,
}: PlaceDialogProps) {
  const { t } = useTranslation()
  const { accessToken, request } = useAuth()
  const mapContainer = useRef<HTMLDivElement>(null)
  const map = useRef<MapLibreMap | null>(null)
  const marker = useRef<MapLibreMarker | null>(null)
  const markerClass = useRef<
    typeof import('maplibre-gl').Marker | null
  >(null)
  const [coordinates, setCoordinates] = useState<GeoCoordinates | null>(
    place?.coordinates ?? null,
  )
  const [name, setName] = useState(place?.name ?? '')
  const [searchQuery, setSearchQuery] = useState(place?.name ?? '')
  const [results, setResults] = useState<ResolvedEventPlace[]>([])
  const [searching, setSearching] = useState(false)
  const [resolving, setResolving] = useState(false)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [error, setError] = useState('')

  const setMarker = (next: GeoCoordinates) => {
    if (!map.current) return
    if (!marker.current) {
      if (!markerClass.current) return
      marker.current = new markerClass.current({ draggable: true })
        .setLngLat([next.longitude, next.latitude])
        .addTo(map.current)
      marker.current.on('dragend', () => {
        const position = marker.current?.getLngLat()
        if (position) {
          void resolvePoint({
            latitude: position.lat,
            longitude: position.lng,
          })
        }
      })
    } else {
      marker.current.setLngLat([next.longitude, next.latitude])
    }
  }

  const resolvePoint = async (next: GeoCoordinates) => {
    setCoordinates(next)
    setMarker(next)
    setResolving(true)
    setError('')
    try {
      const resolved = await mapApi.reverse(
        request,
        next.latitude,
        next.longitude,
      )
      setName(resolved.name)
    } catch {
      setError(t('place.reverseFailed'))
    } finally {
      setResolving(false)
    }
  }

  useEffect(() => {
    const container = mapContainer.current
    if (!container || !accessToken) return
    let active = true
    let instance: MapLibreMap | null = null
    let click: ((event: MapMouseEvent) => void) | null = null
    void import('maplibre-gl').then((maplibre) => {
      if (!active) return
      maplibre.setMaxParallelImageRequests(8)
      markerClass.current = maplibre.Marker
      const initial = place?.coordinates
      instance = new maplibre.Map({
        container,
        center: initial
          ? [initial.longitude, initial.latitude]
          : [20, 20],
        zoom: initial ? 10 : 1.2,
        maxZoom: 20,
        style: {
          version: 8,
          sources: {
            yandex: {
              type: 'raster',
              tiles: ['/api/v1/map/tiles/{z}/{x}/{y}.png'],
              tileSize: 256,
              maxzoom: 20,
              attribution: '<a href="https://yandex.ru/maps/" target="_blank" rel="noreferrer">&copy; Yandex</a>',
            },
          },
          layers: [{
            id: 'yandex-map',
            type: 'raster',
            source: 'yandex',
          }],
        },
        transformRequest(url, resourceType) {
          if (
            resourceType === 'Tile'
            && new URL(url, window.location.href).origin === window.location.origin
            && new URL(url, window.location.href).pathname.startsWith('/api/v1/map/tiles/')
          ) {
            return {
              url,
              headers: { Authorization: `Bearer ${accessToken}` },
            }
          }

          return { url }
        },
      })
      instance.addControl(new maplibre.NavigationControl({ showCompass: false }))
      map.current = instance
      if (initial) {
        setMarker(initial)
      }
      click = (event: MapMouseEvent) => {
        void resolvePoint({
          latitude: event.lngLat.lat,
          longitude: event.lngLat.lng,
        })
      }
      instance.on('click', click)
    })

    return () => {
      active = false
      if (instance && click) instance.off('click', click)
      marker.current?.remove()
      marker.current = null
      markerClass.current = null
      map.current = null
      instance?.remove()
    }
  // The dialog instance owns the initial place and is recreated when reopened.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessToken])

  const search = async () => {
    const query = searchQuery.trim()
    if (query.length < 2 || query.length > 200) {
      setError(t('place.searchInvalid'))
      return
    }
    setSearching(true)
    setError('')
    try {
      const response = await mapApi.search(request, query)
      setResults(response.items)
      if (response.items.length === 0) {
        setError(t('place.noResults'))
      }
    } catch {
      setResults([])
      setError(t('place.searchFailed'))
    } finally {
      setSearching(false)
    }
  }

  const choose = (result: ResolvedEventPlace) => {
    setName(result.name)
    setCoordinates(result.coordinates)
    setMarker(result.coordinates)
    map.current?.jumpTo({
      center: [result.coordinates.longitude, result.coordinates.latitude],
      zoom: 10,
    })
    setResults([])
    setError('')
  }

  const save = async () => {
    const normalizedName = name.trim()
    if (!coordinates || !normalizedName || normalizedName.length > 500) {
      setError(t('place.pointRequired'))
      return
    }
    setSaving(true)
    setError('')
    try {
      await onSave({ name: normalizedName, coordinates })
    } catch {
      setError(t('place.saveFailed'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (!onDelete) return
    setDeleting(true)
    setError('')
    try {
      await onDelete()
    } catch {
      setError(t('place.deleteFailed'))
    } finally {
      setDeleting(false)
    }
  }

  const busy = saving || deleting

  return (
    <div className="dialog-backdrop place-dialog-backdrop" role="presentation">
      <section
        aria-labelledby="place-dialog-title"
        aria-modal="true"
        className="dialog-card place-dialog"
        role="dialog"
      >
        <h2 id="place-dialog-title">{t('place.title')}</h2>
        {place && place.coordinates === null && (
          <p className="place-unresolved-warning" role="status">
            {t('place.unresolvedWarning')}
          </p>
        )}
        <div className="place-search">
          <label>
            <span>{t('place.search')}</span>
            <input
              maxLength={200}
              onChange={(event) => setSearchQuery(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  void search()
                }
              }}
              placeholder={t('place.searchPlaceholder')}
              value={searchQuery}
            />
          </label>
          <button
            className="secondary-button"
            disabled={searching}
            onClick={() => void search()}
            type="button"
          >
            {searching ? t('place.searching') : t('place.searchAction')}
          </button>
        </div>
        {results.length > 0 && (
          <ul className="place-search-results">
            {results.map((result) => (
              <li key={`${result.coordinates.latitude}:${result.coordinates.longitude}:${result.name}`}>
                <button onClick={() => choose(result)} type="button">
                  {result.name}
                </button>
              </li>
            ))}
          </ul>
        )}
        <div
          aria-label={t('place.map')}
          className="place-map"
          ref={mapContainer}
        />
        <small className="place-attribution">
          {t('place.geocodingAttribution')}
        </small>
        <label className="place-name-field">
          <span>{t('place.name')}</span>
          <input
            maxLength={500}
            onChange={(event) => setName(event.target.value)}
            value={name}
          />
        </label>
        <p className="place-coordinate-status">
          {coordinates
            ? t('place.coordinates', {
                latitude: coordinates.latitude.toFixed(6),
                longitude: coordinates.longitude.toFixed(6),
              })
            : t('place.noPoint')}
          {resolving && ` - ${t('place.resolving')}`}
        </p>
        {error && <p className="form-error" role="alert">{error}</p>}
        <div className="dialog-actions place-dialog-actions">
          <button
            className="secondary-button"
            disabled={busy}
            onClick={onCancel}
            type="button"
          >
            {t('common.cancel')}
          </button>
          {onDelete && (
            <button
              className="danger-link place-delete-button"
              disabled={busy}
              onClick={() => void remove()}
              type="button"
            >
              {deleting ? t('common.removing') : t('place.delete')}
            </button>
          )}
          <button
            className="primary-button"
            disabled={busy || !coordinates || !name.trim()}
            onClick={() => void save()}
            type="button"
          >
            {saving ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </section>
    </div>
  )
}

type PlaceTextButtonProps = {
  disabled?: boolean
  onClick: () => void
  place: EventPlace | null
}

export function PlaceTextButton({
  disabled = false,
  onClick,
  place,
}: PlaceTextButtonProps) {
  const { t } = useTranslation()

  return (
    <button
      className={`place-text-button${place ? ' place-text-button-set' : ''}`}
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {place?.name ?? t('place.add')}
    </button>
  )
}

type PlaceIconButtonProps = {
  disabled?: boolean
  label: string
  onClick: () => void
  place: EventPlace | null
}

export function PlaceIconButton({
  disabled = false,
  label,
  onClick,
  place,
}: PlaceIconButtonProps) {
  return (
    <button
      aria-label={label}
      className={`event-place-button${place ? ' event-place-button-set' : ''}`}
      disabled={disabled}
      onClick={onClick}
      title={label}
      type="button"
    >
      <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24">
        <path d="M12 21s6-5.6 6-12a6 6 0 1 0-12 0c0 6.4 6 12 6 12Z" />
        <circle cx="12" cy="9" r="2.2" />
      </svg>
    </button>
  )
}
