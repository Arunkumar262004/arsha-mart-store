import {
  ArrowLeftRight,
  BadgeCheck,
  BarChart3,
  BookOpen,
  Boxes,
  Building2,
  CalendarCheck,
  ClipboardList,
  CornerUpLeft,
  FileSignature,
  FileText,
  HandCoins,
  History,
  KeyRound,
  Landmark,
  LayoutDashboard,
  ListTree,
  NotebookPen,
  Package,
  PackageCheck,
  Percent,
  PieChart,
  ReceiptText,
  Scale,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Truck,
  Undo2,
  UserRoundSearch,
  Users,
  UsersRound,
  Wallet,
} from 'lucide-react'

// One source of truth for the sidebar, route guards and the landing page.
// An entry is either a link, or a menu (`children`) that opens and closes.
// `permission` is checked against the list returned by GET /api/me.
export const NAV = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  { to: '/billing', label: 'New Bill', icon: ReceiptText, permission: 'billing.create' },
  {
    key: 'sales',
    label: 'Sales',
    icon: FileText,
    children: [
      { to: '/orders', label: 'Order History', icon: History, permission: 'orders.view' },
      { to: '/invoices', label: 'Tax Invoices', icon: FileText, permission: 'orders.view' },
      { to: '/quotations', label: 'Quotations', icon: FileSignature, permission: 'quotations.manage' },
      { to: '/challans', label: 'Delivery Challans', icon: Truck, permission: 'challans.manage' },
      { to: '/sales-returns', label: 'Sales Returns', icon: Undo2, permission: 'returns.manage' },
    ],
  },
  {
    key: 'inventory',
    label: 'Inventory',
    icon: Package,
    children: [
      { to: '/inventory', label: 'Products & Stock', icon: Package, permission: 'products.view' },
      { to: '/transfers', label: 'Stock Transfers', icon: ArrowLeftRight, permission: 'transfers.manage' },
    ],
  },
  {
    key: 'purchases',
    label: 'Purchases',
    icon: ShoppingCart,
    children: [
      { to: '/suppliers', label: 'Suppliers', icon: Building2, permission: 'suppliers.manage' },
      { to: '/purchases', label: 'Purchases', icon: PackageCheck, permission: 'purchases.manage' },
      { to: '/purchase-returns', label: 'Purchase Returns', icon: CornerUpLeft, permission: 'returns.manage' },
    ],
  },
  {
    key: 'accounts',
    label: 'Accounts',
    icon: Landmark,
    children: [
      { to: '/accounts/receipts', label: 'Receipts', icon: HandCoins, permission: 'payments.manage' },
      { to: '/accounts/payments', label: 'Payments', icon: Wallet, permission: 'payments.manage' },
      { to: '/accounts/expenses', label: 'Expenses', icon: ReceiptText, permission: 'expenses.manage' },
      { to: '/accounts/vouchers', label: 'Day Book & Vouchers', icon: NotebookPen, permission: 'accounts.view' },
      { to: '/accounts/ledger', label: 'Ledgers', icon: BookOpen, permission: 'accounts.view' },
      { to: '/accounts/chart', label: 'Chart of Accounts', icon: ListTree, permission: 'accounts.view' },
      { to: '/accounts/outstanding', label: 'Outstanding', icon: UsersRound, permission: 'accounts.view' },
      { to: '/accounts/day-closing', label: 'Day Closing', icon: CalendarCheck, permission: 'accounts.view' },
      { to: '/accounts/statements', label: 'Financial Statements', icon: Scale, permission: 'accounts.view' },
      { to: '/accounts/gst', label: 'GST Reports', icon: Percent, permission: 'accounts.view' },
    ],
  },
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
      { to: '/reports/sales-analysis', label: 'Sales Analysis', icon: PieChart, permission: 'reports.view' },
    ],
  },
  {
    key: 'settings',
    label: 'Settings',
    icon: Settings,
    children: [
      { to: '/settings/company', label: 'Company', icon: BadgeCheck, permission: 'settings.manage' },
      { to: '/settings/stores', label: 'Stores', icon: Building2, permission: 'settings.manage' },
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
