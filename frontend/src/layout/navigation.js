import {
  BarChart3,
  Boxes,
  ClipboardList,
  History,
  KeyRound,
  LayoutDashboard,
  Package,
  ReceiptText,
  Settings,
  ShieldCheck,
  UserRoundSearch,
  Users,
  UsersRound,
} from 'lucide-react'

// One source of truth for the sidebar, route guards and the landing page.
// An entry is either a link, or a menu (`children`) that opens and closes.
// `permission` is checked against the list returned by GET /api/me.
export const NAV = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  { to: '/billing', label: 'New Bill', icon: ReceiptText, permission: 'billing.create' },
  { to: '/orders', label: 'Order History', icon: History, permission: 'orders.view' },
  { to: '/inventory', label: 'Inventory', icon: Package, permission: 'products.view' },
  { to: '/employees', label: 'Employees', icon: Users, permission: 'settings.manage' },
  {
    key: 'reports',
    label: 'Reports',
    icon: BarChart3,
    children: [
      { to: '/reports/orders', label: 'Order Report', icon: ClipboardList, permission: 'reports.view' },
      { to: '/reports/customers', label: 'Customer Report', icon: UserRoundSearch, permission: 'reports.view' },
      { to: '/reports/stock', label: 'Stock Report', icon: Boxes, permission: 'reports.view' },
      { to: '/reports/employees', label: 'Employee Report', icon: UsersRound, permission: 'reports.view' },
    ],
  },
  {
    key: 'settings',
    label: 'Settings',
    icon: Settings,
    children: [
      { to: '/settings/roles', label: 'Roles & Permissions', icon: ShieldCheck, permission: 'settings.manage' },
      { to: '/settings/password-reset', label: 'Password Reset', icon: KeyRound, permission: 'settings.manage' },
    ],
  },
]

/** The entries this user may see; a menu with no visible children is hidden. */
export const visibleNav = (can) =>
  NAV.map((entry) => (entry.children ? { ...entry, children: entry.children.filter((c) => can(c.permission)) } : entry)).filter(
    (entry) => (entry.children ? entry.children.length > 0 : can(entry.permission)),
  )

const links = (entries) => entries.flatMap((entry) => entry.children ?? [entry])

/** First page this user may open; used after login and for "/". */
export const homePath = (can) => links(visibleNav(can))[0]?.to ?? '/profile'

export const isWithin = (pathname, to) => pathname === to || pathname.startsWith(`${to}/`)

export const titleFor = (pathname) =>
  links(NAV).find((item) => isWithin(pathname, item.to))?.label ??
  { '/profile': 'My Profile', '/change-password': 'Change Password' }[pathname] ??
  ''
