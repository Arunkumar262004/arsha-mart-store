import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { getReport } from '../../api'
import { parseApiError } from '../../api/client'

const FILTER_KEYS = ['period', 'from', 'to', 'employee_id', 'search', 'type', 'page']

/**
 * Loads one report. Filters live in the URL (?period=last_month&employee_id=3)
 * so a report can be refreshed, bookmarked or shared as it is.
 */
export default function useReport(name, defaults = {}) {
  const [searchParams, setSearchParams] = useSearchParams()
  const filters = { period: 'this_month', ...defaults }
  for (const key of FILTER_KEYS) {
    if (searchParams.has(key)) filters[key] = searchParams.get(key)
  }

  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  // A custom range waits until both dates are picked.
  const incomplete = filters.period === 'custom' && !(filters.from && filters.to)
  const request = JSON.stringify(
    Object.fromEntries(
      // "all" is kept in the URL (so it beats a default) but not sent.
      Object.entries(filters).filter(
        ([key, value]) => value !== '' && value !== 'all' && (filters.period === 'custom' || (key !== 'from' && key !== 'to')),
      ),
    ),
  )

  useEffect(() => {
    if (incomplete) return
    let cancelled = false
    setLoading(true)
    setError(null)
    getReport(name, JSON.parse(request))
      .then((data) => !cancelled && setResult(data))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
      .finally(() => !cancelled && setLoading(false))
    return () => {
      cancelled = true
    }
  }, [name, request, incomplete])

  /** Change filters; any change except paging goes back to page 1. */
  const setFilters = (changes) =>
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current)
        for (const [key, value] of Object.entries(changes)) {
          if (value === '' || value == null) next.delete(key)
          else next.set(key, value)
        }
        if (!('page' in changes)) next.delete('page')
        return next
      },
      { replace: true },
    )

  // The same filters without paging, for the PDF / Excel downloads.
  const { page: _page, ...query } = JSON.parse(request)

  return { filters, setFilters, query, result, loading, error, incomplete }
}
