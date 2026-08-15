import type { ButtonHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import AddBoldIcon from '../assets/icons/add-bold.svg?react'

type AddButtonProps = Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'children'
> & {
  label?: string
}

export function AddButton({
  className,
  label,
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
      <AddBoldIcon aria-hidden="true" className="add-button-icon" focusable="false" />
      <span>{label ?? t('common.add')}</span>
    </button>
  )
}
