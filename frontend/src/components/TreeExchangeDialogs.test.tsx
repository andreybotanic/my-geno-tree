import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthorizedBlobRequest, AuthorizedJsonRequest } from '../api/client'
import i18n from '../i18n'
import { safeTreeFileName } from '../treeFileName'
import { ExportTreeDialog, ImportTreeDialog } from './TreeExchangeDialogs'

describe('TreeExchangeDialogs', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('creates a safe default file name and downloads the GEDCOM blob', async () => {
    const requestBlob = vi.fn(async () => new Blob(['0 HEAD'])) as unknown as AuthorizedBlobRequest
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:gedcom')
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    const onCancel = vi.fn()

    render(
      <ExportTreeDialog
        onCancel={onCancel}
        requestBlob={requestBlob}
        treeId="tree-id"
        treeName={'Family: Smith/Ивановы'}
      />,
    )

    expect(screen.getByLabelText('File name (without extension)')).toHaveValue('Family- Smith-Ивановы')
    await userEvent.click(screen.getByRole('button', { name: 'Export' }))

    await waitFor(() => expect(requestBlob).toHaveBeenCalledWith(
      '/trees/tree-id/exports',
      expect.objectContaining({ method: 'POST' }),
    ))
    expect(createObjectURL).toHaveBeenCalled()
    expect(click).toHaveBeenCalled()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:gedcom')
    expect(onCancel).toHaveBeenCalled()
  })

  it('normalizes invalid and empty names', () => {
    expect(safeTreeFileName('  a/b:*?  ')).toBe('a-b-')
    expect(safeTreeFileName('...')).toBe('family-tree')
  })

  it('shows opt-in place recognition only for GEDCOM files with unresolved places', async () => {
    const request = vi.fn(async <T,>(path: string, init?: RequestInit): Promise<T> => {
      if ('/tree-imports/inspect' === path) {
        return {
          format: 'GEDCOM 5.5.1',
          personCount: 1,
          familyCount: 0,
          hasUnresolvedPlaces: true,
          warnings: [],
        } as T
      }
      expect(path).toBe('/tree-imports')
      if (!(init?.body instanceof FormData)) {
        throw new Error('Expected import form data.')
      }
      expect(init.body.get('recognizePlaces')).toBe('true')

      return { tree: { id: 'imported-tree' }, warnings: [] } as T
    }) as AuthorizedJsonRequest
    const onImported = vi.fn()

    render(<ImportTreeDialog onCancel={vi.fn()} onImported={onImported} request={request} />)

    await userEvent.type(screen.getByLabelText('Tree name'), 'Imported family')
    await userEvent.upload(screen.getByLabelText('GEDCOM file'), new File(['0 HEAD'], 'family.ged'))

    const recognizePlaces = await screen.findByRole('checkbox', { name: 'Recognize settlements with Geoapify' })
    expect(recognizePlaces).not.toBeChecked()
    expect(screen.getByRole('link', { name: 'Powered by Geoapify' })).toHaveAttribute('href', 'https://www.geoapify.com/')
    expect(screen.getByRole('link', { name: 'OpenStreetMap contributors' })).toHaveAttribute('href', 'https://www.openstreetmap.org/copyright')
    expect(screen.getByRole('button', { name: 'About place recognition' })).not.toHaveClass('icon-button')
    await userEvent.click(recognizePlaces)
    await userEvent.click(screen.getByRole('button', { name: 'Import' }))

    await waitFor(() => expect(onImported).toHaveBeenCalledWith('imported-tree', []))
  })

  it('does not show place recognition when every GEDCOM place has coordinates', async () => {
    const request = vi.fn(async <T,>(): Promise<T> => ({
      format: 'GEDCOM 5.5.1',
      personCount: 1,
      familyCount: 0,
      hasUnresolvedPlaces: false,
      warnings: [],
    }) as T) as AuthorizedJsonRequest

    render(<ImportTreeDialog onCancel={vi.fn()} onImported={vi.fn()} request={request} />)

    await userEvent.upload(screen.getByLabelText('GEDCOM file'), new File(['0 HEAD'], 'family.ged'))

    await waitFor(() => expect(screen.queryByRole('checkbox', { name: 'Recognize settlements with Geoapify' })).not.toBeInTheDocument())
  })
})
