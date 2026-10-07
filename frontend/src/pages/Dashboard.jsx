import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ArrowDownRight,
  ArrowUpRight,
  Building2,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  CircleCheck,
  Clock3,
  Download,
  IndianRupee,
  Package,
  PackageX,
  Plus,
  ReceiptText,
  TrendingUp,
  TriangleAlert,
  UserPlus,
  Users,
  Wallet,
} from 'lucide-react'
import { getDashboard } from '../api'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Donut, MonthlyBars, SparkBars } from '../components/dashboard/Charts'
import { MODE_COLORS, MONTHS, NEUTRAL, SERIES, count, rupees } from '../components/dashboard/format'
import TaxInvoiceModal from '../components/documents/TaxInvoiceModal'
import { paymentLabel } from '../components/receipt/paymentModes'
import { Alert, EmptyState, Spinner } from '../components/ui'

const greeting = () => {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
}
const date = (iso) => new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
const time = (iso) => new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' })

export default function Dashboard() {
  const { user, can, currentStore, isAllStores } = useAuth()
  const [data, setData] = useState(null)
  const [year, setYear] = useState(null)
  const [error, setError] = useState(null)

  const load = useCallback(() => {
    getDashboard(year)
      .then(setData)
      .catch((e) => setError(parseApiError(e).message))
  }, [year])

  useEffect(load, [load])

  if (error) return <Alert>{error}</Alert>
  if (!data) {
    return (
      <div className="grid place-items-center py-24 text-slate-400">
        <Spinner size={28} />
      </div>
    )
  }

  const o = data.overview
  const k = o.kpis
  const storeName = isAllStores ? 'All stores' : currentStore?.name ?? 'Store'

  return (
    <div className="space-y-5">
      <TodayBanner o={o} user={user} storeName={storeName} can={can} />

      {/* KPI cards */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard icon={IndianRupee} label="Total Sales" kpi={k.sales} display={rupees} color={SERIES[0]} />
        <KpiCard icon={ReceiptText} label="Total Bills" kpi={k.orders} display={count} color={SERIES[2]} />
        <KpiCard icon={Users} label="Customers" kpi={k.customers} display={count} color={SERIES[1]} />
        <KpiCard icon={TrendingUp} label="Gross Profit" kpi={k.profit} display={rupees} color={SERIES[3]} />
      </div>

      {/* Sales overview + best sellers */}
      <div className="grid gap-4 xl:grid-cols-3">
        <Panel
          title="Sales Overview"
          className="xl:col-span-2"
          action={
            <label className="relative inline-flex items-center">
              <select
                value={o.monthly.year}
                onChange={(e) => setYear(Number(e.target.value))}
                className="appearance-none rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-3 pr-8 text-xs font-medium text-slate-700 outline-none focus:border-brand-500"
                aria-label="Year"
              >
                {o.monthly.years.map((y) => <option key={y} value={y}>{y}</option>)}
              </select>
              <ChevronDown size={14} className="pointer-events-none absolute right-2.5 text-slate-400" aria-hidden />
            </label>
          }
        >
          <div className="flex flex-wrap items-end gap-x-6 gap-y-2">
            <p className="flex items-baseline gap-2">
              <span className="text-3xl font-semibold tracking-tight text-slate-900">{rupees(o.monthly.total)}</span>
              <span className="text-sm text-slate-500">total sales in {o.monthly.year}</span>
            </p>
            <p className="text-xs text-slate-500">
              Best month:{' '}
              <b className="font-semibold text-slate-800">{bestMonth(o.monthly.months)}</b>
            </p>
          </div>
          <MonthlyBars
            months={o.monthly.months}
            highlight={o.monthly.year === new Date().getFullYear() ? new Date().getMonth() : null}
            color={SERIES[0]}
          />
        </Panel>

        <Panel title="Best Selling Products" link={can('reports.view') && { to: '/reports/sales-analysis', label: 'View all' }}>
          {o.best_sellers.length === 0 ? (
            <EmptyState icon={Package} title="No sales this month yet" />
          ) : (
            <ul className="space-y-1">
              {o.best_sellers.map((p, i) => (
                <li key={p.product_id ?? p.name} className="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-slate-50">
                  <span
                    className="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-sm font-semibold"
                    style={{ background: `${SERIES[i % SERIES.length]}14`, color: SERIES[i % SERIES.length] }}
                  >
                    {p.name.slice(0, 2).toUpperCase()}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-slate-800">{p.name}</span>
                    <span className="block text-xs text-slate-500">
                      {count(p.units)} {p.unit} sold{p.category ? ` · ${p.category}` : ''}
                    </span>
                  </span>
                  <span className="text-right">
                    <span className="block text-sm font-semibold tabular-nums text-slate-900">{rupees(p.total)}</span>
                    <Change value={p.change} compact />
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      {/* Payment modes · inventory · store */}
      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <Panel title="Payment Modes">
          <Donut
            segments={o.payment_modes.map((m) => ({
              key: m.mode,
              label: paymentLabel(m.mode),
              value: m.orders,
              color: MODE_COLORS[m.mode] ?? NEUTRAL,
              display: `${count(m.orders)} bills · ${rupees(m.total)}`,
            }))}
            centre={count(k.orders.value)}
            centreLabel="Bills this month"
            format={(v) => count(v)}
          />
        </Panel>

        <Panel title="Inventory Summary" link={can('products.view') && { to: '/inventory', label: 'View all' }}>
          <div className="grid grid-cols-2 gap-3">
            <InventoryTile label="Total Products" value={count(o.inventory.products)} sub="In the catalogue" icon={Package} tone="blue" />
            <InventoryTile label="Low Stock" value={count(o.inventory.low_stock)} sub={`Below ${o.inventory.threshold} units`} icon={TriangleAlert} tone="amber" />
            <InventoryTile label="Out of Stock" value={count(o.inventory.out_of_stock)} sub="Products" icon={PackageX} tone="red" />
            <InventoryTile label="Stock Value" value={rupees(o.inventory.value)} sub="At cost price" icon={Wallet} tone="teal" />
          </div>
        </Panel>

        <Panel title="Store Overview">
          <ul className="space-y-1">
            <StoreRow icon={Building2} tone="blue" label="Total branches" value={count(o.store.branches)} />
            <StoreRow icon={Users} tone="violet" label="Staff" value={count(o.store.employees)} />
            <StoreRow icon={IndianRupee} tone="teal" label="Today's sales" value={rupees(o.store.today_sales)} />
            <StoreRow icon={ReceiptText} tone="amber" label="Today's bills" value={count(o.store.today_orders)} />
            <StoreRow icon={UserPlus} tone="slate" label="New customers today" value={count(o.store.new_customers)} />
          </ul>
        </Panel>
      </div>

      {/* Recent bills + categories */}
      <div className="grid gap-4 xl:grid-cols-3">
        <RecentBills rows={o.recent} can={can} />

        <Panel title="Sales by Category" link={can('reports.view') && { to: '/reports/sales-analysis', label: 'View all' }}>
          {o.categories.length === 0 ? (
            <EmptyState icon={Package} title="No sales this month yet" />
          ) : (
            <Donut
              segments={o.categories.map((c, i) => ({
                key: c.name,
                label: c.name,
                value: Number(c.total),
                color: c.name === 'Others' ? NEUTRAL : SERIES[i] ?? NEUTRAL,
                display: rupees(c.total),
              }))}
              centre={rupees(o.categories.reduce((s, c) => s + Number(c.total), 0))}
              centreLabel="Sales this month"
              format={rupees}
            />
          )}
        </Panel>
      </div>
    </div>
  )
}

/** Greeting, today's figures and the main actions: light and calm. */
function TodayBanner({ o, user, storeName, can }) {
  const today = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  const stats = [
    { label: "Today's sales", value: rupees(o.store.today_sales), icon: IndianRupee, tone: 'emerald' },
    { label: "Today's bills", value: count(o.store.today_orders), icon: ReceiptText, tone: 'violet' },
    { label: 'Collected this month', value: rupees(o.kpis.collected.value), icon: CircleCheck, tone: 'teal' },
    { label: 'Customer dues', value: rupees(o.kpis.outstanding.value), icon: Clock3, tone: 'amber' },
  ]

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="flex items-center gap-1.5 text-xs text-slate-500">
            <CalendarDays size={14} aria-hidden /> {today}
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
            {greeting()}, {user.name.split(' ')[0]}
          </h1>
          <p className="mt-0.5 text-sm text-slate-500">
            Here is how <b className="font-medium text-slate-700">{storeName}</b> is doing.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => exportCsv(o, storeName)}
            className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
          >
            <Download size={16} aria-hidden /> Export
          </button>
          {can('billing.create') && (
            <Link
              to="/billing"
              className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800"
            >
              <Plus size={16} aria-hidden /> New bill
            </Link>
          )}
        </div>
      </div>

      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map(({ label, value, icon: Icon, tone }) => (
          <div key={label} className="flex items-center gap-3 rounded-2xl border border-slate-200/80 bg-white px-4 py-3.5 shadow-sm">
            <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${TONES[tone]}`}>
              <Icon size={18} aria-hidden />
            </span>
            <div className="min-w-0">
              <dt className="truncate text-xs text-slate-500">{label}</dt>
              <dd className="truncate text-lg font-semibold tabular-nums text-slate-900">{value}</dd>
            </div>
          </div>
        ))}
      </dl>
    </section>
  )
}

function Panel({ title, action, link, className = '', padded = true, children }) {
  return (
    <section className={`rounded-2xl border border-slate-200/80 bg-white shadow-sm transition hover:shadow-md ${className}`}>
      <div className="flex items-center justify-between gap-2 px-5 pb-3 pt-5">
        <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
        {action}
        {link && (
          <Link to={link.to} className="inline-flex items-center gap-0.5 text-xs font-medium text-slate-500 hover:text-brand-600">
            {link.label} <ChevronRight size={14} aria-hidden />
          </Link>
        )}
      </div>
      <div className={padded ? 'px-5 pb-5' : ''}>{children}</div>
    </section>
  )
}

function KpiCard({ icon: Icon, label, kpi, display, color }) {
  return (
    <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
      <div className="flex items-center justify-between gap-2">
        <p className="flex items-center gap-2.5 text-sm font-medium text-slate-600">
          <span className="grid h-9 w-9 place-items-center rounded-xl" style={{ background: `${color}14`, color }}>
            <Icon size={18} aria-hidden />
          </span>
          {label}
        </p>
        <Change value={kpi.change} pill />
      </div>
      <p className="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-slate-900">{display(kpi.value)}</p>
      <p className="mt-0.5 text-xs text-slate-500">
        {kpi.change === null ? 'This month · nothing to compare yet' : `This month · ${display(kpi.previous)} same days last month`}
      </p>
      <SparkBars series={kpi.series} color={color} format={display} label={label} />
    </section>
  )
}

/** "+12.5%" in green or "−3.1%" in red, with an arrow so it reads without colour. */
function Change({ value, compact = false, pill = false }) {
  if (value === null || value === undefined) {
    if (pill) return <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">New</span>
    return compact ? <span className="block text-xs text-slate-400">New</span> : null
  }
  const up = value >= 0
  const Icon = up ? ArrowUpRight : ArrowDownRight
  return (
    <span
      className={`inline-flex items-center gap-0.5 text-xs font-semibold ${up ? 'text-emerald-600' : 'text-red-600'} ${
        pill ? `rounded-full px-2 py-0.5 ${up ? 'bg-emerald-50' : 'bg-red-50'}` : ''
      } ${compact ? 'justify-end' : ''}`}
    >
      <Icon size={13} aria-hidden />
      {up ? '+' : '−'}
      {Math.abs(value).toFixed(1)}%
    </span>
  )
}

const TONES = {
  blue: 'bg-blue-50 text-blue-600',
  amber: 'bg-amber-50 text-amber-600',
  red: 'bg-red-50 text-red-600',
  teal: 'bg-teal-50 text-teal-600',
  emerald: 'bg-emerald-50 text-emerald-600',
  violet: 'bg-violet-50 text-violet-600',
  slate: 'bg-slate-100 text-slate-600',
}

function InventoryTile({ label, value, sub, icon: Icon, tone }) {
  return (
    <div className="flex items-start justify-between gap-2 rounded-xl border border-slate-200/80 bg-gradient-to-b from-white to-slate-50/60 p-3.5">
      <div className="min-w-0">
        <p className="text-xs font-medium text-slate-600">{label}</p>
        <p className="mt-1 truncate text-lg font-semibold tabular-nums text-slate-900">{value}</p>
        <p className="text-[11px] text-slate-400">{sub}</p>
      </div>
      <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-lg ${TONES[tone]}`}>
        <Icon size={17} aria-hidden />
      </span>
    </div>
  )
}

function StoreRow({ icon: Icon, tone, label, value }) {
  return (
    <li className="flex items-center gap-3 rounded-lg px-1 py-2">
      <span className={`grid h-8 w-8 place-items-center rounded-lg ${TONES[tone]}`}>
        <Icon size={16} aria-hidden />
      </span>
      <span className="flex-1 text-sm text-slate-600">{label}</span>
      <span className="text-sm font-semibold tabular-nums text-slate-900">{value}</span>
    </li>
  )
}

const PILLS = {
  green: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  amber: 'border-amber-200 bg-amber-50 text-amber-700',
  slate: 'border-slate-200 bg-slate-50 text-slate-600',
}

function Pill({ tone, children }) {
  return <span className={`inline-block rounded-md border px-2 py-0.5 text-xs font-medium ${PILLS[tone]}`}>{children}</span>
}

function RecentBills({ rows, can }) {
  const [viewing, setViewing] = useState(null)
  const canView = can('orders.view') || can('billing.create')

  return (
    <Panel title="Recent Bills" className="xl:col-span-2" padded={false} link={can('orders.view') && { to: '/invoices', label: 'View all' }}>
      {rows.length === 0 ? (
        <EmptyState icon={ReceiptText} title="No bills yet" />
      ) : (
        <div className="overflow-x-auto px-5 pb-5">
          <table className="w-full min-w-[680px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-xs font-medium text-slate-500">
                <th className="rounded-l-lg px-3 py-2.5">Invoice</th>
                <th className="px-3 py-2.5">Customer</th>
                <th className="px-3 py-2.5">Date</th>
                <th className="px-3 py-2.5">Status</th>
                <th className="px-3 py-2.5 text-right">Amount</th>
                <th className="px-3 py-2.5">Payment</th>
                <th className="rounded-r-lg px-3 py-2.5" aria-label="Actions" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((r) => (
                <tr key={r.id} className="hover:bg-slate-50/70">
                  <td className="px-3 py-3 font-medium text-slate-800">{r.invoice_number}</td>
                  <td className="px-3 py-3">
                    <span className="text-slate-700">{r.customer}</span>
                    <span className="block text-xs text-slate-400">{r.items} item{r.items === 1 ? '' : 's'}</span>
                  </td>
                  <td className="px-3 py-3 text-slate-600">
                    {date(r.created_at)}
                    <span className="block text-xs text-slate-400">{time(r.created_at)}</span>
                  </td>
                  <td className="px-3 py-3">
                    <Pill tone={r.status === 'paid' ? 'green' : 'amber'}>{r.status === 'paid' ? 'Paid' : 'Due'}</Pill>
                  </td>
                  <td className="px-3 py-3 text-right font-medium tabular-nums text-slate-900">{rupees(r.grand_total)}</td>
                  <td className="px-3 py-3">
                    <Pill tone="slate">{paymentLabel(r.payment_mode)}</Pill>
                  </td>
                  <td className="px-3 py-3 text-right">
                    {canView && (
                      <button
                        type="button"
                        onClick={() => setViewing(r.id)}
                        className="rounded-md bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-100"
                      >
                        View
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {viewing && <TaxInvoiceModal orderId={viewing} onClose={() => setViewing(null)} />}
    </Panel>
  )
}

const bestMonth = (months) => {
  const best = months.reduce((a, m) => (Number(m.total) > Number(a.total) ? m : a), months[0])
  return Number(best.total) > 0 ? `${MONTHS[best.month - 1]} · ${rupees(best.total)}` : '—'
}

/** The dashboard's figures as a CSV the owner can open in Excel. */
function exportCsv(o, storeName) {
  const k = o.kpis
  const rows = [
    ['Dashboard', storeName, new Date().toLocaleString('en-IN')],
    [],
    ['This month', 'Value', 'Same days last month', 'Change %'],
    ['Total sales', k.sales.value, k.sales.previous, k.sales.change ?? ''],
    ['Total bills', k.orders.value, k.orders.previous, k.orders.change ?? ''],
    ['Customers', k.customers.value, k.customers.previous, k.customers.change ?? ''],
    ['Gross profit', k.profit.value, k.profit.previous, k.profit.change ?? ''],
    ['Collected', k.collected.value, k.collected.previous, k.collected.change ?? ''],
    ['Customer dues', k.outstanding.value, k.outstanding.previous, k.outstanding.change ?? ''],
    [],
    [`Monthly sales ${o.monthly.year}`, 'Sales', 'Bills'],
    ...o.monthly.months.map((m) => [MONTHS[m.month - 1], m.total, m.orders]),
    [],
    ['Best selling products', 'Units', 'Sales', 'Change %'],
    ...o.best_sellers.map((p) => [p.name, p.units, p.total, p.change ?? '']),
    [],
    ['Payment mode', 'Bills', 'Sales'],
    ...o.payment_modes.map((m) => [paymentLabel(m.mode), m.orders, m.total]),
    [],
    ['Category', 'Sales'],
    ...o.categories.map((c) => [c.name, c.total]),
    [],
    ['Inventory', 'Products', o.inventory.products, 'Low stock', o.inventory.low_stock, 'Out of stock', o.inventory.out_of_stock, 'Stock value', o.inventory.value],
  ]
  const csv = rows.map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n')
  const url = URL.createObjectURL(new Blob(['﻿', csv], { type: 'text/csv;charset=utf-8' }))
  const a = Object.assign(document.createElement('a'), {
    href: url,
    download: `dashboard-${storeName.replace(/\s+/g, '-').toLowerCase()}-${new Date().toISOString().slice(0, 10)}.csv`,
  })
  a.click()
  URL.revokeObjectURL(url)
}
