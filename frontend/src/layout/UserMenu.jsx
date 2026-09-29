import { useCallback, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { ChevronDown, KeyRound, LogOut } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import useClickOutside from './useClickOutside'

const initials = (name = '') =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')

export default function UserMenu() {
  const { user, logout } = useAuth()
  const ref = useRef(null)
  const [open, setOpen] = useState(false)
  const close = useCallback(() => setOpen(false), [])
  useClickOutside(ref, close, open)

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white py-1 pl-1 pr-2 shadow-sm transition hover:border-brand-200 hover:bg-brand-50"
        aria-expanded={open}
        aria-label="Account menu"
      >
        <span className="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
          {initials(user?.name)}
        </span>
        <span className="hidden text-left sm:block">
          <span className="block text-sm font-medium leading-tight text-slate-800">{user?.name}</span>
          <span className="block text-xs leading-tight text-slate-500">{user?.role?.name}</span>
        </span>
        <ChevronDown size={16} className="hidden text-slate-400 sm:block" />
      </button>

      {open && (
        <div className="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
          <div className="border-b border-slate-100 px-4 py-3">
            <p className="truncate text-sm font-medium text-slate-900">{user?.name}</p>
            <p className="truncate text-xs text-slate-500">{user?.email}</p>
          </div>
          <Link to="/profile" onClick={close} className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
            <KeyRound size={16} className="text-slate-400" /> Change password
          </Link>
          <button onClick={logout} className="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
            <LogOut size={16} /> Sign out
          </button>
        </div>
      )}
    </div>
  )
}
