import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Scale } from 'lucide-react'
import { getStatement } from '../../api/accounts'
import { Alert, Card, Field, PageHeader, Spinner, inputClass } from '../../components/ui'
import DownloadButton from '../../components/accounts/DownloadButton'
import { finYearStart, formatDay, headRowClass, money, tdClass, thClass, today } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'

const TABS = [
  ['trial-balance', 'Trial Balance'],
  ['profit-loss', 'Profit & Loss'],
  ['balance-sheet', 'Balance Sheet'],
]

export default function Statements() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.some(([t]) => t === searchParams.get('tab')) ? searchParams.get('tab') : 'trial-balance'
  const [asOf, setAsOf] = useState(today())
  const [range, setRange] = useState({ from: finYearStart(), to: today() })

  const params = tab === 'profit-loss' ? range : { as_of: asOf }
  const { data: result, loading, error } = useLoad(() => getStatement(tab, params), `${tab}|${JSON.stringify(params)}`)
  // Ignore the previous tab's answer while the new one loads.
  const report = result && isFor(tab, result.data) ? result.data : null

  return (
    <div className="space-y-6">
      <PageHeader
        title="Financial Statements"
        description={`Built from every voucher in the books${result?.store ? ` · ${result.store}` : ''}.`}
        actions={<DownloadButton path={`/accounts/${tab}`} params={params} filename={`${tab}.pdf`} disabled={!report} />}
      />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex gap-1 rounded-xl bg-slate-100 p-1">
          {TABS.map(([value, label]) => (
            <button
              key={value}
              onClick={() => setSearchParams({ tab: value }, { replace: true })}
              className={`rounded-lg px-4 py-2 text-sm font-medium transition ${tab === value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
            >
              {label}
            </button>
          ))}
        </div>
        <div className="flex flex-wrap gap-3">
          {tab === 'profit-loss' ? (
            <>
              <Field label="From">
                <input type="date" className={inputClass} value={range.from} max={range.to} onChange={(e) => e.target.value && setRange((r) => ({ ...r, from: e.target.value }))} />
              </Field>
              <Field label="To">
                <input type="date" className={inputClass} value={range.to} min={range.from} onChange={(e) => e.target.value && setRange((r) => ({ ...r, to: e.target.value }))} />
              </Field>
            </>
          ) : (
            <Field label="As at">
              <input type="date" className={inputClass} value={asOf} onChange={(e) => e.target.value && setAsOf(e.target.value)} />
            </Field>
          )}
        </div>
      </div>

      {error && <Alert>{error}</Alert>}
      {!report && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}

      {report && tab === 'trial-balance' && <TrialBalance report={report} />}
      {report && tab === 'profit-loss' && <ProfitLoss report={report} />}
      {report && tab === 'balance-sheet' && <BalanceSheet report={report} />}
    </div>
  )
}

const isFor = (tab, data) =>
  tab === 'trial-balance' ? 'rows' in data : tab === 'profit-loss' ? 'trading' in data : 'assets' in data

function TrialBalance({ report }) {
  return (
    <Card padded={false} title={`Trial balance as at ${formatDay(report.as_of)}`}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[620px] text-sm">
          <thead>
            <tr className={headRowClass}>
              <th className={thClass}>Account</th>
              <th className={thClass}>Group</th>
              <th className={`${thClass} text-right`}>Debit</th>
              <th className={`${thClass} text-right`}>Credit</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {report.rows.map((r) => (
              <tr key={r.id} className="hover:bg-slate-50/60">
                <td className={tdClass}><span className="font-mono text-xs text-slate-500">{r.code}</span> <span className="text-slate-800">{r.name}</span></td>
                <td className={`${tdClass} text-xs capitalize text-slate-500`}>{r.group.replace('_', ' ')}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(r.debit)}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(r.credit)}</td>
              </tr>
            ))}
            {report.opening_difference && (
              <tr className="bg-amber-50/60 text-amber-800">
                <td className={tdClass} colSpan={2}>Difference in opening balances</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(report.opening_difference.debit)}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(report.opening_difference.credit)}</td>
              </tr>
            )}
          </tbody>
          <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
            <tr>
              <td className={tdClass} colSpan={2}>Total</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(report.totals.debit)}</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(report.totals.credit)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p className="px-5 py-3 text-xs text-slate-500">Closing stock (valued from stock on hand, not a ledger balance): {money(report.closing_stock)}</p>
    </Card>
  )
}

/** One side of a Tally-style two-column account. */
function Side({ title, lines }) {
  return (
    <div className="min-w-0">
      <div className="flex justify-between border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">
        <span>{title}</span>
        <span>Amount</span>
      </div>
      <div className="divide-y divide-slate-100">
        {lines.map((line) => (
          <div key={line.label} className="px-4 py-2">
            <div className={`flex justify-between gap-3 ${line.bold ? 'font-semibold text-slate-900' : 'text-slate-800'}`}>
              <span>{line.label}</span>
              <span className="tabular-nums">{money(line.amount)}</span>
            </div>
            {line.lines.map((l) => (
              <div key={l.id} className="flex justify-between gap-3 pl-4 text-xs text-slate-500">
                <span className="truncate">{l.name}</span>
                <span className="tabular-nums">{money(l.amount)}</span>
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}

function TwoColumn({ title, account }) {
  return (
    <Card padded={false} title={title}>
      <div className="grid md:grid-cols-2 md:divide-x md:divide-slate-200">
        <Side title="Particulars (Dr)" lines={account.debit} />
        <Side title="Particulars (Cr)" lines={account.credit} />
      </div>
      <div className="grid border-t-2 border-slate-200 bg-slate-50 text-sm font-semibold md:grid-cols-2 md:divide-x md:divide-slate-200">
        {[0, 1].map((i) => (
          <div key={i} className="flex justify-between px-4 py-2.5">
            <span>Total</span>
            <span className="tabular-nums">{money(account.total)}</span>
          </div>
        ))}
      </div>
    </Card>
  )
}

function ProfitLoss({ report }) {
  const net = Number(report.net_profit)
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Summary label="Sales (net)" value={report.sales} />
        <Summary label={Number(report.gross_profit) >= 0 ? 'Gross profit' : 'Gross loss'} value={Math.abs(report.gross_profit)} tone={Number(report.gross_profit) >= 0 ? 'good' : 'bad'} />
        <Summary label={net >= 0 ? 'Net profit' : 'Net loss'} value={Math.abs(net)} tone={net >= 0 ? 'good' : 'bad'} />
      </div>
      <TwoColumn title={`Trading account · ${formatDay(report.from)} – ${formatDay(report.to)}`} account={report.trading} />
      <TwoColumn title="Profit & Loss account" account={report.profit_loss} />
      <p className="text-xs text-slate-500">
        Stock valuation: {report.valuation_basis}. Opening stock is the stock on hand at the end of the day before the period; stock added without a
        purchase entry (e.g. restocks) also counts.
      </p>
    </div>
  )
}

function Summary({ label, value, tone }) {
  const color = tone === 'good' ? 'text-emerald-700' : tone === 'bad' ? 'text-red-600' : 'text-slate-900'
  return (
    <div className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-2 text-2xl font-semibold tabular-nums ${color}`}>{money(value)}</p>
    </div>
  )
}

function BalanceSheet({ report }) {
  const difference = Number(report.difference)
  const side = (title, groups, total) => (
    <div className="min-w-0">
      <div className="flex justify-between border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">
        <span>{title}</span>
        <span>Amount</span>
      </div>
      <div className="divide-y divide-slate-100">
        {groups.map((g) => (
          <div key={g.key} className="px-4 py-2">
            <div className="flex justify-between gap-3 font-medium text-slate-900">
              <span>{g.label}</span>
              <span className="tabular-nums">{money(g.total)}</span>
            </div>
            {g.accounts.map((a, i) => (
              <div key={a.id ?? `x${i}`} className="flex justify-between gap-3 pl-4 text-xs text-slate-500">
                <span className="truncate">{a.name}</span>
                <span className="tabular-nums">{money(a.amount)}</span>
              </div>
            ))}
          </div>
        ))}
      </div>
      <div className="flex justify-between border-t-2 border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold">
        <span>Total</span>
        <span className="tabular-nums">{money(total)}</span>
      </div>
    </div>
  )

  return (
    <div className="space-y-4">
      {difference !== 0 && (
        <Alert tone="warning">
          Assets and liabilities differ by {money(Math.abs(difference))}: the opening balances entered in the chart of accounts don't agree (debits ≠ credits).
        </Alert>
      )}
      <Card padded={false} title={`Balance sheet as at ${formatDay(report.as_of)}`}>
        <div className="grid md:grid-cols-2 md:divide-x md:divide-slate-200">
          {side('Liabilities & capital', report.liabilities, report.total_liabilities)}
          {side('Assets', report.assets, report.total_assets)}
        </div>
      </Card>
      <p className="flex items-center gap-2 text-xs text-slate-500">
        <Scale size={14} aria-hidden /> Profit & Loss A/c = all income − expenses to date + closing stock ({money(report.closing_stock)}; {report.valuation_basis}).
      </p>
    </div>
  )
}
