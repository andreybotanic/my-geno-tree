import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthorizedBlobRequest } from '../api/client'
import i18n from '../i18n'
import { safeTreeFileName } from '../treeFileName'
import { ExportTreeDialog } from './TreeExchangeDialogs'

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
})
