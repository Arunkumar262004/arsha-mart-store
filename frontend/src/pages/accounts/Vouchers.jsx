import { Fragment, useEffect, useState } from 'react'
import { ChevronDown, ChevronRight, NotebookPen, Plus, Search, Trash2, X } from 'lucide-react'
import { createVoucher, deleteVoucher, getAccountOptions, getVoucher, getVouchers } from '../../api/accounts'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Pager } from '../../components/reports/ReportFilters'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import AccountPicker from '../../components/accounts/AccountPicker'
import { VOUCHER_TONES, VOUCHER_TYPES, formatDay, headRowClass, money, monthStart, tdClass, thClass, today } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'
import { toCents } from '../../lib/money'
import { useConfirm } from '../../components/ConfirmDialog'

export default function Vouchers() {
  const confirm = useConfirm()
  const { can, isAllStores } = useAuth()
  const toast = useToast()
  const canManage = can('accounts.manage')
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), type: '', search: '', page: 1 })
  const [searchText, setSearchText] = useState('')
  const [expanded, setExpanded] = useState({})
  const [creating, setCreating] = useState(false)
  const [busyId, setBusyId] = useState(null)

  // Search after a short pause in typing.
  useEffect(() => {
    if (searchText.trim() === filters.search) return
    const timer = setTimeout(() => setFilters((f) => ({ ...f, search: searchText.trim(), page: 1 })), 350)
    return () => clearTimeout(timer)
  }, [searchText, filters.search])

  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
  const { data: result, loading, error, reload } = useLoad(() => getVouchers(params), JSON.stringify(params))
  const change = (changes) => setFilters((f) => ({ ...f, page: 1, ...changes }))

  async function remove(voucher) {
    if (!(await confirm({ title: `Delete ${voucher.number}?`, message: 'Its entries are removed from every ledger.', confirmLabel: 'Delete voucher' }))) return
    setBusyId(voucher.id)
    try {
      await deleteVoucher(voucher.id)
      toast(`${voucher.number} deleted.`)
      reload()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    } finally {
      setBusyId(null)
    }
  }

  const byType = result?.totals?.by_type ?? {}

  return (
    <div className="space-y-6">
      <PageHeader
        title="Day Book & Vouchers"
        description="Every accounting entry — bills, purchases, payments, expenses, returns and manual journals."
        actions={canManage && <Button icon={Plus} disabled={isAllStores} onClick={() => setCreating(true)}>New journal / contra</Button>}
      />
      {canManage && isAllStores && <Alert tone="info">Select a store in the header to record a journal or contra voucher.</Alert>}

      <div className="grid gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <Field label="From">
          <input type="date" className={inputClass} value={filters.from} max={filters.to} onChange={(e) => change({ from: e.target.value })} />
        </Field>
        <Field label="To">
          <input type="date" className={inputClass} value={filters.to} min={filters.from} onChange={(e) => change({ to: e.target.value })} />
        </Field>
        <Field label="Voucher type">
          <select className={inputClass} value={filters.type} onChange={(e) => change({ type: e.target.value })}>
            <option value="">All types</option>
            {Object.entries(VOUCHER_TYPES).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </Field>
        <Field label="Search">
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
            <input className={`${inputClass} pl-9`} placeholder="Number, narration or account" value={searchText} onChange={(e) => setSearchText(e.target.value)} />
          </div>
        </Field>
      </div>

      {error && <Alert>{error}</Alert>}

      {result && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label="Vouchers" value={result.totals.count} icon={NotebookPen} />
          <StatTile label="Total value" value={money(result.totals.amount)} />
          <StatTile label="Sales" value={money(byType.sales?.amount ?? 0)} sub={`${byType.sales?.count ?? 0} bills`} tone="green" />
          <StatTile
            label="Payments & expenses"
            value={money((Number(byType.payment?.amount ?? 0) + Number(byType.expense?.amount ?? 0)).toFixed(2))}
            sub={`${(byType.payment?.count ?? 0) + (byType.expense?.count ?? 0)} vouchers`}
            tone="red"
          />
        </div>
      )}

      <Card padded={false}>
        {!result && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result?.data.length === 0 && <EmptyState icon={NotebookPen} title="No vouchers in this period" />}
        {result?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className={headRowClass}>
                  <th className={`${thClass} w-8`} />
                  <th className={thClass}>Date</th>
                  <th className={thClass}>Voucher</th>
                  <th className={thClass}>Type</th>
                  <th className={thClass}>Narration</th>
                  <th className={`${thClass} text-right`}>Amount</th>
                  <th className={thClass}>By</th>
                  <th className={thClass} />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((v) => {
                  const open = expanded[v.id]
                  return (
                    <Fragment key={v.id}>
                      <tr className="cursor-pointer hover:bg-slate-50/60" onClick={() => setExpanded((x) => ({ ...x, [v.id]: !x[v.id] }))}>
                        <td className={`${tdClass} text-slate-400`}>{open ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</td>
                        <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDay(v.date)}</td>
                        <td className={`${tdClass} font-mono text-xs text-slate-700`}>
                          {v.number}
                          {isAllStores && v.store && <span className="ml-1 font-sans text-slate-400">· {v.store.code}</span>}
                        </td>
                        <td className={tdClass}><Badge tone={VOUCHER_TONES[v.type]}>{VOUCHER_TYPES[v.type] ?? v.type}</Badge></td>
                        <td className={`${tdClass} max-w-xs truncate text-slate-600`}>{v.narration ?? '—'}</td>
                        <td className={`${tdClass} text-right font-medium tabular-nums`}>{money(v.amount)}</td>
                        <td className={`${tdClass} text-slate-500`}>{v.created_by ?? '—'}</td>
                        <td className={`${tdClass} text-right`} onClick={(e) => e.stopPropagation()}>
                          {canManage && v.can_delete && (
                            <Button variant="ghost" size="sm" icon={Trash2} disabled={busyId === v.id} onClick={() => remove(v)} title="Delete" aria-label={`Delete ${v.number}`} />
                          )}
                        </td>
                      </tr>
                      {open && (
                        <tr className="bg-slate-50/60">
                          <td />
                          <td colSpan={7} className="px-4 pb-4 pt-1">
                            <VoucherEntries voucher={v} />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={(page) => setFilters((f) => ({ ...f, page }))} />
      </Card>

      {creating && (
        <VoucherForm
          onClose={() => setCreating(false)}
          onSaved={(voucher) => {
            toast(`${voucher.number} saved.`)
            setCreating(false)
            reload()
          }}
        />
      )}
    </div>
  )
}

/** Debit / credit lines of a voucher, plus the document behind it. */
function VoucherEntries({ voucher }) {
  const [source, setSource] = useState(null)

  useEffect(() => {
    if (voucher.is_manual) return
    getVoucher(voucher.id).then((v) => setSource(v.source_label)).catch(() => {})
  }, [voucher.id, voucher.is_manual])

  return (
    <div className="rounded-lg border border-slate-200 bg-white">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs uppercase tracking-wide text-slate-400">
            <th className="px-3 py-2">Account</th>
            <th className="px-3 py-2 text-right">Debit</th>
            <th className="px-3 py-2 text-right">Credit</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {voucher.entries.map((e, i) => (
            <tr key={i}>
              <td className={`px-3 py-1.5 ${Number(e.credit) > 0 ? 'pl-8' : ''}`}>
                <span className="font-mono text-xs text-slate-500">{e.code}</span> <span className="text-slate-800">{e.name}</span>
              </td>
              <td className="px-3 py-1.5 text-right tabular-nums">{money(e.debit, { blankZero: true })}</td>
              <td className="px-3 py-1.5 text-right tabular-nums">{money(e.credit, { blankZero: true })}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">
        {voucher.is_manual ? 'Manual entry' : source ? `From ${source}` : 'Posted by the software'}
        {voucher.store && ` · ${voucher.store.name}`}
      </p>
    </div>
  )
}

const emptyLine = () => ({ account_id: '', debit: '', credit: '' })

/** New journal or contra voucher with a live debit = credit check. */
function VoucherForm({ onClose, onSaved }) {
  const [type, setType] = useState('journal')
  const [date, setDate] = useState(today())
  const [narration, setNarration] = useState('')
  const [lines, setLines] = useState([emptyLine(), emptyLine()])
  const [accounts, setAccounts] = useState([])
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [saving, setSaving] = useState(false)

  // Contra vouchers only move money between cash and bank accounts.
  useEffect(() => {
    getAccountOptions(type === 'contra' ? { groups: 'cash,bank' } : {})
      .then(setAccounts)
      .catch(() => setAccounts([]))
  }, [type])

  const debits = lines.reduce((s, l) => s + toCents(l.debit), 0)
  const credits = lines.reduce((s, l) => s + toCents(l.credit), 0)
  const difference = debits - credits
  const ready = debits > 0 && difference === 0 && lines.every((l) => l.account_id && (toCents(l.debit) > 0) !== (toCents(l.credit) > 0))

  const setLine = (index, changes) => setLines((ls) => ls.map((l, i) => (i === index ? { ...l, ...changes } : l)))

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setMessage(null)
    try {
      const voucher = await createVoucher({
        type,
        date,
        narration: narration || null,
        lines: lines.map((l) => ({ account_id: Number(l.account_id), debit: l.debit || 0, credit: l.credit || 0 })),
      })
      onSaved(voucher)
    } catch (err) {
      const { message: msg, errors: errs } = parseApiError(err)
      setErrors(errs)
      setMessage(Object.values(errs)[0]?.[0] ?? msg)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      size="xl"
      title="New voucher"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="voucher-form" loading={saving} disabled={!ready}>Save voucher</Button>
        </>
      }
    >
      <form id="voucher-form" onSubmit={save} className="space-y-4">
        {message && <Alert>{message}</Alert>}
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Type">
            <select
              className={inputClass}
              value={type}
              onChange={(e) => {
                setType(e.target.value)
                setLines([emptyLine(), emptyLine()])
              }}
            >
              <option value="journal">Journal (any accounts)</option>
              <option value="contra">Contra (cash ⇄ bank)</option>
            </select>
          </Field>
          <Field label="Date" error={errors.date?.[0]}>
            <input type="date" className={inputClass} value={date} max={today()} onChange={(e) => setDate(e.target.value)} required />
          </Field>
          <Field label="Narration" error={errors.narration?.[0]}>
            <input className={inputClass} value={narration} maxLength={255} onChange={(e) => setNarration(e.target.value)} placeholder="e.g. Owner brought in cash" />
          </Field>
        </div>

        <div className="space-y-2">
          <div className="grid grid-cols-[1fr_110px_110px_32px] gap-2 text-xs font-medium uppercase tracking-wide text-slate-500">
            <span>Account</span>
            <span className="text-right">Debit</span>
            <span className="text-right">Credit</span>
            <span />
          </div>
          {lines.map((line, i) => (
            <div key={i} className="grid grid-cols-[1fr_110px_110px_32px] items-start gap-2">
              <div>
                <AccountPicker accounts={accounts} value={line.account_id} onChange={(id) => setLine(i, { account_id: id })} />
                {errors[`lines.${i}.account_id`] && <p className="mt-1 text-xs text-red-600">{errors[`lines.${i}.account_id`][0]}</p>}
                {errors[`lines.${i}.debit`] && <p className="mt-1 text-xs text-red-600">{errors[`lines.${i}.debit`][0]}</p>}
              </div>
              <input
                type="number" min="0" step="0.01" className={`${inputClass} text-right`} value={line.debit}
                onChange={(e) => setLine(i, { debit: e.target.value, ...(e.target.value ? { credit: '' } : {}) })}
              />
              <input
                type="number" min="0" step="0.01" className={`${inputClass} text-right`} value={line.credit}
                onChange={(e) => setLine(i, { credit: e.target.value, ...(e.target.value ? { debit: '' } : {}) })}
              />
              <button
                type="button"
                className="mt-1.5 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                disabled={lines.length <= 2}
                onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
                aria-label="Remove line"
              >
                <X size={16} />
              </button>
            </div>
          ))}
          <Button variant="ghost" size="sm" icon={Plus} onClick={() => setLines((ls) => [...ls, emptyLine()])} disabled={lines.length >= 50}>
            Add line
          </Button>
        </div>

        <div className={`flex flex-wrap items-center justify-between gap-2 rounded-lg px-4 py-3 text-sm ${difference === 0 && debits > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'}`}>
          <span className="tabular-nums">Debits {money((debits / 100).toFixed(2))} · Credits {money((credits / 100).toFixed(2))}</span>
          <span className="font-medium">
            {debits === 0 ? 'Enter the amounts' : difference === 0 ? 'Balanced ✓' : `Difference ${money((Math.abs(difference) / 100).toFixed(2))} ${difference > 0 ? 'Dr' : 'Cr'}`}
          </span>
        </div>
      </form>
    </Modal>
  )
}
