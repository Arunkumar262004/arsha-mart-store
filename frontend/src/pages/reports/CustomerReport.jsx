import { IndianRupee, ReceiptText, UserPlus, UsersRound } from 'lucide-react'
import { Link } from 'react-router-dom'
import ReportFilters, { Pager } from '../../components/reports/ReportFilters'
import { formatDateTime, tdClass, thClass } from '../../components/reports/table'
import ReportDownloads from '../../components/reports/ReportDownloads'
import useReport from '../../components/reports/useReport'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Card, EmptyState, PageHeader, Spinner, StatTile } from '../../components/ui'
import { formatINR } from '../../lib/money'

export default function CustomerReport() {
  const { can } = useAuth()
  const { filters, setFilters, query, result, loading, error, incomplete } = useReport('customers')
  const summary = result?.summary

  return (
    <div className="space-y-6">
      <PageHeader
        title="Customer Report"
        description="Customers who bought in the period, biggest spenders first."
        actions={<ReportDownloads report="customers" query={query} disabled={incomplete} />}
      />

      <ReportFilters
        filters={filters}
        setFilters={setFilters}
        period={result?.period}
        loading={loading}
        searchPlaceholder="Name, email or mobile"
      />

      {error && <Alert>{error}</Alert>}

      {summary && !incomplete && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label="Customers" value={summary.customers} icon={UsersRound} />
          <StatTile label="New customers" value={summary.new_customers} sub="First registered in this period" icon={UserPlus} tone="green" />
          <StatTile label="Bills" value={summary.orders} icon={ReceiptText} tone="amber" />
          <StatTile label="Total spent" value={formatINR(summary.total_spent)} icon={IndianRupee} tone="green" />
        </div>
      )}

      <Card padded={false}>
        {!result && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result?.data.length === 0 && <EmptyState icon={UsersRound} title="No customers bought in this period" />}
        {result?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className={thClass}>Customer</th>
                  <th className={thClass}>Mobile</th>
                  <th className={`${thClass} text-right`}>Bills</th>
                  <th className={`${thClass} text-right`}>Average bill</th>
                  <th className={`${thClass} text-right`}>Total spent</th>
                  <th className={thClass}>Last bill</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((customer) => (
                  <tr key={customer.id} className="hover:bg-slate-50/60">
                    <td className={tdClass}>
                      <div className="flex items-center gap-2 font-medium text-slate-800">
                        {customer.name}
                        {customer.is_new && <Badge tone="green">New</Badge>}
                      </div>
                      <div className="text-xs text-slate-500">
                        {can('orders.view') ? (
                          <Link to={`/orders?email=${encodeURIComponent(customer.email)}`} className="hover:text-brand-600 hover:underline">
                            {customer.email}
                          </Link>
                        ) : (
                          customer.email
                        )}
                      </div>
                    </td>
                    <td className={`${tdClass} text-slate-600`}>{customer.phone ?? '—'}</td>
                    <td className={`${tdClass} text-right tabular-nums`}>{customer.orders_count}</td>
                    <td className={`${tdClass} text-right tabular-nums text-slate-600`}>{formatINR(customer.average_bill)}</td>
                    <td className={`${tdClass} text-right font-semibold tabular-nums`}>{formatINR(customer.total_spent)}</td>
                    <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDateTime(customer.last_order_at)}</td>
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
