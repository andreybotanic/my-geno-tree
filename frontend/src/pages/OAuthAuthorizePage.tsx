import { useState } from 'react'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import type { OAuthAuthorizationView, OAuthDecisionResponse } from '../api/generated'
import { ApiRequestError } from '../api/client'
import { useAuth } from '../auth/AuthContext'

export function OAuthAuthorizePage() {
  const [queryClient] = useState(() => new QueryClient())
  return <QueryClientProvider client={queryClient}><OAuthConsent /></QueryClientProvider>
}

function OAuthConsent() {
  const { t } = useTranslation()
  const { request, user } = useAuth()
  const [saving, setSaving] = useState(false)
  const [decisionFailed, setDecisionFailed] = useState(false)
  const search = window.location.search
  const authorization = useQuery({
    queryKey: ['oauth-authorization', user?.id, search],
    queryFn: () => request<OAuthAuthorizationView>('/oauth/authorization-requests', {
      method: 'POST',
      body: JSON.stringify(Object.fromEntries(new URLSearchParams(search))),
    }),
    retry: false,
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
  })
  const invalidRequest = authorization.error instanceof ApiRequestError
    && authorization.error.status >= 400
    && authorization.error.status < 500
    && authorization.error.status !== 408
    && authorization.error.status !== 429

  const decide = async (approved: boolean) => {
    if (!authorization.data || saving) return
    setSaving(true)
    setDecisionFailed(false)
    try {
      const result = await request<OAuthDecisionResponse>(`/oauth/authorization-requests/${authorization.data.id}/decision`, {
        method: 'POST', body: JSON.stringify({ approved }),
      })
      window.location.assign(result.redirectUrl)
    } catch {
      setDecisionFailed(true)
      setSaving(false)
    }
  }

  return (
    <main className="auth-page oauth-page">
      <section className="auth-page-card oauth-consent" aria-busy={authorization.isFetching || saving}>
        <p className="eyebrow">MyGenoTree</p>
        <h1>{t('oauth.title')}</h1>
        {authorization.isPending && <p role="status">{t('common.loading')}</p>}
        {authorization.isError && <p className="inline-error" role="alert">{t(invalidRequest ? 'oauth.invalid' : 'oauth.unavailable')}</p>}
        {authorization.data && !authorization.isError && <>
          <p>{t('oauth.request', { name: authorization.data.clientName })}</p>
          <p>{t('oauth.access')}</p>
          <p>{t('oauth.approvals')}</p>
          <p>{t('oauth.redirect')} <bdi className="oauth-redirect-uri">{authorization.data.redirectUri}</bdi></p>
          {decisionFailed && <p role="alert">{t('oauth.failed')}</p>}
          {saving && <p role="status">{t('oauth.redirecting')}</p>}
          <div className="form-actions">
            <button className="primary-button" disabled={saving || decisionFailed || authorization.isFetching} onClick={() => { void decide(true) }}>{t('oauth.allow')}</button>
            <button className="secondary-button" disabled={saving || decisionFailed || authorization.isFetching} onClick={() => { void decide(false) }}>{t('oauth.deny')}</button>
          </div>
        </>}
        {((authorization.isError && !invalidRequest) || decisionFailed) && <button className="secondary-button" disabled={authorization.isFetching} onClick={() => { setDecisionFailed(false); void authorization.refetch() }}>{t('oauth.retry')}</button>}
      </section>
    </main>
  )
}
