import { BarChart3, IndianRupee, Percent, ReceiptText, ShoppingBag } from 'lucide-react'
import ReportFilters from '../../components/reports/ReportFilters'
import useReport from '../../components/reports/useReport'
import { Alert, Card, EmptyState, PageHeader, Spinner, StatTile } from '../../components/ui'
import { PAYMENT_MODES, headRowClass, money, tdClass, thClass } from '../../components/accounts/format'

const pct = (value) => (value == null ? '—' : `${value}%`)

/** A thin horizontal bar, as a share of the largest value in the list. */
function Bar({ value, max }) {
  const width = max > 0 ? Math.max(2, (Number(value) / max) * 100) : 0
  return (
    <div className="h-2 w-full rounded-full bg-slate-100">
      <div className="h-2 rounded-full bg-brand-500" style={{ width: `${width}%` }} />
    </div>
  )
}

export default function SalesAnalysis() {
  // /api/reports/sales-analysis answers { data, period }, like the other reports.
  const { filters, setFilters, result, loading, error, incomplete } = useReport('sales-analysis')
  const data = result?.data
  const s = data?.summary

  return (
    <div className="space-y-6">
      <PageHeader title="Sales Analysis" description="Sales by category with margin, payment mode, store, day and hour, and the best products." />

      <ReportFilters filters={filters} setFilters={setFilters} period={result?.period} loading={loading} />

      {error && <Alert>{error}</Alert>}
      {!data && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}

      {s && !incomplete && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Revenue" value={money(s.revenue)} sub={`Taxable ${money(s.taxable_sales)}`} icon={IndianRupee} />
            <StatTile label="Bills" value={s.bills} sub={`Average ${money(s.average_bill)}`} icon={ReceiptText} tone="green" />
            <StatTile label="Units sold" value={s.units} icon={ShoppingBag} tone="amber" />
            <StatTile label="Gross margin" value={money(s.gross_margin)} sub={`${pct(s.margin_percent)} on costed sales`} icon={Percent} tone="brand" />
          </div>

          {s.bills === 0 ? (
            <Card><EmptyState icon={BarChart3} title="No sales in this period" /></Card>
          ) : (
            <>
              <CategoryTable rows={data.categories} />

              <div className="grid gap-6 lg:grid-cols-2">
                <ShareCard title="By payment mode" rows={data.payment_modes.map((m) => ({ key: m.mode, label: PAYMENT_MODES[m.mode] ?? m.mode, bills: m.bills, total: m.total }))} />
                {data.stores.length > 1 ? (
                  <Card title="By store" padded={false}>
                    <table className="w-full text-sm">
                      <thead>
                        <tr className={headRowClass}>
                          <th className={thClass}>Store</th>
                          <th className={`${thClass} text-right`}>Bills</th>
                          <th className={`${thClass} text-right`}>Sales</th>
                          <th className={`${thClass} text-right`}>Avg bill</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {data.stores.map((st) => (
                          <tr key={st.store_id}>
                            <td className={`${tdClass} font-medium`}>{st.store}</td>
                            <td className={`${tdClass} text-right tabular-nums`}>{st.bills}</td>
                            <td className={`${tdClass} text-right tabular-nums`}>{money(st.total)}</td>
                            <td className={`${tdClass} text-right tabular-nums`}>{money(st.average_bill)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </Card>
                ) : (
                  <ShareCard title="By day of week" rows={data.weekdays.map((d) => ({ key: d.day, label: d.day, bills: d.bills, total: d.total }))} />
                )}
              </div>

              <div className="grid gap-6 lg:grid-cols-2">
                {data.stores.length > 1 && <ShareCard title="By day of week" rows={data.weekdays.map((d) => ({ key: d.day, label: d.day, bills: d.bills, total: d.total }))} />}
                <ShareCard
                  title="By hour of day"
                  rows={data.hours.filter((h) => h.bills > 0).map((h) => ({ key: h.hour, label: `${String(h.hour).padStart(2, '0')}:00`, bills: h.bills, total: h.total }))}
                />
              </div>

              <div className="grid gap-6 lg:grid-cols-2">
                <TopProducts title="Top 10 by revenue" rows={data.top_by_revenue} field="sales" />
                <TopProducts title="Top 10 by margin" rows={data.top_by_margin} field="margin" />
              </div>
            </>
          )}

          <p className="text-xs text-slate-500">
            {data.cost_note}
            {s.uncosted_units > 0 && ` ${s.uncosted_units} unit(s) sold had no cost price.`}
          </p>
        </>
      )}
    </div>
  )
}

function CategoryTable({ rows }) {
  const max = Math.max(...rows.map((r) => Number(r.sales)), 0)
  return (
    <Card title="By category" padded={false}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[820px] text-sm">
          <thead>
            <tr className={headRowClass}>
              <th className={thClass}>Category</th>
              <th className={`${thClass} text-right`}>Bills</th>
              <th className={`${thClass} text-right`}>Units</th>
              <th className={`${thClass} text-right`}>Taxable sales</th>
              <th className={`${thClass} w-40`} />
              <th className={`${thClass} text-right`}>Cost*</th>
              <th className={`${thClass} text-right`}>Margin</th>
              <th className={`${thClass} text-right`}>Margin %</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r) => (
              <tr key={r.category} className="hover:bg-slate-50/60">
                <td className={`${tdClass} font-medium text-slate-800`}>{r.category}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{r.bills}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{r.units}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(r.sales)}</td>
                <td className={tdClass}><Bar value={r.sales} max={max} /></td>
                <td className={`${tdClass} text-right tabular-nums text-slate-600`}>{money(r.cost)}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(r.margin)}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{pct(r.margin_percent)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

function ShareCard({ title, rows }) {
  const max = Math.max(...rows.map((r) => Number(r.total)), 0)
  return (
    <Card title={title}>
      {rows.length === 0 ? (
        <p className="text-sm text-slate-500">No bills.</p>
      ) : (
        <div className="space-y-3">
          {rows.map((r) => (
            <div key={r.key}>
              <div className="mb-1 flex justify-between gap-3 text-sm">
                <span className="text-slate-700">{r.label}</span>
                <span className="tabular-nums text-slate-600">{money(r.total)} · {r.bills} bills</span>
              </div>
              <Bar value={r.total} max={max} />
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}

function TopProducts({ title, rows, field }) {
  return (
    <Card title={title} padded={false}>
      {rows.length === 0 ? (
        <p className="px-5 pb-5 text-sm text-slate-500">Nothing to show.</p>
      ) : (
        <table className="w-full text-sm">
          <thead>
            <tr className={headRowClass}>
              <th className={thClass}>#</th>
              <th className={thClass}>Product</th>
              <th className={`${thClass} text-right`}>Units</th>
              <th className={`${thClass} text-right`}>{field === 'sales' ? 'Sales' : 'Margin'}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((p, i) => (
              <tr key={p.id}>
                <td className={`${tdClass} text-slate-400`}>{i + 1}</td>
                <td className={tdClass}>
                  <div className="font-medium text-slate-800">{p.name}</div>
                  <div className="text-xs text-slate-500">{p.category}</div>
                </td>
                <td className={`${tdClass} text-right tabular-nums`}>{p.units}</td>
                <td className={`${tdClass} text-right tabular-nums`}>
                  {money(p[field])}
                  {field === 'margin' && <div className="text-xs text-slate-500">{pct(p.margin_percent)}</div>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  )
}
