import type { ButtonHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'

type AddButtonProps = Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'children'
>

export function AddButton({
  className,
  type = 'button',
  ...props
}: AddButtonProps) {
  const { t } = useTranslation()

  return (
    <button
      {...props}
      className={`secondary-button compact-button add-button${
        className ? ` ${className}` : ''
      }`}
      type={type}
    >
      <svg
        aria-hidden="true"
        className="add-button-icon"
        focusable="false"
        viewBox="0 0 16 16"
      >
        <path d="M8 3v10M3 8h10" />
      </svg>
      <span>{t('common.add')}</span>
    </button>
  )
}
