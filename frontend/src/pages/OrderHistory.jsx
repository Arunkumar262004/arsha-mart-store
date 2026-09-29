import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { getOrderHistory } from '../api'
import { parseApiError } from '../api/client'
import { Printer } from 'lucide-react'
import ReceiptModal from '../components/receipt/ReceiptModal'
import { Alert, Button, Card, Spinner, inputClass } from '../components/ui'
import { formatINR } from '../lib/money'

export default function OrderHistory() {
  const [params, setParams] = useSearchParams()
  const email = params.get('email') ?? ''
  const page = Number(params.get('page') ?? 1)

  const [input, setInput] = useState(email)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [receiptFor, setReceiptFor] = useState(null)

  useEffect(() => {
    if (!email) return
    let cancelled = false
    setLoading(true)
    setError(null)
    getOrderHistory(email, page)
      .then((data) => !cancelled && setResult(data))
      .catch((e) => {
        if (cancelled) return
        setResult(null)
        setError(parseApiError(e).message)
      })
      .finally(() => !cancelled && setLoading(false))
    return () => {
      cancelled = true
    }
  }, [email, page])

  function search(event) {
    event.preventDefault()
    const value = input.trim()
    if (value) setParams({ email: value })
  }

  return (
    <div className="space-y-6">
      <Card title="Order history">
        <form onSubmit={search} className="flex flex-col gap-2 sm:flex-row">
          <input
            type="email"
            className={inputClass}
            placeholder="Customer email, e.g. arun@example.com"
            value={input}
            onChange={(e) => setInput(e.target.value)}
          />
          <button className="flex items-center justify-center gap-2 rounded-md bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700">
            {loading && <Spinner />} Search
          </button>
        </form>
      </Card>

      {error && <Alert>{error}</Alert>}

      {result && (
        <>
          <p className="text-sm text-slate-600">
            <span className="font-medium text-slate-900">{result.customer.name}</span> ({result.customer.email}
            {result.customer.phone && `, ${result.customer.phone}`}) ·{' '}
            {result.meta.total} order{result.meta.total === 1 ? '' : 's'}
          </p>

          {result.data.length === 0 && <Alert tone="info">This customer has no orders yet.</Alert>}

          <div className="space-y-3">
            {result.data.map((order) => (
              <details key={order.id} className="group rounded-xl border border-slate-200 bg-white shadow-sm">
                <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-5 py-3">
                  <span>
                    <span className="font-semibold">{order.order_number}</span>
                    <span className="ml-3 text-sm text-slate-500">{new Date(order.created_at).toLocaleString('en-IN')}</span>
                  </span>
                  <span className="flex items-center gap-3 text-sm">
                    <span className="text-slate-500">{order.items.length} item(s)</span>
                    <span className="font-semibold">{formatINR(order.grand_total)}</span>
                    <span className="text-slate-400 transition group-open:rotate-90">›</span>
                  </span>
                </summary>
                <div className="border-t border-slate-100 px-5 py-3">
                  <table className="w-full text-sm">
                    <tbody>
                      {order.items.map((item) => (
                        <tr key={item.product_id}>
                          <td className="py-1">{item.product_name}</td>
                          <td className="py-1 text-right text-slate-500">
                            {item.quantity} × {formatINR(item.unit_price)}
                          </td>
                          <td className="w-28 py-1 text-right">{formatINR(item.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  <div className="mt-2 flex flex-wrap items-center justify-end gap-4 border-t border-dashed border-slate-200 pt-2 text-xs text-slate-500">
                    <span>Subtotal {formatINR(order.subtotal)}</span>
                    {order.is_interstate ? (
                      <span>IGST {formatINR(order.igst_amount)}</span>
                    ) : (
                      <>
                        <span>CGST {formatINR(order.cgst_amount)}</span>
                        <span>SGST {formatINR(order.sgst_amount)}</span>
                      </>
                    )}
                    <span>{order.confirmation_sent_at ? '✉ Email sent' : '⏳ Email queued'}</span>
                    {result.customer.phone && (
                      <span>{order.whatsapp_sent_at ? '✓ WhatsApp sent' : '⏳ WhatsApp pending'}</span>
                    )}
                    <Button
                      variant="secondary"
                      size="sm"
                      icon={Printer}
                      onClick={() => setReceiptFor({ ...order, customer: result.customer })}
                    >
                      Receipt
                    </Button>
                  </div>
                </div>
              </details>
            ))}
          </div>

          {result.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3 text-sm">
              <button
                disabled={page <= 1}
                onClick={() => setParams({ email, page: page - 1 })}
                className="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
              >
                ← Prev
              </button>
              <span>
                Page {result.meta.current_page} of {result.meta.last_page}
              </span>
              <button
                disabled={page >= result.meta.last_page}
                onClick={() => setParams({ email, page: page + 1 })}
                className="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
              >
                Next →
              </button>
            </div>
          )}
        </>
      )}

      {receiptFor && <ReceiptModal order={receiptFor} onClose={() => setReceiptFor(null)} />}
    </div>
  )
}
