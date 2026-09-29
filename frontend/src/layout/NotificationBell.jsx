import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell, PackageX, TriangleAlert } from 'lucide-react'
import { getNotifications } from '../api'
import { headerButtonClass } from './headerButton'
import useClickOutside from './useClickOutside'

const SEEN_KEY = 'store_billing_seen_notifications'
const POLL_MS = 60_000

const ICONS = {
  out_of_stock: { icon: PackageX, className: 'bg-red-50 text-red-600' },
  low_stock: { icon: TriangleAlert, className: 'bg-amber-50 text-amber-600' },
}

const readSeen = () => {
  try {
    return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) ?? '[]'))
  } catch {
    return new Set()
  }
}

export default function NotificationBell() {
  const navigate = useNavigate()
  const ref = useRef(null)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState([])
  const [seen, setSeen] = useState(readSeen)

  const load = useCallback(() => {
    getNotifications()
      .then((res) => setItems(res.data))
      .catch(() => {})
  }, [])

  useEffect(() => {
    load()
    const timer = setInterval(load, POLL_MS)
    window.addEventListener('store:refresh-notifications', load)
    return () => {
      clearInterval(timer)
      window.removeEventListener('store:refresh-notifications', load)
    }
  }, [load])

  const close = useCallback(() => setOpen(false), [])
  useClickOutside(ref, close, open)

  const unseen = items.filter((n) => !seen.has(n.id)).length

  function toggle() {
    const next = !open
    setOpen(next)
    if (next) {
      // Opening the panel marks everything currently listed as seen.
      const ids = new Set(items.map((n) => n.id))
      setSeen(ids)
      try {
        localStorage.setItem(SEEN_KEY, JSON.stringify([...ids]))
      } catch {
        /* ignore */
      }
    }
  }

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={toggle}
        className={headerButtonClass}
        aria-label={`Notifications${unseen ? ` (${unseen} new)` : ''}`}
        aria-expanded={open}
      >
        <Bell size={18} />
        {unseen > 0 && (
          <span className="absolute -right-1.5 -top-1.5 grid h-4 min-w-4 ring-2 ring-white place-items-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">
            {unseen > 9 ? '9+' : unseen}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <p className="text-sm font-semibold text-slate-900">Notifications</p>
            <span className="text-xs text-slate-500">{items.length} active</span>
          </div>
          <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
            {items.length === 0 && <li className="px-4 py-8 text-center text-sm text-slate-500">You're all caught up.</li>}
            {items.map((n) => {
              const { icon: Icon, className } = ICONS[n.type] ?? ICONS.low_stock
              return (
                <li key={n.id}>
                  <button
                    onClick={() => {
                      setOpen(false)
                      navigate(n.link)
                    }}
                    className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-slate-50"
                  >
                    <span className={`mt-0.5 rounded-lg p-1.5 ${className}`}>
                      <Icon size={16} aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium text-slate-800">{n.title}</span>
                      <span className="block text-xs text-slate-500">{n.message}</span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </div>
  )
}
