import { Fragment, useState } from 'react'
import { ChevronRight, ChevronsUpDown, ClipboardList, IndianRupee, Percent, Printer, ReceiptText } from 'lucide-react'
import ReceiptModal from '../../components/receipt/ReceiptModal'
import ReportFilters, { Pager } from '../../components/reports/ReportFilters'
import { formatDateTime, tdClass, thClass } from '../../components/reports/table'
import ReportDownloads from '../../components/reports/ReportDownloads'
import useReport from '../../components/reports/useReport'
import { Alert, Button, Card, EmptyState, PageHeader, Spinner, StatTile } from '../../components/ui'
import { formatINR } from '../../lib/money'

export default function OrderReport() {
  const { filters, setFilters, query, result, loading, error, incomplete } = useReport('orders')
  const [receiptFor, setReceiptFor] = useState(null)
  const [openIds, setOpenIds] = useState(() => new Set())
  const [showAll, setShowAll] = useState(false)
  const isOpen = (id) => showAll !== openIds.has(id) // a row clicked while "show all" is on closes it
  const toggle = (id) =>
    setOpenIds((ids) => {
      const next = new Set(ids)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  const summary = result?.summary

  return (
    <div className="space-y-6">
      <PageHeader
        title="Order Report"
        description="Every bill in the period and the employee who billed it."
        actions={<ReportDownloads report="orders" query={query} disabled={incomplete} />}
      />

      <ReportFilters
        filters={filters}
        setFilters={setFilters}
        period={result?.period}
        loading={loading}
        employeeLabel="Billed by"
        searchPlaceholder="Bill no., customer name, email, mobile"
      />

      {error && <Alert>{error}</Alert>}

      {summary && !incomplete && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label="Bills" value={summary.orders} icon={ReceiptText} />
          <StatTile label="Sales" value={formatINR(summary.grand_total)} sub={`Before tax ${formatINR(summary.subtotal)}`} icon={IndianRupee} tone="green" />
          <StatTile label="GST collected" value={formatINR(summary.tax_total)} icon={Percent} tone="amber" />
          <StatTile label="Average bill" value={formatINR(summary.average_bill)} icon={ClipboardList} />
        </div>
      )}

      <Card
        padded={false}
        title={result?.data.length > 0 ? 'Bills' : undefined}
        actions={
          result?.data.length > 0 && (
            <Button
              variant="secondary"
              size="sm"
              icon={ChevronsUpDown}
              onClick={() => {
                setShowAll((s) => !s)
                setOpenIds(new Set())
              }}
            >
              {showAll ? 'Hide all products' : 'Show all products'}
            </Button>
          )
        }
      >
        {!result && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result?.data.length === 0 && <EmptyState icon={ReceiptText} title="No bills in this period" />}
        {result?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className={thClass}>Bill no.</th>
                  <th className={thClass}>Date</th>
                  <th className={thClass}>Customer</th>
                  <th className={thClass}>Billed by</th>
                  <th className={`${thClass} text-right`}>Items</th>
                  <th className={`${thClass} text-right`}>GST</th>
                  <th className={`${thClass} text-right`}>Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((order) => (
                  <OrderRow
                    key={order.id}
                    order={order}
                    open={isOpen(order.id)}
                    onToggle={() => toggle(order.id)}
                    onReceipt={() => setReceiptFor(order)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={(page) => setFilters({ page })} />
      </Card>

      {receiptFor && <ReceiptModal order={receiptFor} onClose={() => setReceiptFor(null)} />}
    </div>
  )
}

/** One bill; the arrow next to the bill number opens its products underneath. */
function OrderRow({ order, open, onToggle, onReceipt }) {
  const units = order.items.reduce((n, item) => n + item.quantity, 0)

  return (
    <Fragment>
      {/* An open bill is shaded, with a matching bar down the left of its products. */}
      <tr className={`cursor-pointer ${open ? 'bg-brand-100/70 hover:bg-brand-100' : 'hover:bg-slate-50/60'}`} onClick={onToggle}>
        <td className={`${tdClass} border-l-4 font-medium text-slate-900 ${open ? 'border-brand-600' : 'border-transparent'}`}>
          <button
            type="button"
            className="flex items-center gap-1.5 whitespace-nowrap"
            aria-expanded={open}
            aria-label={`${open ? 'Hide' : 'Show'} products in ${order.order_number}`}
            onClick={(e) => {
              e.stopPropagation()
              onToggle()
            }}
          >
            <ChevronRight size={16} className={`text-slate-400 transition-transform ${open ? 'rotate-90 text-brand-600' : ''}`} aria-hidden />
            {order.order_number}
          </button>
        </td>
        <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDateTime(order.created_at)}</td>
        <td className={tdClass}>
          <div className="font-medium text-slate-800">{order.customer?.name}</div>
          <div className="text-xs text-slate-500">{order.customer?.phone ?? order.customer?.email}</div>
        </td>
        <td className={`${tdClass} text-slate-700`}>{order.cashier ?? '—'}</td>
        <td className={`${tdClass} text-right tabular-nums`}>{units}</td>
        <td className={`${tdClass} text-right tabular-nums text-slate-600`}>{formatINR(order.tax_total)}</td>
        <td className={`${tdClass} text-right font-semibold tabular-nums`}>{formatINR(order.grand_total)}</td>
      </tr>

      {open && (
        <tr>
          <td colSpan={7} className="border-l-4 border-brand-200 px-5 pb-4 pt-2">
            <div className="ml-6 overflow-hidden rounded-xl border border-brand-100 bg-white">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2">Product</th>
                    <th className="px-4 py-2 text-right">Qty</th>
                    <th className="px-4 py-2 text-right">Unit price</th>
                    <th className="px-4 py-2 text-right">GST %</th>
                    <th className="px-4 py-2 text-right">Taxable</th>
                    <th className="px-4 py-2 text-right">GST</th>
                    <th className="px-4 py-2 text-right">Line total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {order.items.map((item) => (
                    <tr key={item.product_id}>
                      <td className="px-4 py-2">
                        <div className="font-medium text-slate-800">{item.product_name}</div>
                        {item.product_code && <div className="text-xs text-slate-500">{item.product_code}</div>}
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums">{item.quantity}</td>
                      <td className="px-4 py-2 text-right tabular-nums">{formatINR(item.unit_price)}</td>
                      <td className="px-4 py-2 text-right tabular-nums text-slate-600">{Number(item.tax_percent)}%</td>
                      <td className="px-4 py-2 text-right tabular-nums">{formatINR(item.line_subtotal)}</td>
                      <td className="px-4 py-2 text-right tabular-nums text-slate-600">{formatINR(item.line_tax)}</td>
                      <td className="px-4 py-2 text-right font-medium tabular-nums">{formatINR(item.line_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 border-t border-dashed border-slate-200 px-4 py-2 text-xs text-slate-500">
                <span>Subtotal {formatINR(order.subtotal)}</span>
                {order.is_interstate ? (
                  <span>IGST {formatINR(order.igst_amount)}</span>
                ) : (
                  <>
                    <span>CGST {formatINR(order.cgst_amount)}</span>
                    <span>SGST {formatINR(order.sgst_amount)}</span>
                  </>
                )}
                {order.amount_paid && (
                  <span>
                    Paid {formatINR(order.amount_paid)} · Change {formatINR(order.change_due)}
                  </span>
                )}
                <span className="font-semibold text-slate-800">Total {formatINR(order.grand_total)}</span>
                <Button variant="secondary" size="sm" icon={Printer} onClick={onReceipt}>
                  Receipt
                </Button>
              </div>
            </div>
          </td>
        </tr>
      )}
    </Fragment>
  )
}
