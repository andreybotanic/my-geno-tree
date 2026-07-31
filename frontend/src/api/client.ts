import type {
  ApiError,
  AuthResponse,
  ChangeLanguageRequest,
  LoginRequest,
  RegisterRequest,
  User,
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
  const body: unknown = await response.json()
  if (!response.ok) {
    throw new ApiRequestError(response.status, body as ApiError)
  }

  return body as T
}

async function jsonRequest<T>(
  path: string,
  init: RequestInit,
): Promise<T> {
  const response = await fetch(`/api/v1${path}`, {
    ...init,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...init.headers,
    },
  })

  return readResponse<T>(response)
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
  updateProfile(
    input: ChangeLanguageRequest,
    accessToken: string,
  ): Promise<User> {
    return jsonRequest<User>('/me', {
      method: 'PATCH',
      body: JSON.stringify(input),
      headers: { Authorization: `Bearer ${accessToken}` },
    })
  },
}
