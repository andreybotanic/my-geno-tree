type PersonPortraitPlaceholderProps = {
  className?: string
}

export function PersonPortraitPlaceholder({
  className,
}: PersonPortraitPlaceholderProps) {
  return <PersonFilledIcon aria-hidden="true" className={`person-portrait-placeholder${className ? ` ${className}` : ''}`} focusable="false" />
}
import PersonFilledIcon from '../assets/icons/person-filled.svg?react'
