type PersonPortraitPlaceholderProps = {
  className?: string
}

export function PersonPortraitPlaceholder({
  className,
}: PersonPortraitPlaceholderProps) {
  return (
    <svg
      aria-hidden="true"
      className={`person-portrait-placeholder${className ? ` ${className}` : ''}`}
      focusable="false"
      viewBox="0 0 24 24"
    >
      <circle cx="12" cy="8" r="4" />
      <path d="M4 22a8 8 0 0 1 16 0Z" />
    </svg>
  )
}
