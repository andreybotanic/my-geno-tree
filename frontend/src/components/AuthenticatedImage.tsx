import { useEffect, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'

const pendingBlobRequests = new Map<string, Promise<Blob>>()
const cachedBlobs = new Map<string, Blob>()

function loadBlob(url: string, requestBlob: (url: string) => Promise<Blob>) {
  const cached = cachedBlobs.get(url)
  if (cached) {
    return Promise.resolve(cached)
  }

  const pending = pendingBlobRequests.get(url)
  if (pending) {
    return pending
  }

  const request = requestBlob(url)
    .then((blob) => {
      cachedBlobs.set(url, blob)
      return blob
    })
    .finally(() => {
      pendingBlobRequests.delete(url)
    })
  pendingBlobRequests.set(url, request)

  return request
}

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
      void loadBlob(url, requestBlob)
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
