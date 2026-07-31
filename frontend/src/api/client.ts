import type {
  ApiError,
  AuthResponse,
  LoginRequest,
  RegisterRequest,
} from './generated'

export class ApiRequestError extends Error {
  readonly status: number
  readonly body: ApiError

  constructor(
    status: number,
    body: ApiError,
  ) {
    super(body.message ?? body.code)
    this.status = status
    this.body = body
  }
}

async function readResponse<T>(response: Response): Promise<T> {
  const text = await response.text()
  const body: unknown = text ? JSON.parse(text) : undefined
  if (!response.ok) {
    throw new ApiRequestError(response.status, body as ApiError)
  }

  return body as T
}

export async function jsonRequest<T>(
  path: string,
  init: RequestInit,
): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Content-Type', 'application/json')

  const response = await fetch(`/api/v1${path}`, {
    ...init,
    credentials: 'include',
    headers,
  })

  return readResponse<T>(response)
}

export type AuthorizedJsonRequest = <T>(
  path: string,
  init?: RequestInit,
) => Promise<T>

export const authApi = {
  register(input: RegisterRequest): Promise<AuthResponse> {
    return jsonRequest<AuthResponse>('/auth/register', {
      method: 'POST',
      body: JSON.stringify(input),
    })
  },
  login(input: LoginRequest): Promise<AuthResponse> {
    return jsonRequest<AuthResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(input),
    })
  },
  refresh(): Promise<AuthResponse> {
    return jsonRequest<AuthResponse>('/auth/refresh', {
      method: 'POST',
    })
  },
  async logout(): Promise<void> {
    const response = await fetch('/api/v1/auth/logout', {
      method: 'POST',
      credentials: 'include',
    })
    if (!response.ok) {
      throw new Error('Logout failed')
    }
  },
}

export const treeApi = {
  list(request: AuthorizedJsonRequest) {
    return request<import('./generated').TreeList>('/trees')
  },
  create(request: AuthorizedJsonRequest, name: string) {
    return request<import('./generated').FamilyTree>('/trees', {
      method: 'POST',
      body: JSON.stringify({ name }),
    })
  },
  rename(
    request: AuthorizedJsonRequest,
    treeId: string,
    name: string,
    etag: string,
  ) {
    return request<import('./generated').FamilyTree>(`/trees/${treeId}`, {
      method: 'PATCH',
      body: JSON.stringify({ name }),
      headers: { 'If-Match': etag },
    })
  },
  remove(
    request: AuthorizedJsonRequest,
    treeId: string,
    etag: string,
  ) {
    return request<void>(`/trees/${treeId}`, {
      method: 'DELETE',
      headers: { 'If-Match': etag },
    })
  },
  snapshot(request: AuthorizedJsonRequest, treeId: string) {
    return request<import('./generated').TreeSnapshot>(
      `/trees/${treeId}/snapshot`,
    )
  },
  createFirstPerson(
    request: AuthorizedJsonRequest,
    treeId: string,
    etag: string,
    person: import('./generated').PersonInput,
    coordinates: import('./generated').Coordinates,
  ) {
    return treeApi.mutate(request, treeId, etag, {
      type: 'create_first_person',
      person,
      coordinates,
    })
  },
  mutate(
    request: AuthorizedJsonRequest,
    treeId: string,
    etag: string,
    mutation: import('./generated').GraphMutation,
  ) {
    return request<import('./generated').TreeSnapshot>(
      `/trees/${treeId}/graph-mutations`,
      {
        method: 'POST',
        body: JSON.stringify(mutation),
        headers: { 'If-Match': etag },
      },
    )
  },
}

export const personApi = {
  get(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
  ) {
    return request<import('./generated').Person>(
      `/trees/${treeId}/people/${personId}`,
    )
  },
  update(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    etag: string,
    patch: import('./generated').PersonPatchRequest,
  ) {
    return request<import('./generated').PersonUpdateResult>(
      `/trees/${treeId}/people/${personId}`,
      {
        method: 'PATCH',
        body: JSON.stringify(patch),
        headers: { 'If-Match': etag },
      },
    )
  },
  events(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
  ) {
    return request<import('./generated').EventList>(
      `/trees/${treeId}/people/${personId}/events`,
    )
  },
}

export const marriageApi = {
  update(
    request: AuthorizedJsonRequest,
    treeId: string,
    marriageId: string,
    etag: string,
    patch: import('./generated').MarriagePeriodPatchRequest,
  ) {
    return request<import('./generated').MarriagePeriodUpdateResult>(
      `/trees/${treeId}/marriages/${marriageId}`,
      {
        method: 'PATCH',
        body: JSON.stringify(patch),
        headers: { 'If-Match': etag },
      },
    )
  },
}

export const eventApi = {
  create(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    etag: string,
    event: import('./generated').ManualEventRequest,
  ) {
    return request<import('./generated').EventMutationResult>(
      `/trees/${treeId}/people/${personId}/events`,
      {
        method: 'POST',
        body: JSON.stringify(event),
        headers: { 'If-Match': etag },
      },
    )
  },
  update(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    eventId: string,
    etag: string,
    event: import('./generated').ManualEventRequest,
  ) {
    return request<import('./generated').EventMutationResult>(
      `/trees/${treeId}/people/${personId}/events/${eventId}`,
      {
        method: 'PATCH',
        body: JSON.stringify(event),
        headers: { 'If-Match': etag },
      },
    )
  },
  remove(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    eventId: string,
    etag: string,
  ) {
    return request<void>(
      `/trees/${treeId}/people/${personId}/events/${eventId}`,
      {
        method: 'DELETE',
        headers: { 'If-Match': etag },
      },
    )
  },
  reorder(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    etag: string,
    eventIds: string[],
  ) {
    return request<import('./generated').EventOrderResult>(
      `/trees/${treeId}/people/${personId}/event-order`,
      {
        method: 'PUT',
        body: JSON.stringify({ eventIds }),
        headers: { 'If-Match': etag },
      },
    )
  },
}
