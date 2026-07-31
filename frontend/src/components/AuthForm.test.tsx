import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { AuthContextValue } from '../auth/AuthContext'
import { AuthContext } from '../auth/AuthContext'
import { ApiRequestError } from '../api/client'
import i18n from '../i18n'
import { AuthForm } from './AuthForm'

const baseContext: AuthContextValue = {
  accessToken: null,
  isBooting: false,
  user: null,
  login: vi.fn(),
  logout: vi.fn(),
  register: vi.fn(),
  updateLanguage: vi.fn(),
}

function renderForm(
  value: AuthContextValue,
  onSuccess = vi.fn(),
) {
  render(
    <AuthContext value={value}>
      <AuthForm onSuccess={onSuccess} />
    </AuthContext>,
  )

  return onSuccess
}

describe('AuthForm', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('switches between login and registration without closing', async () => {
    renderForm(baseContext)

    await userEvent.click(
      screen.getByRole('button', { name: 'Or create an account' }),
    )
    expect(screen.getByLabelText('Username')).toBeInTheDocument()
    expect(screen.getByLabelText('Confirm password')).toBeInTheDocument()

    await userEvent.click(
      screen.getByRole('button', { name: 'I already have an account' }),
    )
    expect(screen.getByLabelText('Username or email')).toBeInTheDocument()
  })

  it('validates registration fields and confirmation before sending', async () => {
    const register = vi.fn()
    renderForm({ ...baseContext, register })
    await userEvent.click(
      screen.getByRole('button', { name: 'Or create an account' }),
    )
    await userEvent.type(screen.getByLabelText('Username'), 'x')
    await userEvent.type(screen.getByLabelText('Email'), 'invalid')
    await userEvent.type(screen.getByLabelText('Password'), 'weak')
    await userEvent.type(screen.getByLabelText('Confirm password'), 'other')
    await userEvent.click(screen.getByRole('button', { name: 'Register' }))

    expect(
      screen.getByText(/Use 4–32 Latin letters/),
    ).toBeInTheDocument()
    expect(screen.getByText('Enter a valid email address.')).toBeInTheDocument()
    expect(screen.getByText('Passwords do not match.')).toBeInTheDocument()
    expect(register).not.toHaveBeenCalled()
  })

  it('submits a valid registration and reports success', async () => {
    const register = vi.fn().mockResolvedValue(undefined)
    const onSuccess = renderForm({ ...baseContext, register })
    await userEvent.click(
      screen.getByRole('button', { name: 'Or create an account' }),
    )
    await userEvent.type(screen.getByLabelText('Username'), 'family_admin')
    await userEvent.type(
      screen.getByLabelText('Email'),
      'family@example.test',
    )
    await userEvent.type(screen.getByLabelText('Password'), 'StrongPass1!')
    await userEvent.type(
      screen.getByLabelText('Confirm password'),
      'StrongPass1!',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Register' }))

    expect(register).toHaveBeenCalledWith({
      username: 'family_admin',
      email: 'family@example.test',
      password: 'StrongPass1!',
    })
    expect(onSuccess).toHaveBeenCalled()
  })

  it('shows the same safe error for invalid login credentials', async () => {
    const login = vi.fn().mockRejectedValue(new ApiRequestError(401, {
      code: 'INVALID_CREDENTIALS',
      message: 'invalid_credentials',
      fields: {},
      details: {},
    }))
    renderForm({ ...baseContext, login })
    await userEvent.type(
      screen.getByLabelText('Username or email'),
      'missing_user',
    )
    await userEvent.type(screen.getByLabelText('Password'), 'WrongPass1!')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(
      await screen.findByText(
        'The username, email or password is incorrect.',
      ),
    ).toBeInTheDocument()
  })

  it('maps server field errors and hides unexpected failures', async () => {
    const register = vi
      .fn()
      .mockRejectedValueOnce(new ApiRequestError(409, {
        code: 'VALIDATION_FAILED',
        message: 'validation_failed',
        fields: { username: ['username_not_unique'] },
        details: {},
      }))
      .mockRejectedValueOnce(new TypeError('network'))
    renderForm({ ...baseContext, register })
    await userEvent.click(
      screen.getByRole('button', { name: 'Or create an account' }),
    )
    await userEvent.type(screen.getByLabelText('Username'), 'family_admin')
    await userEvent.type(
      screen.getByLabelText('Email'),
      'family@example.test',
    )
    await userEvent.type(screen.getByLabelText('Password'), 'StrongPass1!')
    await userEvent.type(
      screen.getByLabelText('Confirm password'),
      'StrongPass1!',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Register' }))
    expect(
      await screen.findByText('This username is already in use.'),
    ).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/Username/), '2')
    await userEvent.click(screen.getByRole('button', { name: 'Register' }))
    expect(
      await screen.findByText(
        'The request could not be completed. Please try again.',
      ),
    ).toBeInTheDocument()
  })
})
