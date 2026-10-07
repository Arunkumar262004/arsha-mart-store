import { useCallback, useRef, useState } from 'react'
import { Check, ChevronDown, Layers, Store } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import useClickOutside from './useClickOutside'

/**
 * Header control for the store the app works in. Users tied to one store see
 * a plain badge; others get a dropdown of their stores plus "All stores" when
 * allowed. Switching remounts the page (AppLayout keys it), so it refetches.
 */
export default function StoreSwitcher() {
  const { stores, currentStore, isAllStores, setCurrentStore, canSwitchStores, canSelectAllStores } = useAuth()
  const ref = useRef(null)
  const [open, setOpen] = useState(false)
  const close = useCallback(() => setOpen(false), [])
  useClickOutside(ref, close, open)

  const label = isAllStores ? 'All stores' : (currentStore?.name ?? 'No store')
  const Icon = isAllStores ? Layers : Store

  if (!canSwitchStores) {
    if (!currentStore) return null
    return (
      <span
        className="flex h-10 max-w-[10rem] items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700 sm:max-w-[14rem]"
        title={`Store: ${label}`}
      >
        <Store size={16} className="shrink-0 text-slate-400" aria-hidden />
        <span className="truncate sm:hidden">{currentStore.code ?? label}</span>
        <span className="hidden truncate sm:inline">{label}</span>
      </span>
    )
  }

  const pick = (value) => {
    setCurrentStore(value)
    close()
  }

  const option = (value, text, sub, selected) => (
    <button
      key={value}
      type="button"
      role="menuitemradio"
      aria-checked={selected}
      onClick={() => pick(value)}
      className={`flex w-full items-center gap-2 px-4 py-2 text-left text-sm hover:bg-slate-50 ${selected ? 'text-brand-700' : 'text-slate-700'}`}
    >
      <span className="min-w-0 flex-1">
        <span className="block truncate font-medium">{text}</span>
        {sub && <span className="block truncate text-xs text-slate-500">{sub}</span>}
      </span>
      {selected && <Check size={16} className="shrink-0 text-brand-600" aria-hidden />}
    </button>
  )

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex h-10 max-w-[10rem] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 sm:max-w-[16rem]"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Store: ${label}. Change store`}
        title="Change store"
      >
        <Icon size={16} className="shrink-0 text-brand-600" aria-hidden />
        {/* Phones show the short code to leave room for the page title. */}
        <span className="truncate sm:hidden">{isAllStores ? 'All' : (currentStore?.code ?? label)}</span>
        <span className="hidden truncate sm:inline">{label}</span>
        <ChevronDown size={16} className="shrink-0 text-slate-400" aria-hidden />
      </button>

      {open && (
        <div role="menu" className="absolute right-0 z-30 mt-2 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
          <p className="px-4 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-slate-400">Work in store</p>
          {canSelectAllStores && option('all', 'All stores', 'View totals across every store', isAllStores)}
          {stores.map((s) =>
            option(String(s.id), s.name, [s.code, s.city].filter(Boolean).join(' · '), !isAllStores && currentStore?.id === s.id),
          )}
        </div>
      )}
    </div>
  )
}
