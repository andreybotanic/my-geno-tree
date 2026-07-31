import { createContext, useContext } from 'react'
import type {
  Language,
  LoginRequest,
  RegisterRequest,
  User,
} from '../api/generated'
import type {
  AuthorizedBlobRequest,
  AuthorizedJsonRequest,
} from '../api/client'

export type AuthContextValue = {
  accessToken: string | null
  isBooting: boolean
  user: User | null
  login: (input: LoginRequest) => Promise<void>
  logout: () => Promise<void>
  register: (input: Omit<RegisterRequest, 'language'>) => Promise<void>
  request: AuthorizedJsonRequest
  requestBlob: AuthorizedBlobRequest
  updateLanguage: (language: Language) => Promise<void>
  updateUser: (user: User) => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (!value) {
    throw new Error('useAuth must be used inside AuthProvider')
  }

  return value
}
