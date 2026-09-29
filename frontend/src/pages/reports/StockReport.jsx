import { Boxes, PackageMinus, PackagePlus, Wrench } from 'lucide-react'
import ReportFilters, { Pager } from '../../components/reports/ReportFilters'
import { formatDateTime, tdClass, thClass } from '../../components/reports/table'
import ReportDownloads from '../../components/reports/ReportDownloads'
import useReport from '../../components/reports/useReport'
import { Alert, Badge, Card, EmptyState, Field, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'

const TYPES = [
  ['adjustments', 'Adjustments only (no sales)'],
  ['all', 'All changes incl. sales'],
  ['restock', 'Restock'],
  ['correction', 'Correction'],
  ['initial', 'Opening stock'],
  ['sale', 'Sale'],
]

const TYPE_TONES = { sale: 'slate', restock: 'green', correction: 'amber', initial: 'brand' }
const TYPE_LABELS = { sale: 'Sale', restock: 'Restock', correction: 'Correction', initial: 'Opening stock' }

export default function StockReport() {
  const { filters, setFilters, query, result, loading, error, incomplete } = useReport('stock', { type: 'adjustments' })
  const summary = result?.summary

  return (
    <div className="space-y-6">
      <PageHeader
        title="Stock Report"
        description="Every stock change in the period and the employee who made it."
        actions={<ReportDownloads report="stock" query={query} disabled={incomplete} />}
      />

      <ReportFilters
        filters={filters}
        setFilters={setFilters}
        period={result?.period}
        loading={loading}
        employeeLabel="Adjusted by"
        searchPlaceholder="Product name or code"
      >
        <Field label="Change type">
          <select className={inputClass} value={filters.type} onChange={(e) => setFilters({ type: e.target.value })}>
            {TYPES.map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </Field>
      </ReportFilters>

      {error && <Alert>{error}</Alert>}

      {summary && !incomplete && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label="Stock changes" value={summary.entries} icon={Boxes} />
          <StatTile label="Units restocked" value={summary.units_restocked} sub="Restocks + opening stock" icon={PackagePlus} tone="green" />
          <StatTile label="Units sold" value={summary.units_sold} icon={PackageMinus} tone="brand" />
          <StatTile
            label="Corrections"
            value={summary.corrections}
            sub={`Net ${summary.correction_units > 0 ? '+' : ''}${summary.correction_units} units`}
            icon={Wrench}
            tone="amber"
          />
        </div>
      )}

      <Card padded={false}>
        {!result && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result?.data.length === 0 && <EmptyState icon={Boxes} title="No stock changes in this period" />}
        {result?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className={thClass}>Date</th>
                  <th className={thClass}>Product</th>
                  <th className={thClass}>Type</th>
                  <th className={`${thClass} text-right`}>Change</th>
                  <th className={`${thClass} text-right`}>Stock after</th>
                  <th className={thClass}>Adjusted by</th>
                  <th className={thClass}>Note / bill</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((m) => (
                  <tr key={m.id} className="hover:bg-slate-50/60">
                    <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDateTime(m.created_at)}</td>
                    <td className={tdClass}>
                      <div className="font-medium text-slate-800">{m.product?.name ?? 'Deleted product'}</div>
                      {m.product?.code && <div className="text-xs text-slate-500">{m.product.code}</div>}
                    </td>
                    <td className={tdClass}><Badge tone={TYPE_TONES[m.type]}>{TYPE_LABELS[m.type] ?? m.type}</Badge></td>
                    <td className={`${tdClass} text-right font-semibold tabular-nums ${m.quantity > 0 ? 'text-emerald-700' : 'text-red-600'}`}>
                      {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                    </td>
                    <td className={`${tdClass} text-right tabular-nums`}>{m.stock_after}</td>
                    <td className={`${tdClass} text-slate-700`}>{m.user ?? '—'}</td>
                    <td className={`${tdClass} text-slate-600`}>{m.order_number ?? m.note ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={(page) => setFilters({ page })} />
      </Card>
    </div>
  )
}
