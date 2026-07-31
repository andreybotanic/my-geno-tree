type UnknownToggleButtonProps = {
  active: boolean
  onChange: (active: boolean) => void
  title: string
}

export function UnknownToggleButton({
  active,
  onChange,
  title,
}: UnknownToggleButtonProps) {
  return (
    <button
      aria-label={title}
      aria-pressed={active}
      className="unknown-toggle-button"
      onClick={() => onChange(!active)}
      title={title}
      type="button"
    >
      <svg
        aria-hidden="true"
        focusable="false"
        viewBox="0 0 18 18"
      >
        <circle cx="9" cy="9" r="7" />
        <path d="M6.7 6.8A2.35 2.35 0 0 1 9.1 4.7c1.45 0 2.55.9 2.55 2.2 0 1.15-.7 1.75-1.55 2.3-.75.5-1.1.9-1.1 1.75" />
        <circle className="unknown-toggle-dot" cx="9" cy="13.4" r=".7" />
      </svg>
    </button>
  )
}
