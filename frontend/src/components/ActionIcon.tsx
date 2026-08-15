type ActionIconProps = {
  name: 'delete' | 'edit'
}

export function ActionIcon({ name }: ActionIconProps) {
  return (
    <svg
      aria-hidden="true"
      className="action-icon"
      focusable="false"
      viewBox="0 0 24 24"
    >
      {name === 'edit' ? (
        <>
          <path d="m4 20 4.5-1 10.9-10.9a2.1 2.1 0 0 0-3-3L5.5 16z" />
          <path d="m13.8 6.7 3.5 3.5" />
        </>
      ) : (
        <>
          <path d="M4 7h16M10 11v5M14 11v5M9 7V4h6v3M6 7l1 13h10l1-13" />
        </>
      )}
    </svg>
  )
}
