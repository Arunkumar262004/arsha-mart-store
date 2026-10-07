import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useReactToPrint } from 'react-to-print'
import { BookOpen, Landmark, Printer, Wallet } from 'lucide-react'
import { getAccountOptions, getLedger } from '../../api/accounts'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, Spinner, inputClass } from '../../components/ui'
import AccountPicker from '../../components/accounts/AccountPicker'
import { VOUCHER_TYPES, drCr, finYearStart, formatDay, headRowClass, money, tdClass, thClass, today } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'

/**
 * Account statement with running balance. ?account=<id> opens a ledger
 * directly (links from the chart of accounts); Cash book and Bank book are
 * the ledgers of accounts 1000 and 1010.
 */
export default function Ledger() {
  const { currentStore, isAllStores } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const accountId = searchParams.get('account') ?? ''
  const from = searchParams.get('from') ?? finYearStart()
  const to = searchParams.get('to') ?? today()
  const [accounts, setAccounts] = useState([])
  const sheet = useRef(null)

  useEffect(() => {
    getAccountOptions({ active: 0 }).then(setAccounts).catch(() => setAccounts([]))
  }, [])

  const setParam = (changes) =>
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current)
        for (const [key, value] of Object.entries(changes)) {
          if (value === '' || value == null) next.delete(key)
          else next.set(key, value)
        }
        return next
      },
      { replace: true },
    )

  const { data: ledger, loading, error } = useLoad(() => getLedger(accountId, { from, to }), `${accountId}|${from}|${to}`, { skip: !accountId })
  const shown = accountId && ledger?.account?.id === Number(accountId) ? ledger : null

  const print = useReactToPrint({
    contentRef: sheet,
    documentTitle: shown ? `Ledger ${shown.account.code} ${from} to ${to}` : 'Ledger',
    pageStyle: '@page { size: A4; margin: 12mm; } body { background: #fff; }',
  })

  const quick = (code) => accounts.find((a) => a.code === code)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Ledgers"
        description="Account statement with opening balance, every entry and the running balance."
        actions={
          <>
            {[['1000', 'Cash book', Wallet], ['1010', 'Bank book', Landmark]].map(([code, label, Icon]) => (
              <Button key={code} variant="secondary" icon={Icon} disabled={!quick(code)} onClick={() => setParam({ account: quick(code)?.id })}>
                {label}
              </Button>
            ))}
            <Button variant="secondary" icon={Printer} disabled={!shown} onClick={print}>Print</Button>
          </>
        }
      />

      <div className="grid gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Account" className="sm:col-span-2">
          <AccountPicker accounts={accounts} value={accountId} onChange={(id) => setParam({ account: id })} />
        </Field>
        <Field label="From">
          <input type="date" className={inputClass} value={from} max={to} onChange={(e) => setParam({ from: e.target.value })} />
        </Field>
        <Field label="To">
          <input type="date" className={inputClass} value={to} min={from} onChange={(e) => setParam({ to: e.target.value })} />
        </Field>
      </div>

      {error && <Alert>{error}</Alert>}

      {!accountId && (
        <Card>
          <EmptyState icon={BookOpen} title="Pick an account">Search by name or code, or open the cash book / bank book.</EmptyState>
        </Card>
      )}

      {accountId && !shown && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}

      {shown && (
        <Card padded={false}>
          <div ref={sheet} className="print:p-0">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
              <div>
                <h2 className="text-base font-semibold text-slate-900">
                  <span className="font-mono text-sm text-slate-500">{shown.account.code}</span> {shown.account.name}
                </h2>
                <p className="mt-0.5 text-xs text-slate-500">
                  {formatDay(shown.period.from)} – {formatDay(shown.period.to)} · {isAllStores || shown.all_stores ? 'All stores' : currentStore?.name ?? 'This store'}
                  {loading && <Spinner size={12} />}
                </p>
              </div>
              <div className="text-right text-sm">
                <p className="text-xs uppercase tracking-wide text-slate-500">Closing balance</p>
                <p className="text-lg font-semibold tabular-nums text-slate-900">{drCr(shown.closing.amount, shown.closing.side)}</p>
              </div>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[860px] text-sm">
                <thead>
                  <tr className={headRowClass}>
                    <th className={thClass}>Date</th>
                    <th className={thClass}>Particulars</th>
                    <th className={thClass}>Voucher</th>
                    <th className={`${thClass} text-right`}>Debit</th>
                    <th className={`${thClass} text-right`}>Credit</th>
                    <th className={`${thClass} text-right`}>Balance</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  <tr className="bg-slate-50/70 font-medium">
                    <td className={`${tdClass} text-slate-600`}>{formatDay(shown.period.from)}</td>
                    <td className={tdClass} colSpan={4}>Opening balance</td>
                    <td className={`${tdClass} text-right tabular-nums`}>{drCr(shown.opening.amount, shown.opening.side)}</td>
                  </tr>
                  {shown.data.map((e) => (
                    <tr key={e.id} className="hover:bg-slate-50/60">
                      <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDay(e.date)}</td>
                      <td className={tdClass}>
                        <div className="text-slate-800">{e.particulars || '—'}</div>
                        {e.narration && <div className="max-w-md truncate text-xs text-slate-500">{e.narration}</div>}
                      </td>
                      <td className={tdClass}>
                        <div className="font-mono text-xs text-slate-700">{e.number}</div>
                        <Badge>{VOUCHER_TYPES[e.type] ?? e.type}</Badge>
                        {shown.all_stores && e.store && <span className="ml-1 text-xs text-slate-400">{e.store}</span>}
                      </td>
                      <td className={`${tdClass} text-right tabular-nums`}>{money(e.debit, { blankZero: true })}</td>
                      <td className={`${tdClass} text-right tabular-nums`}>{money(e.credit, { blankZero: true })}</td>
                      <td className={`${tdClass} whitespace-nowrap text-right tabular-nums text-slate-700`}>{drCr(e.balance, e.side)}</td>
                    </tr>
                  ))}
                  {shown.data.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-slate-500">No entries in this period.</td>
                    </tr>
                  )}
                </tbody>
                <tfoot className="border-t-2 border-slate-200 font-semibold">
                  <tr>
                    <td className={tdClass} colSpan={3}>Period totals</td>
                    <td className={`${tdClass} text-right tabular-nums`}>{money(shown.totals.debit)}</td>
                    <td className={`${tdClass} text-right tabular-nums`}>{money(shown.totals.credit)}</td>
                    <td />
                  </tr>
                  <tr className="bg-slate-50">
                    <td className={tdClass} colSpan={5}>Closing balance</td>
                    <td className={`${tdClass} text-right tabular-nums`}>{drCr(shown.closing.amount, shown.closing.side)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            {shown.truncated && <p className="px-5 py-3 text-xs text-amber-700">Only the first 5,000 entries are listed; totals and closing balance include every entry. Narrow the dates to see the rest.</p>}
            {shown.all_stores && <p className="px-5 pb-4 pt-2 text-xs text-slate-500">All stores: the opening balance includes the account's opening balance from the chart of accounts.</p>}
          </div>
        </Card>
      )}
    </div>
  )
}
