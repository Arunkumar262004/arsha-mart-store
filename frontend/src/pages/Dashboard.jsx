import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight, Boxes, IndianRupee, PackageX, ReceiptText, ShoppingCart } from 'lucide-react'
import { getDashboard } from '../api'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import ProductShareChart from '../components/ProductShareChart'
import SalesChart from '../components/SalesChart'
import { Alert, Badge, Button, Card, EmptyState, Spinner, StatTile } from '../components/ui'
import { formatINR } from '../lib/money'

const greeting = () => {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
}

const time = (iso) => new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })

export default function Dashboard() {
  const { user, can, currentStore, isAllStores } = useAuth()
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  const load = useCallback(() => {
    getDashboard()
      .then(setData)
      .catch((e) => setError(parseApiError(e).message))
  }, [])

  useEffect(load, [load])

  if (error) return <Alert>{error}</Alert>
  if (!data) {
    return (
      <div className="grid place-items-center py-24 text-slate-400">
        <Spinner size={28} />
      </div>
    )
  }

  const { billing, stock } = data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-slate-900">
            {greeting()}, {user.name.split(' ')[0]}
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {isAllStores ? (
              <>Here's how <b className="font-medium text-slate-700">all stores</b> are doing today.</>
            ) : (
              <>Here's how <b className="font-medium text-slate-700">{currentStore?.name ?? 'the store'}</b> is doing today.</>
            )}
          </p>
        </div>
        {can('billing.create') && (
          <Link to="/billing">
            <Button icon={ReceiptText}>New bill</Button>
          </Link>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Today's sales" value={formatINR(billing.today_sales)} sub={`${billing.today_orders} bill${billing.today_orders === 1 ? '' : 's'}`} icon={IndianRupee} tone="green" />
        <StatTile label="This month" value={formatINR(billing.month_sales)} sub={`${billing.month_orders} bills`} icon={ShoppingCart} />
        <StatTile label="Products" value={stock.products} sub={`${stock.units.toLocaleString('en-IN')} units in stock`} icon={Boxes} />
        <StatTile
          label="Needs restock"
          value={stock.low_stock + stock.out_of_stock}
          sub={`${stock.out_of_stock} out of stock · ${stock.low_stock} low`}
          icon={PackageX}
          tone={stock.out_of_stock ? 'red' : 'amber'}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        {/* Left: sales + low stock, stacked so both columns end level */}
        <div className="flex flex-col gap-6 xl:col-span-2">
          <Card title="Sales, last 7 days">
            <SalesChart days={billing.last_7_days} />
          </Card>
          <LowStockCard stock={stock} can={can} />
        </div>
        <Card title="Top products this month">
          <ProductShareChart products={billing.top_products ?? []} />
        </Card>
      </div>

      <Card title="Recent bills" padded={false}
        actions={can('orders.view') && <Link to="/orders" className="text-xs font-medium text-brand-600">Order history</Link>}
      >
        {billing.recent_orders.length === 0 ? (
          <EmptyState icon={ReceiptText} title="No bills yet" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[480px] text-sm">
              <thead>
                <tr className="border-y border-slate-100 bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-2.5">Bill</th>
                  <th className="px-5 py-2.5">Customer</th>
                  <th className="px-5 py-2.5">Time</th>
                  <th className="px-5 py-2.5 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {billing.recent_orders.map((o) => (
                  <tr key={o.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3 font-medium text-slate-800">{o.invoice_number || o.order_number}</td>
                    <td className="px-5 py-3">
                      {can('orders.view') ? (
                        <Link to={`/orders?email=${encodeURIComponent(o.email)}`} className="hover:text-brand-600">
                          {o.customer}
                        </Link>
                      ) : (
                        o.customer
                      )}
                    </td>
                    <td className="px-5 py-3 text-slate-500">{time(o.created_at)}</td>
                    <td className="px-5 py-3 text-right font-medium tabular-nums">{formatINR(o.grand_total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}

/** Products below the low-stock threshold, with how far each is from it. */
function LowStockCard({ stock, can }) {
  return (
    <Card
      title="Low stock"
      className="flex-1"
      actions={can('products.view') && <Link to="/inventory?filter=low" className="text-xs font-medium text-brand-600">Inventory</Link>}
    >
      {stock.lowest.length === 0 ? (
        <EmptyState icon={Boxes} title="Everything is well stocked" />
      ) : (
        <ul className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
          {stock.lowest.map((p) => {
            const out = p.stock === 0
            const fill = Math.min(100, (p.stock / Math.max(1, stock.threshold)) * 100)
            return (
              <li key={p.id} className="flex items-center gap-3 border-b border-slate-100 py-2.5">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center justify-between gap-2">
                    <p className="truncate text-sm font-medium text-slate-800" title={p.name}>{p.name}</p>
                    {out ? <Badge tone="red">Out of stock</Badge> : <Badge tone="amber">{p.stock} left</Badge>}
                  </div>
                  <div className="mt-1.5 flex items-center gap-2">
                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden>
                      <div className={`h-full rounded-full ${out ? 'bg-red-500' : 'bg-amber-500'}`} style={{ width: `${Math.max(fill, out ? 0 : 4)}%` }} />
                    </div>
                    <span className="text-[11px] text-slate-400">{p.code}</span>
                  </div>
                </div>
                {can('stock.adjust') && (
                  <Link to={`/inventory?restock=${p.id}`} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-brand-600" title={`Restock ${p.name}`}>
                    <ArrowUpRight size={16} />
                  </Link>
                )}
              </li>
            )
          })}
        </ul>
      )}
      <p className="mt-3 text-xs text-slate-500">Showing products below {stock.threshold} units, lowest first.</p>
    </Card>
  )
}
