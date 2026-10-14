import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import logoC2 from '../assets/logo-concepts/concept-c-connected.png'
import { ApiRequestError } from '../api/client'
import { useAuth } from '../auth/AuthContext'

export type AuthMode = 'login' | 'register'

type AuthFormProps = {
  onSuccess: () => void
}

type Fields = {
  confirmation: string
  email: string
  identifier: string
  password: string
  username: string
}

type FieldErrors = Partial<Record<keyof Fields, string>>

const emptyFields: Fields = {
  confirmation: '',
  email: '',
  identifier: '',
  password: '',
  username: '',
}

export function AuthForm({ onSuccess }: AuthFormProps) {
  const { t } = useTranslation()
  const { login, register } = useAuth()
  const [mode, setMode] = useState<AuthMode>('login')
  const [fields, setFields] = useState(emptyFields)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  const setField = (field: keyof Fields, value: string) => {
    setFields((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
  }

  const switchMode = () => {
    setMode((current) => (current === 'login' ? 'register' : 'login'))
    setErrors({})
    setFormError('')
  }

  const validate = (): FieldErrors => {
    const next: FieldErrors = {}
    if (mode === 'login') {
      if (!fields.identifier.trim()) {
        next.identifier = t('auth.required')
      }
      if (!fields.password) {
        next.password = t('auth.required')
      }

      return next
    }

    if (!/^[A-Za-z0-9_-]{4,32}$/.test(fields.username)) {
      next.username = t('auth.usernameInvalid')
    }
    if (
      fields.email.trim().length > 254 ||
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(fields.email.trim())
    ) {
      next.email = t('auth.emailInvalid')
    }
    const categories = [
      /[a-z]/.test(fields.password),
      /[A-Z]/.test(fields.password),
      /\d/.test(fields.password),
      /[!-/:-@[-`{-~]/.test(fields.password),
    ].filter(Boolean).length
    if (
      fields.password.length < 8 ||
      fields.password.length > 64 ||
      /[^\x21-\x7e]/.test(fields.password) ||
      categories < 3
    ) {
      next.password = t('auth.passwordInvalid')
    }
    if (fields.password !== fields.confirmation) {
      next.confirmation = t('auth.passwordMismatch')
    }

    return next
  }

  const applyApiError = (error: unknown) => {
    if (!(error instanceof ApiRequestError)) {
      setFormError(t('auth.genericError'))
      return
    }
    if (error.status === 401) {
      setFormError(t('auth.invalidCredentials'))
      return
    }

    const serverFields: FieldErrors = {}
    for (const [field, messages] of Object.entries(error.body.fields)) {
      if (field in emptyFields && messages[0]) {
        serverFields[field as keyof Fields] = t(`errors.${messages[0]}`)
      }
    }
    if (Object.keys(serverFields).length > 0) {
      setErrors(serverFields)
      return
    }
    setFormError(t('auth.genericError'))
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setFormError('')
    const validationErrors = validate()
    setErrors(validationErrors)
    if (Object.keys(validationErrors).length > 0) {
      return
    }

    setIsSubmitting(true)
    try {
      if (mode === 'login') {
        await login({
          identifier: fields.identifier.trim(),
          password: fields.password,
        })
      } else {
        await register({
          username: fields.username,
          email: fields.email,
          password: fields.password,
        })
      }
      onSuccess()
    } catch (error) {
      applyApiError(error)
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form className="auth-form" onSubmit={submit} noValidate>
      <div className="auth-heading">
        <span className="auth-mark" aria-hidden="true">
          <img alt="" src={logoC2} />
        </span>
        <h2>
          {mode === 'login'
            ? t('auth.loginTitle')
            : t('auth.registerTitle')}
        </h2>
      </div>

      {mode === 'login' ? (
        <FormField
          autoComplete="username"
          error={errors.identifier}
          label={t('auth.identifier')}
          name="identifier"
          onChange={(value) => setField('identifier', value)}
          value={fields.identifier}
        />
      ) : (
        <>
          <FormField
            autoComplete="username"
            error={errors.username}
            label={t('auth.username')}
            name="username"
            onChange={(value) => setField('username', value)}
            value={fields.username}
          />
          <FormField
            autoComplete="email"
            error={errors.email}
            label={t('auth.email')}
            name="email"
            onChange={(value) => setField('email', value)}
            type="email"
            value={fields.email}
          />
        </>
      )}

      <FormField
        autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
        error={errors.password}
        label={t('auth.password')}
        name="password"
        onChange={(value) => setField('password', value)}
        type="password"
        value={fields.password}
      />

      {mode === 'register' && (
        <FormField
          autoComplete="new-password"
          error={errors.confirmation}
          label={t('auth.confirmation')}
          name="confirmation"
          onChange={(value) => setField('confirmation', value)}
          type="password"
          value={fields.confirmation}
        />
      )}

      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}

      <button className="primary-button auth-submit" disabled={isSubmitting}>
        {mode === 'login'
          ? isSubmitting
            ? t('auth.loggingIn')
            : t('auth.login')
          : isSubmitting
            ? t('auth.registering')
            : t('auth.register')}
      </button>
      <button
        className="link-button auth-switch"
        onClick={switchMode}
        type="button"
      >
        {mode === 'login'
          ? t('auth.switchRegister')
          : t('auth.switchLogin')}
      </button>
    </form>
  )
}

type FormFieldProps = {
  autoComplete: string
  error?: string | undefined
  label: string
  name: string
  onChange: (value: string) => void
  type?: 'email' | 'password' | 'text'
  value: string
}

function FormField({
  autoComplete,
  error,
  label,
  name,
  onChange,
  type = 'text',
  value,
}: FormFieldProps) {
  const errorId = `${name}-error`

  return (
    <label className="form-field">
      <span>{label}</span>
      <input
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        autoComplete={autoComplete}
        name={name}
        onChange={(event) => onChange(event.target.value)}
        type={type}
        value={value}
      />
      {error && (
        <small id={errorId} role="alert">
          {error}
        </small>
      )}
    </label>
  )
}
