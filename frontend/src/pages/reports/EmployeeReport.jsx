import { Boxes, IndianRupee, ReceiptText, UserCheck, UsersRound } from 'lucide-react'
import { Link } from 'react-router-dom'
import ReportFilters from '../../components/reports/ReportFilters'
import { formatDateTime, tdClass, thClass } from '../../components/reports/table'
import ReportDownloads from '../../components/reports/ReportDownloads'
import useReport from '../../components/reports/useReport'
import { Alert, Badge, Card, EmptyState, PageHeader, Spinner, StatTile } from '../../components/ui'
import { formatINR } from '../../lib/money'

export default function EmployeeReport() {
  const { filters, setFilters, query, result, loading, error, incomplete } = useReport('employees')
  const summary = result?.summary
  const keepPeriod = (extra) =>
    new URLSearchParams({
      period: filters.period,
      ...(filters.period === 'custom' ? { from: filters.from, to: filters.to } : {}),
      ...extra,
    }).toString()

  return (
    <div className="space-y-6">
      <PageHeader
        title="Employee Report"
        description="Bills, sales and stock changes made by each employee in the period."
        actions={<ReportDownloads report="employees" query={query} disabled={incomplete} />}
      />

      <ReportFilters
        filters={filters}
        setFilters={setFilters}
        period={result?.period}
        loading={loading}
        employeeLabel="Employee"
        searchPlaceholder="Name or email"
      />

      {error && <Alert>{error}</Alert>}

      {summary && !incomplete && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label="Employees billing" value={summary.active_billers} sub={`of ${summary.employees} employees`} icon={UserCheck} />
          <StatTile label="Bills" value={summary.orders} icon={ReceiptText} tone="amber" />
          <StatTile label="Sales" value={formatINR(summary.sales_total)} icon={IndianRupee} tone="green" />
          <StatTile label="Stock adjustments" value={summary.adjustments} icon={Boxes} />
        </div>
      )}

      <Card padded={false}>
        {!result && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result?.data.length === 0 && <EmptyState icon={UsersRound} title="No employees match" />}
        {result?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className={thClass}>Employee</th>
                  <th className={thClass}>Role</th>
                  <th className={`${thClass} text-right`}>Bills</th>
                  <th className={`${thClass} text-right`}>Customers</th>
                  <th className={`${thClass} text-right`}>Average bill</th>
                  <th className={`${thClass} text-right`}>Sales</th>
                  <th className={`${thClass} text-right`}>Stock changes</th>
                  <th className={thClass}>Last bill</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((e) => (
                  <tr key={e.id} className="hover:bg-slate-50/60">
                    <td className={tdClass}>
                      <div className="flex items-center gap-2 font-medium text-slate-800">
                        {e.name}
                        {!e.is_active && <Badge tone="red">Inactive</Badge>}
                      </div>
                      <div className="text-xs text-slate-500">{e.email}</div>
                    </td>
                    <td className={`${tdClass} text-slate-600`}>{e.role ?? '—'}</td>
                    <td className={`${tdClass} text-right tabular-nums`}>
                      {e.orders_count > 0 ? (
                        <Link to={`/reports/orders?${keepPeriod({ employee_id: e.id })}`} className="text-brand-600 hover:underline">
                          {e.orders_count}
                        </Link>
                      ) : (
                        0
                      )}
                    </td>
                    <td className={`${tdClass} text-right tabular-nums`}>{e.customers_count}</td>
                    <td className={`${tdClass} text-right tabular-nums text-slate-600`}>{formatINR(e.average_bill)}</td>
                    <td className={`${tdClass} text-right font-semibold tabular-nums`}>{formatINR(e.sales_total)}</td>
                    <td className={`${tdClass} text-right tabular-nums`}>
                      {e.adjustments_count > 0 ? (
                        <Link
                          to={`/reports/stock?${keepPeriod({ employee_id: e.id, type: 'adjustments' })}`}
                          className="text-brand-600 hover:underline"
                          title={`+${e.units_added} / −${e.units_removed} units`}
                        >
                          {e.adjustments_count}
                        </Link>
                      ) : (
                        0
                      )}
                    </td>
                    <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDateTime(e.last_bill_at)}</td>
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
