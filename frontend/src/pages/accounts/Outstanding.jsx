import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BookOpen, Clock, HandCoins, Search, UsersRound } from 'lucide-react'
import { getOutstanding } from '../../api/accounts'
import { Alert, Card, EmptyState, Field, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import { formatDay, headRowClass, money, tdClass, thClass, today } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'

const TABS = [
  ['receivable', 'Receivables', 'What customers owe the store'],
  ['payable', 'Payables', 'What the store owes suppliers'],
]

const BUCKETS = [
  ['0_30', '0–30 days'],
  ['31_60', '31–60 days'],
  ['61_90', '61–90 days'],
  ['90_plus', '90+ days'],
]

export default function Outstanding() {
  const [type, setType] = useState('receivable')
  const [asOf, setAsOf] = useState(today())
  const [search, setSearch] = useState('')

  const params = { type, as_of: asOf, ...(search.trim() ? { search: search.trim() } : {}) }
  const { data: result, loading, error } = useLoad(() => getOutstanding(params), JSON.stringify(params))
  const shown = result?.type === type ? result : null
  const totals = shown?.totals

  return (
    <div className="space-y-6">
      <PageHeader title="Outstanding" description="Party balances with ageing: payments settle the oldest bills first." />

      <div className="flex gap-1 rounded-xl bg-slate-100 p-1 sm:w-fit">
        {TABS.map(([value, label]) => (
          <button
            key={value}
            onClick={() => setType(value)}
            className={`flex-1 rounded-lg px-4 py-2 text-sm font-medium transition sm:flex-none ${type === value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="grid gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-3">
        <Field label="As of">
          <input type="date" className={inputClass} value={asOf} max={today()} onChange={(e) => setAsOf(e.target.value || today())} />
        </Field>
        <Field label="Search" className="sm:col-span-2">
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
            <input className={`${inputClass} pl-9`} placeholder="Party name or ledger code" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </Field>
      </div>

      {error && <Alert>{error}</Alert>}

      {totals && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label={type === 'receivable' ? 'Total receivable' : 'Total payable'} value={money(totals.balance)} sub={`${shown.count} parties`} icon={type === 'receivable' ? HandCoins : UsersRound} />
          <StatTile label="Within 30 days" value={money(totals['0_30'])} tone="green" />
          <StatTile label="31–90 days" value={money((Number(totals['31_60']) + Number(totals['61_90'])).toFixed(2))} tone="amber" icon={Clock} />
          <StatTile label="Over 90 days" value={money(totals['90_plus'])} tone="red" />
        </div>
      )}

      <Card padded={false}>
        {!shown && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {shown?.data.length === 0 && (
          <EmptyState icon={HandCoins} title={type === 'receivable' ? 'No customer owes anything' : 'Nothing is owed to suppliers'}>
            {TABS.find((t) => t[0] === type)[2]} as of {formatDay(asOf)}.
          </EmptyState>
        )}
        {shown?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[980px] text-sm">
              <thead>
                <tr className={headRowClass}>
                  <th className={thClass}>Party</th>
                  <th className={thClass}>Contact</th>
                  <th className={thClass}>Last entry</th>
                  {BUCKETS.map(([key, label]) => <th key={key} className={`${thClass} text-right`}>{label}</th>)}
                  <th className={`${thClass} text-right`}>Balance</th>
                  <th className={thClass} />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {shown.data.map((row) => (
                  <tr key={row.account_id} className="hover:bg-slate-50/60">
                    <td className={tdClass}>
                      <div className="font-medium text-slate-800">{row.name}</div>
                      <div className="font-mono text-xs text-slate-500">{row.code}</div>
                    </td>
                    <td className={`${tdClass} text-xs text-slate-600`}>
                      {row.phone && <div>{row.phone}</div>}
                      {row.email && <div className="truncate">{row.email}</div>}
                      {!row.phone && !row.email && '—'}
                    </td>
                    <td className={`${tdClass} whitespace-nowrap text-slate-600`}>{formatDay(row.last_transaction)}</td>
                    {BUCKETS.map(([key]) => (
                      <td key={key} className={`${tdClass} text-right tabular-nums ${key === '90_plus' && Number(row.buckets[key]) > 0 ? 'text-red-600' : ''}`}>
                        {money(row.buckets[key], { blankZero: true }) || '—'}
                      </td>
                    ))}
                    <td className={`${tdClass} text-right font-semibold tabular-nums`}>
                      {Number(row.balance) < 0 ? (
                        <span className="text-emerald-700" title="Paid in advance">{money(Math.abs(row.balance))} adv.</span>
                      ) : (
                        `${money(row.balance)} ${row.side ?? ''}`
                      )}
                    </td>
                    <td className={`${tdClass} text-right`}>
                      <Link to={`/accounts/ledger?account=${row.account_id}`} className="inline-flex rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800" title="Open ledger">
                        <BookOpen size={14} />
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                <tr>
                  <td className={tdClass} colSpan={3}>Total</td>
                  {BUCKETS.map(([key]) => <td key={key} className={`${tdClass} text-right tabular-nums`}>{money(totals[key])}</td>)}
                  <td className={`${tdClass} text-right tabular-nums`}>{money(totals.balance)}</td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </Card>
      {Number(totals?.advance ?? 0) > 0 && <p className="text-xs text-slate-500">Includes {money(totals.advance)} paid in advance (shown as negative balances).</p>}
    </div>
  )
}
