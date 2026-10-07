// One page component for customer receipts, supplier payments and expenses:
// a quick-entry form, a list with period filter and totals, and cancel.
import { useCallback, useEffect, useState } from 'react'
import { Ban, Search } from 'lucide-react'
import {
  cancelMoneyEntry,
  createMoneyEntry,
  getCustomersWithOutstanding,
  getExpenseAccounts,
  getMoneyEntries,
  getSuppliers,
} from '../../api/purchasing'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, Spinner, StatTile, inputClass } from '../ui'
import { formatINR, taxOn, toCents } from '../../lib/money'
import { Pager, PeriodFilter, SelectStoreAlert } from './shared'
import { MODE_LABELS, formatDate, periodRange, today } from './helpers'
import { useConfirm } from '../ConfirmDialog'

const CONFIG = {
  receipts: {
    title: 'Receipts',
    description: 'Money received from customers against credit bills.',
    what: 'receipts',
    noun: 'Receipt',
    party: 'Customer',
  },
  payments: {
    title: 'Payments',
    description: 'Money paid to suppliers against purchases.',
    what: 'payments',
    noun: 'Payment',
    party: 'Supplier',
  },
  expenses: {
    title: 'Expenses',
    description: 'Shop running costs: rent, salaries, electricity, transport and so on.',
    what: 'expenses',
    noun: 'Expense',
    party: 'Expense',
  },
}

export default function MoneyEntries({ kind, icon }) {
  const confirm = useConfirm()
  const config = CONFIG[kind]
  const { isAllStores, currentStore } = useAuth()
  const toast = useToast()
  const [period, setPeriod] = useState({ preset: 'this_month', from: '', to: '' })
  const [mode, setMode] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)

  useEffect(() => {
    const t = setTimeout(() => setQuery(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(() => {
    getMoneyEntries(kind, { ...periodRange(period.preset, period), mode, search: query, page })
      .then((res) => {
        setResult(res)
        setError(null)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [kind, period, mode, query, page])

  useEffect(load, [load])

  async function cancel(entry) {
    if (!(await confirm({ title: `Cancel ${config.noun.toLowerCase()} ${entry.number}?`, message: 'A reversing entry is posted in the accounts. The entry stays in the list, marked cancelled.', confirmLabel: `Cancel ${config.noun.toLowerCase()}`, cancelLabel: 'Keep it' }))) return
    setBusyId(entry.id)
    try {
      await cancelMoneyEntry(kind, entry.id)
      toast(`${config.noun} ${entry.number} cancelled.`)
      load()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    } finally {
      setBusyId(null)
    }
  }

  const rows = result?.data ?? []
  const totals = result?.meta?.totals

  return (
    <>
      <PageHeader
        title={config.title}
        description={`${config.description}${isAllStores ? ' All stores.' : currentStore ? ` ${currentStore.name}.` : ''}`}
      />
      {isAllStores && <SelectStoreAlert what={config.what} />}

      <div className="grid gap-6 xl:grid-cols-[360px_1fr]">
        <div className="space-y-4">
          <EntryForm
            kind={kind}
            config={config}
            disabled={isAllStores}
            onSaved={(entry) => {
              toast(`${config.noun} ${entry.number} saved.`)
              setPage(1)
              load()
            }}
          />
        </div>

        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <StatTile label={`${config.title} in period`} value={totals ? totals.count : '-'} icon={icon} />
            <StatTile label="Total amount" value={totals ? formatINR(totals.total) : '-'} sub={kind === 'expenses' && totals ? `Before GST ${formatINR(totals.amount)}` : 'Cancelled entries excluded'} tone="green" />
          </div>

          <Card padded={false}>
            <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
              <PeriodFilter value={period} onChange={(v) => { setPage(1); setPeriod(v) }} />
              <select className={`${inputClass} w-auto`} value={mode} onChange={(e) => { setPage(1); setMode(e.target.value) }} aria-label="Mode">
                <option value="">Cash & bank</option>
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
              <div className="relative ml-auto w-full sm:w-56">
                <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input className={`${inputClass} pl-9`} placeholder="Search" value={search} onChange={(e) => { setPage(1); setSearch(e.target.value) }} />
              </div>
            </div>

            {error && <div className="p-4"><Alert>{error}</Alert></div>}
            {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
            {result && rows.length === 0 && <EmptyState icon={icon} title={`No ${config.what} in this period`} />}

            {rows.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-sm">
                  <thead>
                    <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                      <th className="px-5 py-3">Number</th>
                      <th className="px-5 py-3">{config.party}</th>
                      <th className="px-5 py-3">Mode</th>
                      <th className="px-5 py-3 text-right">Amount</th>
                      <th className="px-5 py-3" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.map((entry) => {
                      const cancelled = entry.status === 'cancelled'
                      return (
                        <tr key={entry.id} className="hover:bg-slate-50/60">
                          <td className="px-5 py-3">
                            <p className="font-mono text-xs font-medium text-slate-800">{entry.number}</p>
                            <p className="text-xs text-slate-500">{formatDate(entry.date)}{isAllStores && entry.store && ` · ${entry.store.code}`}</p>
                          </td>
                          <td className="px-5 py-3 text-slate-700">
                            {entry.customer?.name ?? entry.supplier?.name ?? entry.account?.name}
                            {(entry.paid_to || entry.reference || entry.narration) && (
                              <p className="text-xs text-slate-400">{[entry.paid_to, entry.reference, entry.narration].filter(Boolean).join(' · ')}</p>
                            )}
                          </td>
                          <td className="px-5 py-3">
                            {cancelled ? <Badge tone="red">Cancelled</Badge> : <Badge tone={entry.mode === 'bank' ? 'brand' : 'slate'}>{MODE_LABELS[entry.mode]}</Badge>}
                          </td>
                          <td className={`px-5 py-3 text-right tabular-nums ${cancelled ? 'text-slate-400 line-through' : 'font-medium text-slate-900'}`}>
                            {formatINR(entry.total)}
                            {Number(entry.cgst_amount) > 0 && <p className="text-xs font-normal text-slate-400">incl. GST {formatINR(Number(entry.cgst_amount) + Number(entry.sgst_amount))}</p>}
                          </td>
                          <td className="px-5 py-3 text-right">
                            {!cancelled && (
                              <Button variant="ghost" size="sm" icon={Ban} loading={busyId === entry.id} onClick={() => cancel(entry)}
                                aria-label={`Cancel ${entry.number}`} title="Cancel (reverses the accounts entry)" />
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
            <Pager meta={result?.meta} onPage={setPage} />
          </Card>
        </div>
      </div>
    </>
  )
}

const blankForm = () => ({
  party_id: '', amount: '', mode: 'cash', date: today(), reference: '', narration: '',
  tax_percent: 0, supplier_gstin: '', paid_to: '',
})

function EntryForm({ kind, config, disabled, onSaved }) {
  const [options, setOptions] = useState(null)
  const [form, setForm] = useState(blankForm)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  // Party choices: customers who owe us, suppliers (with what we owe), or expense ledgers.
  const loadOptions = useCallback(() => {
    const request =
      kind === 'receipts'
        ? getCustomersWithOutstanding()
        : kind === 'payments'
          ? getSuppliers({ active: 1 }).then((res) => res.data)
          : getExpenseAccounts()
    request.then(setOptions).catch((e) => setError(parseApiError(e)))
  }, [kind])

  useEffect(loadOptions, [loadOptions])

  const partyKey = { receipts: 'customer_id', payments: 'supplier_id', expenses: 'account_id' }[kind]
  const selected = options?.find((o) => String(o.id) === String(form.party_id))
  const gstCents = kind === 'expenses' ? 2 * taxOn(toCents(form.amount), Number(form.tax_percent) / 2) : 0

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const payload = {
        [partyKey]: form.party_id || null,
        amount: form.amount,
        mode: form.mode,
        date: form.date || null,
        reference: form.reference.trim() || null,
        narration: form.narration.trim() || null,
      }
      if (kind === 'expenses') {
        payload.tax_percent = Number(form.tax_percent) || null
        payload.supplier_gstin = Number(form.tax_percent) ? form.supplier_gstin.trim() || null : null
        payload.paid_to = form.paid_to.trim() || null
      }
      const saved = await createMoneyEntry(kind, payload)
      setForm(blankForm())
      setSaving(false)
      loadOptions()
      onSaved(saved)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <Card title={`New ${config.noun.toLowerCase()}`}>
      <form onSubmit={submit} className="space-y-4" noValidate>
        <fieldset disabled={disabled} className="space-y-4">
          <Field label={kind === 'expenses' ? 'Expense head' : config.party} error={fieldError(partyKey)}>
            <select className={inputClass} value={form.party_id} onChange={set('party_id')}>
              <option value="">{options ? 'Choose…' : 'Loading…'}</option>
              {(options ?? []).map((o) => (
                <option key={o.id} value={o.id}>
                  {kind === 'expenses' ? `${o.code} · ${o.name}` : o.name}
                  {kind !== 'expenses' && o.outstanding && Number(o.outstanding) > 0 ? ` (owes ${formatINR(o.outstanding)})` : ''}
                </option>
              ))}
            </select>
            {kind === 'receipts' && options?.length === 0 && <span className="mt-1 block text-xs text-slate-500">No customer has an outstanding balance.</span>}
          </Field>
          {selected?.outstanding !== undefined && (
            <p className="-mt-2 text-xs text-slate-500">
              {kind === 'receipts' ? 'Customer owes' : 'You owe'} <b>{formatINR(selected.outstanding)}</b>
              {Number(selected.outstanding) > 0 && (
                <button type="button" className="ml-2 text-brand-600 hover:underline" onClick={() => setForm((f) => ({ ...f, amount: selected.outstanding }))}>
                  Use full amount
                </button>
              )}
            </p>
          )}

          <div className="grid grid-cols-2 gap-3">
            <Field label={kind === 'expenses' ? 'Amount (before GST)' : 'Amount (₹)'} error={fieldError('amount')}>
              <input type="number" min="0" step="0.01" className={inputClass} value={form.amount} onChange={set('amount')} />
            </Field>
            <Field label="Date" error={fieldError('date')}>
              <input type="date" className={inputClass} value={form.date} max={today()} onChange={set('date')} />
            </Field>
          </div>

          {kind === 'expenses' && (
            <>
              <div className="grid grid-cols-2 gap-3">
                <Field label="GST on bill" error={fieldError('tax_percent')}>
                  <select className={inputClass} value={form.tax_percent} onChange={set('tax_percent')}>
                    {[0, 5, 12, 18, 28].map((t) => <option key={t} value={t}>{t ? `${t}%` : 'No GST'}</option>)}
                  </select>
                </Field>
                <Field label="Supplier GSTIN" error={fieldError('supplier_gstin')}>
                  <input className={`${inputClass} font-mono uppercase`} maxLength={15} value={form.supplier_gstin} onChange={set('supplier_gstin')} disabled={!Number(form.tax_percent)} />
                </Field>
              </div>
              {gstCents > 0 && (
                <p className="-mt-2 text-xs text-slate-500">
                  Input GST {formatINR(gstCents, { cents: true })} · total {formatINR(toCents(form.amount) + gstCents, { cents: true })}
                </p>
              )}
              <Field label="Paid to" error={fieldError('paid_to')}>
                <input className={inputClass} value={form.paid_to} onChange={set('paid_to')} placeholder="e.g. TNEB, landlord" />
              </Field>
            </>
          )}

          <div className="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm">
            {['cash', 'bank'].map((m) => (
              <button type="button" key={m} onClick={() => setForm((f) => ({ ...f, mode: m }))}
                className={`rounded-md px-2 py-1.5 font-medium ${form.mode === m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'}`}>
                {m === 'bank' ? 'Bank / UPI' : 'Cash'}
              </button>
            ))}
          </div>

          <Field label="Reference" error={fieldError('reference')} hint="Cheque no., UTR, bill no.">
            <input className={inputClass} value={form.reference} onChange={set('reference')} />
          </Field>
          <Field label="Narration" error={fieldError('narration')}>
            <input className={inputClass} value={form.narration} onChange={set('narration')} />
          </Field>

          {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
          <Button type="submit" className="w-full" loading={saving} disabled={disabled || !form.party_id || !form.amount}>
            Save {config.noun.toLowerCase()}
          </Button>
        </fieldset>
      </form>
    </Card>
  )
}
