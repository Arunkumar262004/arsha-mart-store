import { useState } from 'react'
import { Banknote, CalendarCheck, CreditCard, Receipt, Smartphone, Wallet } from 'lucide-react'
import { getDayClosing, saveDayClosing } from '../../api/accounts'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import { PAYMENT_MODES, VOUCHER_TYPES, formatDay, headRowClass, money, tdClass, thClass, today } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'
import { toCents } from '../../lib/money'

const MODE_ICONS = { cash: Banknote, card: CreditCard, upi: Smartphone, credit: Receipt }

export default function DayClosing() {
  const { can, isAllStores } = useAuth()
  const toast = useToast()
  const canClose = can('billing.create') || can('accounts.manage')
  const [date, setDate] = useState(today())
  const { data: result, loading, error, reload } = useLoad(() => getDayClosing({ date }), date)
  const day = result?.data?.date === date ? result.data : null

  return (
    <div className="space-y-6">
      <PageHeader
        title="Day Closing"
        description="Bills by payment mode and cashier, cash movements, and the counted cash in the drawer."
        actions={
          <Field>
            <input type="date" className={inputClass} value={date} max={today()} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          </Field>
        }
      />
      {isAllStores && <Alert tone="info">Showing all stores together. Select a store in the header to close its cash drawer.</Alert>}
      {error && <Alert>{error}</Alert>}
      {!day && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}

      {day && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatTile label="Bills" value={day.bills.count} sub={money(day.bills.total)} icon={CalendarCheck} />
            {day.modes.map((m) => (
              <StatTile key={m.mode} label={PAYMENT_MODES[m.mode] ?? m.mode} value={money(m.total)} sub={`${m.bills} bills`} icon={MODE_ICONS[m.mode]} tone={m.mode === 'credit' ? 'amber' : m.mode === 'cash' ? 'green' : 'brand'} />
            ))}
          </div>

          <div className="grid gap-6 lg:grid-cols-3">
            <Card title="Cashier × payment mode" padded={false} className="lg:col-span-2">
              {day.cashiers.length === 0 ? (
                <EmptyState icon={Receipt} title="No bills on this day" />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[620px] text-sm">
                    <thead>
                      <tr className={headRowClass}>
                        <th className={thClass}>Cashier</th>
                        <th className={`${thClass} text-right`}>Bills</th>
                        {Object.entries(PAYMENT_MODES).map(([mode, label]) => <th key={mode} className={`${thClass} text-right`}>{label}</th>)}
                        <th className={`${thClass} text-right`}>Total</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {day.cashiers.map((c) => (
                        <tr key={`${c.user_id}-${c.name}`}>
                          <td className={`${tdClass} font-medium text-slate-800`}>{c.name}</td>
                          <td className={`${tdClass} text-right tabular-nums`}>{c.bills}</td>
                          {Object.keys(PAYMENT_MODES).map((mode) => (
                            <td key={mode} className={`${tdClass} text-right tabular-nums`}>{money(c.modes[mode], { blankZero: true }) || '—'}</td>
                          ))}
                          <td className={`${tdClass} text-right font-semibold tabular-nums`}>{money(c.total)}</td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                      <tr>
                        <td className={tdClass}>Total</td>
                        <td className={`${tdClass} text-right tabular-nums`}>{day.bills.count}</td>
                        {Object.keys(PAYMENT_MODES).map((mode) => (
                          <td key={mode} className={`${tdClass} text-right tabular-nums`}>{money(day.modes.find((m) => m.mode === mode)?.total ?? 0)}</td>
                        ))}
                        <td className={`${tdClass} text-right tabular-nums`}>{money(day.bills.total)}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </Card>

            <Card title="Cash in hand">
              <dl className="space-y-2 text-sm">
                <CashLine label="Opening cash" value={day.cash.opening} />
                <CashLine label="Cash sales" value={day.cash.sales} sign="+" />
                <CashLine label="Receipts" value={day.cash.receipts} sign="+" />
                <CashLine label="Other cash in" value={day.cash.other_in} sign="+" />
                <CashLine label="Sales returns refunded" value={day.cash.refunds} sign="−" />
                <CashLine label="Payments" value={day.cash.payments} sign="−" />
                <CashLine label="Expenses" value={day.cash.expenses} sign="−" />
                <CashLine label="Other cash out" value={day.cash.other_out} sign="−" />
                <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                  <dt>Expected closing</dt>
                  <dd className="tabular-nums">{money(day.cash.expected_closing)}</dd>
                </div>
              </dl>
            </Card>
          </div>

          {day.cash_vouchers.length > 0 && (
            <Card title="Other cash entries" padded={false}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[620px] text-sm">
                  <thead>
                    <tr className={headRowClass}>
                      <th className={thClass}>Voucher</th>
                      <th className={thClass}>Type</th>
                      <th className={thClass}>Narration</th>
                      <th className={`${thClass} text-right`}>Cash in</th>
                      <th className={`${thClass} text-right`}>Cash out</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {day.cash_vouchers.map((v, i) => (
                      <tr key={`${v.id}-${i}`}>
                        <td className={`${tdClass} font-mono text-xs`}>{v.number}</td>
                        <td className={tdClass}><Badge>{VOUCHER_TYPES[v.type] ?? v.type}</Badge></td>
                        <td className={`${tdClass} text-slate-600`}>{v.narration ?? '—'}</td>
                        <td className={`${tdClass} text-right tabular-nums text-emerald-700`}>{money(v.in, { blankZero: true })}</td>
                        <td className={`${tdClass} text-right tabular-nums text-red-600`}>{money(v.out, { blankZero: true })}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}

          {!isAllStores && canClose && (
            <CloseDrawer
              key={`${day.date}-${day.closing?.updated_at ?? 'new'}`}
              day={day}
              onSaved={() => {
                toast('Day closing saved.')
                reload()
              }}
            />
          )}
        </>
      )}

      {result?.recent?.length > 0 && (
        <Card title="Recent closings" padded={false}>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className={headRowClass}>
                  <th className={thClass}>Date</th>
                  {isAllStores && <th className={thClass}>Store</th>}
                  <th className={`${thClass} text-right`}>Expected</th>
                  <th className={`${thClass} text-right`}>Counted</th>
                  <th className={`${thClass} text-right`}>Difference</th>
                  <th className={thClass}>Closed by</th>
                  <th className={thClass}>Notes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.recent.map((c) => (
                  <tr key={c.id} className="cursor-pointer hover:bg-slate-50/60" onClick={() => setDate(c.date)}>
                    <td className={`${tdClass} whitespace-nowrap`}>{formatDay(c.date)}</td>
                    {isAllStores && <td className={tdClass}>{c.store}</td>}
                    <td className={`${tdClass} text-right tabular-nums`}>{money(c.expected_cash)}</td>
                    <td className={`${tdClass} text-right tabular-nums`}>{money(c.counted_cash)}</td>
                    <td className={`${tdClass} text-right font-medium tabular-nums`}><Difference value={c.difference} /></td>
                    <td className={`${tdClass} text-slate-600`}>{c.closed_by ?? '—'}</td>
                    <td className={`${tdClass} max-w-xs truncate text-slate-500`}>{c.notes ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}

function CashLine({ label, value, sign }) {
  if (sign && Number(value) === 0) return null
  return (
    <div className="flex justify-between text-slate-600">
      <dt>{label}</dt>
      <dd className="tabular-nums">{sign ? `${sign} ` : ''}{money(value)}</dd>
    </div>
  )
}

function Difference({ value }) {
  const n = Number(value)
  if (n === 0) return <span className="text-emerald-700">Tallied</span>
  return <span className={n < 0 ? 'text-red-600' : 'text-amber-700'}>{n < 0 ? `Short ${money(Math.abs(n))}` : `Excess ${money(n)}`}</span>
}

function CloseDrawer({ day, onSaved }) {
  const [counted, setCounted] = useState(day.closing?.counted_cash ?? '')
  const [notes, setNotes] = useState(day.closing?.notes ?? '')
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [saving, setSaving] = useState(false)
  const difference = counted === '' ? null : (toCents(counted) - toCents(day.cash.expected_closing)) / 100

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setMessage(null)
    try {
      await saveDayClosing({ date: day.date, counted_cash: counted, notes: notes || null })
      onSaved()
    } catch (err) {
      const { message: msg, errors: errs } = parseApiError(err)
      setErrors(errs)
      setMessage(msg)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card title={day.closing ? `Closed by ${day.closing.closed_by ?? '—'}` : 'Close the cash drawer'}>
      <form onSubmit={save} className="grid gap-4 sm:grid-cols-4 sm:items-end">
        {message && Object.keys(errors).length === 0 && <div className="sm:col-span-4"><Alert>{message}</Alert></div>}
        <Field label="Expected cash">
          <input className={inputClass} value={money(day.cash.expected_closing)} readOnly />
        </Field>
        <Field label="Counted cash (₹)" error={errors.counted_cash?.[0]}>
          <input type="number" min="0" step="0.01" className={inputClass} value={counted} onChange={(e) => setCounted(e.target.value)} required />
        </Field>
        <Field label="Notes" error={errors.notes?.[0]}>
          <input className={inputClass} value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} placeholder="Reason for any difference" />
        </Field>
        <div className="flex items-center justify-between gap-3">
          <span className="text-sm font-medium">{difference !== null && <Difference value={difference} />}</span>
          <Button type="submit" icon={Wallet} loading={saving} disabled={counted === ''}>{day.closing ? 'Update' : 'Close day'}</Button>
        </div>
      </form>
    </Card>
  )
}
