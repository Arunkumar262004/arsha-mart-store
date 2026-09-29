import { Link } from 'react-router-dom'
import { CircleCheck, History, Mail, MessageCircle, Plus, Printer } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { formatINR } from '../lib/money'
import ThermalReceipt from './receipt/ThermalReceipt'
import usePrintReceipt from './receipt/usePrintReceipt'
import { Button, Card } from './ui'

export function PaperToggle({ paper, setPaper }) {
  return (
    <div className="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="radiogroup" aria-label="Receipt paper width">
      {['80', '58'].map((size) => (
        <button
          key={size}
          type="button"
          role="radio"
          aria-checked={paper === size}
          onClick={() => setPaper(size)}
          className={`rounded-md px-3 py-1.5 font-medium transition ${paper === size ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
        >
          {size} mm
        </button>
      ))}
    </div>
  )
}

export default function Bill({ order, onNewOrder }) {
  const { can } = useAuth()
  const { contentRef, paper, setPaper, print } = usePrintReceipt(order.order_number)

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
      {/* Receipt preview on a "counter" backdrop, exactly as it will print. */}
      <div className="flex justify-center overflow-x-auto rounded-2xl bg-gradient-to-b from-slate-200 to-slate-300/70 px-4 py-8 shadow-inner">
        <div className="shadow-[0_18px_40px_-12px_rgba(15,23,42,0.45),0_4px_10px_-4px_rgba(15,23,42,0.2)]">
          <ThermalReceipt ref={contentRef} order={order} paper={paper} />
        </div>
      </div>

      <div className="space-y-6 lg:sticky lg:top-24">
        <Card>
          <div className="flex items-start gap-3">
            <div className="rounded-full bg-emerald-50 p-2 text-emerald-600">
              <CircleCheck size={22} aria-hidden />
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-emerald-700">Bill generated</p>
              <p className="truncate text-lg font-semibold text-slate-900">{order.order_number}</p>
              <p className="text-sm text-slate-500">{order.customer.name}</p>
            </div>
          </div>

          <div className="mt-4 flex items-baseline justify-between rounded-xl bg-slate-900 px-4 py-3 text-white">
            <span className="text-sm text-slate-300">Grand total</span>
            <span className="text-2xl font-semibold tabular-nums">{formatINR(order.grand_total)}</span>
          </div>
          {order.amount_paid !== null && (
            <div className="mt-2 flex justify-between rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">
              <span>Change returned</span>
              <span className="tabular-nums">{formatINR(order.change_due)}</span>
            </div>
          )}

          <div className="mt-5">
            <p className="mb-2 text-xs font-medium text-slate-600">Receipt paper</p>
            <PaperToggle paper={paper} setPaper={setPaper} />
          </div>

          <Button icon={Printer} size="lg" className="mt-4 w-full" onClick={print}>
            Print receipt
          </Button>
          <div className="mt-2 grid grid-cols-2 gap-2">
            <Button variant="secondary" icon={Plus} onClick={onNewOrder}>
              New bill
            </Button>
            {can('orders.view') ? (
              <Link
                to={`/orders?email=${encodeURIComponent(order.customer.email)}`}
                className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
              >
                <History size={16} aria-hidden /> History
              </Link>
            ) : (
              <span />
            )}
          </div>
        </Card>

        <Card title="Confirmations">
          <ul className="space-y-2 text-sm">
            <li className="flex items-center gap-2 text-slate-700">
              <Mail size={16} className="text-slate-400" aria-hidden /> Email queued for {order.customer.email}
            </li>
            {order.customer.phone && (
              <li className="flex items-center gap-2 text-slate-700">
                <MessageCircle size={16} className="text-slate-400" aria-hidden /> WhatsApp queued for {order.customer.phone}
              </li>
            )}
          </ul>
        </Card>
      </div>
    </div>
  )
}
