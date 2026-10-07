import { useState } from 'react'
import { CircleCheck, Printer, ReceiptText } from 'lucide-react'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { PAYMENT_MODES } from '../receipt/paymentModes'
import { formatINR } from '../../lib/money'
import { Alert, Button, Field, Modal, inputClass } from '../ui'
import TaxInvoiceModal from './TaxInvoiceModal'

/**
 * "Make a bill" from a quotation or delivery challans: choose how it is paid
 * (and give an email when the document has none), then show the new invoice
 * number. `submit(payload)` must resolve with the created order.
 */
export default function BillDialog({ title, total, needsEmail = false, defaultName = '', submit, onClose, onDone, children }) {
  const { can } = useAuth()
  const [paymentMode, setPaymentMode] = useState('cash')
  const [amountPaid, setAmountPaid] = useState('')
  const [email, setEmail] = useState('')
  const [name, setName] = useState(defaultName)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [order, setOrder] = useState(null)
  const [showInvoice, setShowInvoice] = useState(false)
  const credit = paymentMode === 'credit'
  const fieldError = (key) => error?.errors?.[key]?.[0]
  const stockErrors = Object.entries(error?.errors ?? {}).filter(([key]) => key.startsWith('items.'))

  async function confirm() {
    setSaving(true)
    setError(null)
    try {
      const created = await submit({
        payment_mode: paymentMode,
        amount_paid: credit || amountPaid === '' ? null : Number(amountPaid),
        ...(needsEmail ? { customer_email: email.trim() || null, customer_name: name.trim() || null } : {}),
      })
      setOrder(created)
      onDone?.(created)
    } catch (e) {
      setError(parseApiError(e))
    } finally {
      setSaving(false)
    }
  }

  if (showInvoice && order) return <TaxInvoiceModal orderId={order.id} onClose={onClose} />

  if (order) {
    return (
      <Modal
        open
        size="sm"
        title="Bill created"
        onClose={onClose}
        footer={
          <>
            <Button variant="secondary" onClick={onClose}>Done</Button>
            {can('orders.view') && <Button icon={Printer} onClick={() => setShowInvoice(true)}>Print tax invoice</Button>}
          </>
        }
      >
        <div className="flex items-start gap-3">
          <div className="rounded-full bg-emerald-50 p-2 text-emerald-600"><CircleCheck size={22} aria-hidden /></div>
          <div>
            <p className="text-sm text-slate-500">Invoice number</p>
            <p className="text-lg font-semibold text-slate-900">{order.invoice_number}</p>
            <p className="text-sm text-slate-600">{order.customer?.name} · {formatINR(order.grand_total)}</p>
          </div>
        </div>
      </Modal>
    )
  }

  return (
    <Modal
      open
      size="md"
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button variant="success" icon={ReceiptText} loading={saving} onClick={confirm}>Create bill</Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <Alert>
            {error.message}
            {stockErrors.map(([key, messages]) => <span key={key} className="block text-xs">{messages[0]}</span>)}
          </Alert>
        )}
        {children}
        {total != null && (
          <div className="flex items-baseline justify-between rounded-xl bg-slate-900 px-4 py-3 text-white">
            <span className="text-sm text-slate-300">Approx. total</span>
            <span className="text-xl font-semibold tabular-nums">{formatINR(total)}</span>
          </div>
        )}
        {needsEmail && (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Customer email" error={fieldError('customer_email')} hint="The document has no email">
              <input type="email" className={inputClass} value={email} onChange={(e) => setEmail(e.target.value)} />
            </Field>
            <Field label="Customer name" error={fieldError('customer_name')}>
              <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
            </Field>
          </div>
        )}
        <div>
          <span className="mb-1.5 block text-xs font-medium text-slate-600">Payment</span>
          <div className="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:grid-cols-4" role="radiogroup" aria-label="Payment mode">
            {PAYMENT_MODES.map((m) => (
              <button
                key={m.value}
                type="button"
                role="radio"
                aria-checked={paymentMode === m.value}
                onClick={() => {
                  setPaymentMode(m.value)
                  if (m.value === 'credit') setAmountPaid('')
                }}
                className={`rounded-md px-2 py-1.5 font-medium transition ${paymentMode === m.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
              >
                {m.value === 'credit' ? 'Credit' : m.label}
              </button>
            ))}
          </div>
        </div>
        {!credit && (
          <Field label="Amount paid" hint="Optional: shows the change to return" error={fieldError('amount_paid')}>
            <input type="number" min="0" step="0.01" className={inputClass} value={amountPaid} onChange={(e) => setAmountPaid(e.target.value)} />
          </Field>
        )}
      </div>
    </Modal>
  )
}
