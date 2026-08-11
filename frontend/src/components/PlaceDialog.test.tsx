import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import i18n from '../i18n'

const mapHarness = vi.hoisted(() => ({
  click: null as ((event: { lngLat: { lat: number, lng: number } }) => void) | null,
  jumpTo: vi.fn(),
  markerAdds: 0,
  maxParallel: vi.fn(),
  options: null as null | {
    attributionControl: { compact: boolean, customAttribution: string }
    container: HTMLElement
    transformRequest: (
      url: string,
      resourceType: string,
    ) => { headers?: Record<string, string>, url: string }
  },
}))

vi.mock('maplibre-gl', () => {
  class Map {
    constructor(options: typeof mapHarness.options) {
      mapHarness.options = options
      const attribution = document.createElement('details')
      attribution.classList.add(
        'maplibregl-ctrl-attrib',
        'maplibregl-compact',
        'maplibregl-compact-show',
      )
      attribution.setAttribute('open', '')
      options?.container.append(attribution)
    }

    addControl() {}
    jumpTo(options: unknown) { mapHarness.jumpTo(options) }
    off() {}
    on(type: string, callback: typeof mapHarness.click) {
      if (type === 'click') mapHarness.click = callback
    }
    once(type: string, callback: () => void) {
      if (type === 'load') callback()
    }
    remove() {}
  }

  class Marker {
    addTo() {
      ++mapHarness.markerAdds
      return this
    }
    getLngLat() { return { lat: 0, lng: 0 } }
    on() { return this }
    remove() {}
    setLngLat() { return this }
  }

  return {
    Map,
    Marker,
    NavigationControl: class {},
    setMaxParallelImageRequests: mapHarness.maxParallel,
  }
})

import { PlaceDialog } from './PlaceDialog'

const renderDialog = (
  request: AuthContextValue['request'],
  properties: Partial<React.ComponentProps<typeof PlaceDialog>> = {},
) => {
  const onCancel = vi.fn()
  const onSave = vi.fn(async () => {})
  render(
    <AuthContext value={{
      accessToken: 'map-access-token',
      isBooting: false,
      user: null,
      login: vi.fn(),
      logout: vi.fn(),
      register: vi.fn(),
      request,
      requestBlob: vi.fn(),
      updateLanguage: vi.fn(),
      updateUser: vi.fn(),
    }}>
      <PlaceDialog
        onCancel={onCancel}
        onSave={onSave}
        place={null}
        {...properties}
      />
    </AuthContext>,
  )

  return { onCancel, onSave }
}

describe('PlaceDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    mapHarness.click = null
    mapHarness.jumpTo.mockClear()
    mapHarness.maxParallel.mockClear()
    mapHarness.markerAdds = 0
    mapHarness.options = null
  })

  it('authorizes only same-origin tile requests and limits parallel loading', async () => {
    renderDialog(vi.fn())

    await waitFor(() => expect(mapHarness.maxParallel).toHaveBeenCalledWith(8))
    expect(mapHarness.options?.attributionControl).toEqual({
      compact: true,
      customAttribution: expect.stringContaining(
        'https://www.openstreetmap.org/copyright',
      ),
    })
    const attribution = document.querySelector('.maplibregl-ctrl-attrib')
    expect(attribution).not.toHaveAttribute('open')
    expect(attribution).not.toHaveClass('maplibregl-compact-show')
    const transform = mapHarness.options?.transformRequest
    expect(transform).toBeTypeOf('function')
    const tileUrl = `${window.location.origin}/api/v1/map/tiles/1/0/0.png`
    expect(transform?.(
      tileUrl,
      'Tile',
    )).toEqual({
      url: tileUrl,
      headers: { Authorization: 'Bearer map-access-token' },
    })
    expect(transform?.(
      'https://tiles.api-maps.yandex.ru/v1/tiles/',
      'Tile',
    )).toEqual({
      url: 'https://tiles.api-maps.yandex.ru/v1/tiles/',
    })
    expect(screen.getByRole('link', { name: 'Powered by Geoapify' })).toHaveAttribute('href', 'https://www.geoapify.com/')
    expect(screen.getByRole('link', { name: 'OpenStreetMap contributors' })).toHaveAttribute('href', 'https://www.openstreetmap.org/copyright')
  })

  it('keeps an imported unresolved place until deletion or point selection', async () => {
    const onDelete = vi.fn(async () => {})
    renderDialog(vi.fn(), {
      onDelete,
      place: { name: 'Old village', coordinates: null },
    })

    expect(screen.getByText(/imported without coordinates/i))
      .toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Settlement search' }))
      .toHaveValue('Old village')
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
    expect(mapHarness.markerAdds).toBe(0)

    await userEvent.click(screen.getByRole('button', { name: 'Delete place' }))
    expect(onDelete).toHaveBeenCalledOnce()
  })

  it('selects a search result while allowing an independent custom name', async () => {
    const request = vi.fn(async (path: string) => {
      expect(path).toContain('/map/geocoding/search?q=Novosibirsk')
      return {
        items: [{
          name: 'Novosibirsk, Russia',
          coordinates: { latitude: 55.0302, longitude: 82.9204 },
        }],
      }
    }) as AuthContextValue['request']
    const { onSave } = renderDialog(request)
    await userEvent.type(
      screen.getByRole('combobox', { name: 'Settlement search' }),
      'Novosibirsk',
    )
    await userEvent.click(await screen.findByRole('option', {
      name: 'Novosibirsk, Russia',
    }))
    const name = screen.getByRole('textbox', { name: 'Place name' })
    await userEvent.clear(name)
    await userEvent.type(name, 'My custom name')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onSave).toHaveBeenCalledWith({
      name: 'My custom name',
      coordinates: { latitude: 55.0302, longitude: 82.9204 },
    })
    expect(mapHarness.jumpTo).toHaveBeenCalledWith({
      center: [82.9204, 55.0302],
      zoom: 10,
    })
    expect(document.querySelector('.place-coordinate-status'))
      .not.toBeInTheDocument()
    expect(document.querySelector('.place-attribution')).not.toBeInTheDocument()
    expect(screen.queryByText(/55\.0302/)).not.toBeInTheDocument()
  })

  it('shows the official Yandex logo as a map link', () => {
    renderDialog(vi.fn())

    const link = screen.getByRole('link', { name: 'Yandex Maps' })
    expect(link).toHaveAttribute('href', 'https://yandex.ru/maps/')
    expect(link.querySelector('img')).toHaveAttribute(
      'src',
      '/yandex-logo.svg',
    )
  })

  it('reverse geocodes a map click and cancel does not save it', async () => {
    const request = vi.fn(async (path: string) => {
      expect(path).toContain('/map/geocoding/reverse?')
      return {
        name: 'Selected point',
        coordinates: { latitude: 55, longitude: 83 },
      }
    }) as AuthContextValue['request']
    const { onCancel, onSave } = renderDialog(request)

    await waitFor(() => expect(mapHarness.click).toBeTypeOf('function'))
    mapHarness.click?.({ lngLat: { lat: 55, lng: 83 } })
    await waitFor(() => expect(
      screen.getByRole('textbox', { name: 'Place name' }),
    ).toHaveValue('Selected point'))
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(onCancel).toHaveBeenCalledOnce()
    expect(onSave).not.toHaveBeenCalled()
  })
})
