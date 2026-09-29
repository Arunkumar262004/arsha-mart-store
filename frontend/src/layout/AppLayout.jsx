import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { ChevronDown, ChevronLeft, ChevronRight, Menu, X } from 'lucide-react'
import logo from '../assets/billing.png'
import { useAuth } from '../auth/AuthContext'
import FullscreenToggle from './FullscreenToggle'
import NotificationBell from './NotificationBell'
import UserMenu from './UserMenu'
import { isWithin, titleFor, visibleNav } from './navigation'

const COLLAPSED_KEY = 'store_billing_sidebar_collapsed'

const MENUS_KEY = 'store_billing_open_menus'

const readCollapsed = () => {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1'
  } catch {
    return false
  }
}

const readMenus = () => {
  try {
    return JSON.parse(localStorage.getItem(MENUS_KEY)) ?? {}
  } catch {
    return {}
  }
}

export default function AppLayout() {
  const { can } = useAuth()
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(readCollapsed) // desktop
  const [mobileOpenAt, setMobileOpenAt] = useState(null) // pathname the drawer was opened on
  const [openMenus, setOpenMenus] = useState(readMenus) // { reports: true, settings: false }

  // The mobile drawer closes itself on navigation: it is only "open" for the page it was opened on.
  const mobileOpen = mobileOpenAt === location.pathname

  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0')
    } catch {
      /* ignore */
    }
  }, [collapsed])

  useEffect(() => {
    try {
      localStorage.setItem(MENUS_KEY, JSON.stringify(openMenus))
    } catch {
      /* ignore */
    }
  }, [openMenus])

  const entries = visibleNav(can)
  // A menu opens by itself while one of its pages is showing, until the user closes it.
  const isOpen = (menu) => openMenus[menu.key] ?? menu.children.some((c) => isWithin(location.pathname, c.to))
  const toggleMenu = (menu) => {
    if (collapsed) {
      // The icon rail has no room for sub-menus: widen the sidebar and show this one.
      setCollapsed(false)
      setOpenMenus((m) => ({ ...m, [menu.key]: true }))
    } else {
      setOpenMenus((m) => ({ ...m, [menu.key]: !isOpen(menu) }))
    }
  }

  const link = ({ to, label, icon: Icon }, nested = false) => (
    <li key={to}>
      <NavLink
        to={to}
        title={collapsed ? label : undefined}
        className={({ isActive }) =>
          `group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${isActive ? 'bg-brand-50 text-brand-700' : 'hover:bg-slate-100 hover:text-slate-900'
          } ${nested ? 'pl-9' : ''} ${collapsed ? 'lg:justify-center lg:px-0' : ''}`
        }
      >
        {({ isActive }) => (
          <>
            <Icon size={nested ? 16 : 18} className={isActive ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-600'} aria-hidden />
            <span className={collapsed ? 'lg:hidden' : ''}>{label}</span>
          </>
        )}
      </NavLink>
    </li>
  )

  return (
    <div className="min-h-screen bg-slate-50 text-slate-800">
      {/* Mobile backdrop */}
      {mobileOpen && (
        <div className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" onClick={() => setMobileOpenAt(null)} aria-hidden />
      )}

      <aside
        className={`no-print fixed inset-y-0 left-0 z-40 flex flex-col border-r border-slate-200 bg-white text-slate-600 transition-all duration-200
          ${mobileOpen ? 'translate-x-0 shadow-xl' : '-translate-x-full'} w-64 lg:translate-x-0
          ${collapsed ? 'lg:w-[72px]' : 'lg:w-64'}`}
      >
        <div className={`flex h-16 items-center gap-3 border-b border-slate-200 px-4 ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}>
          {collapsed && (
            <button
              onClick={() => setCollapsed(false)}
              className="hidden h-11 w-11 place-items-center rounded-xl transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 lg:grid"
              aria-label="Expand menu"
              title="Expand menu"
            >
              <img src={logo} alt="" className="h-8 w-8 object-contain" />
            </button>
          )}
          <img src={logo} alt="" className={`h-9 w-9 shrink-0 object-contain ${collapsed ? 'lg:hidden' : ''}`} />
          <span className={`truncate text-base font-semibold text-slate-900 ${collapsed ? 'lg:hidden' : ''}`}>Arsha Mart</span>
          <button
            onClick={() => setCollapsed((c) => !c)}
            className="absolute -right-3.5 top-[18px] z-10 hidden h-7 w-7 place-items-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-md transition hover:border-brand-300 hover:bg-brand-600 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 lg:grid"
            aria-label={collapsed ? 'Expand menu' : 'Collapse menu'}
            title={collapsed ? 'Expand menu' : 'Collapse menu'}
          >
            {collapsed ? <ChevronRight size={16} /> : <ChevronLeft size={16} />}
          </button>
          <button
            onClick={() => setMobileOpenAt(null)}
            className="ml-auto rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 lg:hidden"
            aria-label="Close menu"
          >
            <X size={18} />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto px-3 py-5">
          <ul className="space-y-1">
            {entries.map((entry) => {
              if (!entry.children) return link(entry)

              const open = isOpen(entry)
              const active = entry.children.some((c) => isWithin(location.pathname, c.to))
              const Icon = entry.icon
              return (
                <li key={entry.key}>
                  <button
                    type="button"
                    onClick={() => toggleMenu(entry)}
                    aria-expanded={open && !collapsed}
                    title={collapsed ? entry.label : undefined}
                    className={`group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition hover:bg-slate-100 hover:text-slate-900 ${active ? 'text-brand-700' : ''
                      } ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}
                  >
                    <Icon size={18} className={active ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-600'} aria-hidden />
                    <span className={`flex-1 text-left ${collapsed ? 'lg:hidden' : ''}`}>{entry.label}</span>
                    <ChevronDown
                      size={16}
                      className={`text-slate-400 transition-transform ${open ? 'rotate-180' : ''} ${collapsed ? 'lg:hidden' : ''}`}
                      aria-hidden
                    />
                  </button>
                  {open && (
                    <ul className={`mt-1 space-y-1 ${collapsed ? 'lg:hidden' : ''}`}>{entry.children.map((child) => link(child, true))}</ul>
                  )}
                </li>
              )
            })}
          </ul>
        </nav>
      </aside>

      <div className={`transition-all duration-200 ${collapsed ? 'lg:pl-[72px]' : 'lg:pl-64'}`}>
        <header className="no-print sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
          <button
            onClick={() => setMobileOpenAt(location.pathname)}
            className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
            aria-label="Open menu"
          >
            <Menu size={20} />
          </button>
          <h1 className="truncate text-base font-semibold text-slate-900">{titleFor(location.pathname)}</h1>
          <div className="ml-auto flex items-center gap-2">
            <FullscreenToggle />
            <NotificationBell />
            <UserMenu />
          </div>
        </header>

        <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
