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

async function readBlobResponse(response: Response): Promise<Blob> {
  if (!response.ok) {
    const text = await response.text()
    const body: unknown = text ? JSON.parse(text) : {}
    throw new ApiRequestError(response.status, body as ApiError)
  }

  return response.blob()
}

export async function jsonRequest<T>(
  path: string,
  init: RequestInit,
): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (!(init.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`/api/v1${path}`, {
    ...init,
    credentials: 'include',
    headers,
  })

  return readResponse<T>(response)
}

export async function blobRequest(
  path: string,
  init: RequestInit,
): Promise<Blob> {
  const response = await fetch(`/api/v1${path}`, {
    ...init,
    credentials: 'include',
  })

  return readBlobResponse(response)
}

export type AuthorizedJsonRequest = <T>(
  path: string,
  init?: RequestInit,
) => Promise<T>

export type AuthorizedBlobRequest = (
  path: string,
  init?: RequestInit,
) => Promise<Blob>

export type CropPixels = {
  x: number
  y: number
  width: number
  height: number
}

function imageForm(file: File, crop: CropPixels) {
  const form = new FormData()
  form.set('image', file)
  form.set('cropX', String(Math.round(crop.x)))
  form.set('cropY', String(Math.round(crop.y)))
  form.set('cropWidth', String(Math.round(crop.width)))
  form.set('cropHeight', String(Math.round(crop.height)))

  return form
}

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
  inspectImport(request: AuthorizedJsonRequest, file: File) {
    const form = new FormData()
    form.set('file', file)
    return request<import('./generated').TreeImportInspection>(
      '/tree-imports/inspect',
      { method: 'POST', body: form },
    )
  },
  import(request: AuthorizedJsonRequest, file: File, name: string, recognizePlaces: boolean) {
    const form = new FormData()
    form.set('file', file)
    form.set('name', name)
    form.set('recognizePlaces', String(recognizePlaces))
    return request<import('./generated').TreeImportResult>('/tree-imports', {
      method: 'POST',
      body: form,
    })
  },
  export(
    request: AuthorizedBlobRequest,
    treeId: string,
    format: 'gedcom-5.5.1' | 'gedcom-7',
    fileName: string,
  ) {
    return request(`/trees/${treeId}/exports`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ format, fileName }),
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
  saveLayout(
    request: AuthorizedJsonRequest,
    treeId: string,
    layout: import('./generated').SaveTreeLayoutRequest,
  ) {
    return request<void>(`/trees/${treeId}/layout`, {
      method: 'PUT',
      body: JSON.stringify(layout),
    })
  },
  saveBloodNetworkState(
    request: AuthorizedJsonRequest,
    treeId: string,
    etag: string,
    state: import('./generated').BloodNetworkMapState,
  ) {
    return request<import('./generated').TreeSnapshot>(
      `/trees/${treeId}/blood-network-state`,
      {
        method: 'PUT',
        body: JSON.stringify(state),
        headers: { 'If-Match': etag },
      },
    )
  },
  createFirstPerson(
    request: AuthorizedJsonRequest,
    treeId: string,
    etag: string,
    person: import('./generated').PersonInput,
  ) {
    return treeApi.mutate(request, treeId, etag, {
      type: 'create_first_person',
      person,
    })
  },
  changeReference(
    request: AuthorizedJsonRequest,
    treeId: string,
    etag: string,
    reference: import('./generated').TreeReference,
  ) {
    return request<import('./generated').TreeSnapshot>(
      `/trees/${treeId}/reference`,
      {
        method: 'PUT',
        body: JSON.stringify(reference),
        headers: { 'If-Match': etag },
      },
    )
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
  search(
    request: AuthorizedJsonRequest,
    treeId: string,
    query: string,
    signal?: AbortSignal,
  ) {
    return request<import('./generated').PersonSearchResultList>(
      `/trees/${treeId}/people/search?q=${encodeURIComponent(query)}`,
      signal ? { signal } : {},
    )
  },
  details(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
  ) {
    return request<import('./generated').PersonDetails>(
      `/trees/${treeId}/people/${personId}/details`,
    )
  },
  routeToReference(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
  ) {
    return request<import('./generated').SearchRoutePlan>(
      `/trees/${treeId}/route-to-reference/${personId}`,
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
  savePlace(
    request: AuthorizedJsonRequest,
    treeId: string,
    eventId: string,
    etag: string,
    place: import('./generated').ResolvedEventPlace,
  ) {
    return request<import('./generated').EventMutationResult>(
      `/trees/${treeId}/events/${eventId}/place`,
      {
        method: 'PUT',
        body: JSON.stringify(place),
        headers: { 'If-Match': etag },
      },
    )
  },
  removePlace(
    request: AuthorizedJsonRequest,
    treeId: string,
    eventId: string,
    etag: string,
  ) {
    return request<void>(`/trees/${treeId}/events/${eventId}/place`, {
      method: 'DELETE',
      headers: { 'If-Match': etag },
    })
  },
}

export const mapApi = {
  search(request: AuthorizedJsonRequest, query: string) {
    return request<import('./generated').GeocodingSearchResult>(
      `/map/geocoding/search?q=${encodeURIComponent(query)}`,
    )
  },
  reverse(
    request: AuthorizedJsonRequest,
    latitude: number,
    longitude: number,
  ) {
    const parameters = new URLSearchParams({
      latitude: String(latitude),
      longitude: String(longitude),
    })

    return request<import('./generated').ResolvedEventPlace>(
      `/map/geocoding/reverse?${parameters}`,
    )
  },
}

export const surnameApi = {
  localize(
    request: AuthorizedJsonRequest,
    input: import('./generated').SurnameLocalizationRequest,
  ) {
    return request<import('./generated').SurnameLocalizationResult>(
      '/surname-localization',
      { method: 'POST', body: JSON.stringify(input) },
    )
  },
}

export const profileMediaApi = {
  uploadAvatar(
    request: AuthorizedJsonRequest,
    file: File,
    crop: CropPixels,
  ) {
    return request<import('./generated').User>('/me/avatar', {
      method: 'POST',
      body: imageForm(file, crop),
    })
  },
  removeAvatar(request: AuthorizedJsonRequest) {
    return request<import('./generated').User>('/me/avatar', {
      method: 'DELETE',
    })
  },
}

export const personPhotoApi = {
  upload(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    etag: string,
    file: File,
    crop: CropPixels,
  ) {
    return request<import('./generated').PersonUpdateResult>(
      `/trees/${treeId}/people/${personId}/photo`,
      {
        method: 'POST',
        body: imageForm(file, crop),
        headers: { 'If-Match': etag },
      },
    )
  },
  remove(
    request: AuthorizedJsonRequest,
    treeId: string,
    personId: string,
    etag: string,
  ) {
    return request<import('./generated').PersonUpdateResult>(
      `/trees/${treeId}/people/${personId}/photo`,
      {
        method: 'DELETE',
        headers: { 'If-Match': etag },
      },
    )
  },
}
