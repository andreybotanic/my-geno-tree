import { useEffect, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'

type AuthenticatedImageProps = {
  alt: string
  className?: string
  fallback: ReactNode
  url: string | null
}

export function AuthenticatedImage({
  alt,
  className,
  fallback,
  url,
}: AuthenticatedImageProps) {
  const { requestBlob } = useAuth()
  const [source, setSource] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    let objectUrl: string | null = null
    setSource(null)
    if (url) {
      void requestBlob(url)
        .then((blob) => {
          if (!active) {
            return
          }
          objectUrl = URL.createObjectURL(blob)
          setSource(objectUrl)
        })
        .catch(() => {
          if (active) {
            setSource(null)
          }
        })
    }

    return () => {
      active = false
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl)
      }
    }
  }, [requestBlob, url])

  return source
    ? <img alt={alt} className={className} src={source} />
    : fallback
}
