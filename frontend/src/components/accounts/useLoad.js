import { useEffect, useState } from 'react'
import { parseApiError } from '../../api/client'

/**
 * Runs `loader()` whenever `key` (e.g. the JSON of the filters) changes and
 * keeps the last result while the next one loads. `reload()` runs it again.
 */
export default function useLoad(loader, key, { skip = false } = {}) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [tick, setTick] = useState(0)

  useEffect(() => {
    if (skip) return
    let cancelled = false
    setLoading(true)
    setError(null)
    loader()
      .then((result) => !cancelled && setData(result))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
      .finally(() => !cancelled && setLoading(false))
    return () => {
      cancelled = true
    }
    // The loader is a fresh closure every render; `key` says when it changed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, tick, skip])

  return { data, loading, error, reload: () => setTick((t) => t + 1) }
}
