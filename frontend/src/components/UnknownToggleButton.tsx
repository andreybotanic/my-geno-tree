type UnknownToggleButtonProps = {
  active: boolean
  onChange: (active: boolean) => void
  tabIndex?: number | undefined
  title: string
}

export function UnknownToggleButton({
  active,
  onChange,
  tabIndex,
  title,
}: UnknownToggleButtonProps) {
  return (
    <button
      aria-label={title}
      aria-pressed={active}
      className="unknown-toggle-button"
      onClick={() => onChange(!active)}
      tabIndex={tabIndex}
      title={title}
      type="button"
    >
      <svg
        aria-hidden="true"
        focusable="false"
        viewBox="0 0 18 18"
      >
        <rect height="10" rx="2" width="14" x="2" y="4" />
        <path d="M5.5 9h7" />
      </svg>
    </button>
  )
}
