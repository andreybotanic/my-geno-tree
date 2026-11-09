import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import { AuthContext, type AuthContextValue } from '../auth/AuthContext'
import i18n from '../i18n'
import { DocumentSourceLinks } from './DocumentSourceLinks'

beforeEach(async () => { await i18n.changeLanguage('en') })
it('renders escaped sources, downloads through authentication and reports unavailable originals', async () => {
  const requestBlob = vi.fn().mockRejectedValue(new Error('removed'))
  const auth: AuthContextValue = {
    accessToken: null, isBooting: false, user: null, requestBlob, request: vi.fn(),
    login: vi.fn(), logout: vi.fn(), register: vi.fn(), updateLanguage: vi.fn(), updateUser: vi.fn(),
  }
  const source = {
    name: '<img src=x>', excerpt: 'An original passage.',
    originalSizeBytes: 1234,
    downloadUrl: '/trees/019861c4-6a93-7a2e-8ca8-51813740d4a5/documents/019861c4-6a93-7a2e-8ca8-51813740d4a7/download',
  }
  render(<AuthContext.Provider value={auth}><DocumentSourceLinks
    sources={[source, { ...source, excerpt: 'A second passage from the same file.' }]} /></AuthContext.Provider>)
  fireEvent.click(screen.getByText('Sources (1)'))
  expect(screen.queryByText('An original passage.')).not.toBeInTheDocument()
  expect(screen.queryByText('A second passage from the same file.')).not.toBeInTheDocument()
  expect(screen.getByText('1.2 kB')).toBeInTheDocument()
  expect(screen.getAllByRole('button')).toHaveLength(1)
  expect(document.querySelector('img')).toBeNull()
  fireEvent.click(screen.getByRole('button', { name: '<img src=x>' }))
  await waitFor(() => expect(requestBlob).toHaveBeenCalled())
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not complete')
})
