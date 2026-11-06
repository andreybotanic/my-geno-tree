import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import i18n from '../i18n'
import { ApiRequestError } from '../api/client'
import { OAuthAuthorizePage } from './OAuthAuthorizePage'

const { request } = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('../auth/AuthContext', () => ({ useAuth: () => ({ request, user: { id: 'owner' } }) }))

const consent = { id: 'request-id', clientName: '<img src=x> External agent', redirectUri: 'https://agent.example/callback', scope: 'mcp' }
const failure = { code: 'OAUTH_REQUEST_FAILED', message: 'oauth_request_failed', fields: {}, details: {} }

function renderPage() {
  return render(<OAuthAuthorizePage />)
}

describe('OAuth consent', () => {
  beforeEach(async () => {
    request.mockReset()
    window.history.replaceState({}, '', '/oauth/authorize?client_id=agent&state=original-state')
    await i18n.changeLanguage('en')
  })

  it('loads the validated request and escapes the application name', async () => {
    request.mockResolvedValue(consent)
    const { container } = renderPage()
    expect(screen.getByRole('status')).toHaveTextContent('Loading')
    expect(await screen.findByText(/<img src=x> External agent/)).toBeVisible()
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText(/all trees you own/)).toBeVisible()
    expect(request).toHaveBeenCalledWith('/oauth/authorization-requests', expect.objectContaining({ method: 'POST', body: JSON.stringify({ client_id: 'agent', state: 'original-state' }) }))
  })

  it('allows a specific request and prevents duplicate submission', async () => {
    request.mockResolvedValueOnce(consent).mockImplementationOnce(() => new Promise(() => {}))
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Allow access' }))
    expect(request).toHaveBeenLastCalledWith('/oauth/authorization-requests/request-id/decision', { method: 'POST', body: JSON.stringify({ approved: true }) })
    expect(screen.getByRole('button', { name: 'Allow access' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Deny' })).toBeDisabled()
    expect(screen.getByRole('status')).toHaveTextContent('Returning')
  })

  it('declines and returns to the callback provided by the backend', async () => {
    request.mockResolvedValueOnce(consent).mockResolvedValueOnce({ redirectUrl: '#denied' })
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Deny' }))
    expect(request).toHaveBeenLastCalledWith('/oauth/authorization-requests/request-id/decision', { method: 'POST', body: JSON.stringify({ approved: false }) })
    await waitFor(() => { expect(window.location.hash).toBe('#denied') })
  })

  it.each([400, 401, 404, 422])('requires a new connection in the agent for an invalid request (%s)', async (status) => {
    request.mockRejectedValue(new ApiRequestError(status, failure))
    renderPage()
    expect(await screen.findByRole('alert')).toHaveTextContent('invalid or unavailable')
    expect(screen.queryByRole('button', { name: 'Allow access' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull()
    expect(request).toHaveBeenCalledTimes(1)
  })

  it.each([
    new TypeError('Failed to fetch'),
    new ApiRequestError(503, failure),
    new ApiRequestError(408, failure),
    new ApiRequestError(429, failure),
  ])('allows retrying a temporarily unavailable request (%s)', async (error) => {
    request.mockRejectedValueOnce(error).mockResolvedValueOnce(consent)
    renderPage()
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load')
    expect(screen.queryByRole('button', { name: 'Allow access' })).toBeNull()
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('button', { name: 'Allow access' })).toBeEnabled()
  })

  it('requires a new consent request after a failed or expired decision', async () => {
    request.mockResolvedValueOnce(consent).mockRejectedValueOnce(new Error('expired')).mockResolvedValueOnce({ ...consent, id: 'new-request' })
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Allow access' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('expired or already been used')
    expect(screen.getByRole('button', { name: 'Allow access' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    await waitFor(() => { expect(request).toHaveBeenCalledTimes(3) })
    expect(screen.getByRole('button', { name: 'Allow access' })).toBeEnabled()
  })

  it('hides the previous consent if retrying finds invalid authorization parameters', async () => {
    request.mockResolvedValueOnce(consent)
      .mockRejectedValueOnce(new Error('expired'))
      .mockRejectedValueOnce(new ApiRequestError(400, failure))
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Allow access' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('invalid or unavailable')
    expect(screen.queryByRole('button', { name: 'Allow access' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull()
  })

  it('localizes consent and errors in Russian', async () => {
    await i18n.changeLanguage('ru')
    request.mockResolvedValueOnce(consent)
    renderPage()
    expect(await screen.findByRole('button', { name: 'Разрешить доступ' })).toBeVisible()
    expect(screen.getByText(/просматривать и изменять все ваши деревья/)).toBeVisible()
  })
})
