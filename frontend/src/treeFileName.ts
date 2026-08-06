const invalidFileNameCharacters = '\\/:*?"<>|'

export function safeTreeFileName(name: string) {
  let sanitized = ''
  let replacingInvalidRun = false
  for (const character of name) {
    const code = character.charCodeAt(0)
    const invalid = code < 32
      || code === 127
      || invalidFileNameCharacters.includes(character)
    if (invalid) {
      if (!replacingInvalidRun) sanitized += '-'
      replacingInvalidRun = true
    } else {
      sanitized += character
      replacingInvalidRun = false
    }
  }
  sanitized = sanitized.replace(/^[ .]+|[ .]+$/g, '')

  return sanitized || 'family-tree'
}
