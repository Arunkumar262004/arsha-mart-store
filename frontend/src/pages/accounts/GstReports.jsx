import { useState } from 'react'
import { FileText, Percent } from 'lucide-react'
import { getGstReport } from '../../api/accounts'
import { Alert, Card, EmptyState, Field, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import DownloadButton from '../../components/accounts/DownloadButton'
import { formatDay, headRowClass, money, tdClass, thClass, thisMonth } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'

const SECTIONS = [
  ['b2b', 'B2B'],
  ['b2c', 'B2C'],
  ['hsn', 'HSN summary'],
  ['credit_notes', 'Credit notes'],
  ['documents', 'Documents'],
]

const TAX = [['igst', 'IGST'], ['cgst', 'CGST'], ['sgst', 'SGST']]

export default function GstReports() {
  const [form, setForm] = useState('gstr1')
  const [month, setMonth] = useState(thisMonth())
  const [section, setSection] = useState('b2c')

  const { data: result, loading, error } = useLoad(() => getGstReport(form, { month }), `${form}|${month}`)
  const report = result?.data?.month === month && (form === 'gstr1' ? 'hsn' in result.data : 'heads' in result.data) ? result.data : null

  return (
    <div className="space-y-6">
      <PageHeader
        title="GST Reports"
        description={`Monthly GSTR-1 and GSTR-3B summaries${result?.store ? ` · ${result.store}` : ''}${result?.gstin ? ` · GSTIN ${result.gstin}` : ''}.`}
        actions={
          form === 'gstr1' ? (
            <DownloadButton path="/accounts/gst/gstr1" params={{ month }} format="xlsx" filename={`gstr1-${month}.xlsx`} disabled={!report}>GSTR-1 Excel</DownloadButton>
          ) : (
            <DownloadButton path="/accounts/gst/gstr3b" params={{ month }} filename={`gstr3b-${month}.pdf`} disabled={!report}>GSTR-3B PDF</DownloadButton>
          )
        }
      />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex gap-1 rounded-xl bg-slate-100 p-1">
          {[['gstr1', 'GSTR-1'], ['gstr3b', 'GSTR-3B']].map(([value, label]) => (
            <button
              key={value}
              onClick={() => setForm(value)}
              className={`rounded-lg px-4 py-2 text-sm font-medium transition ${form === value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
            >
              {label}
            </button>
          ))}
        </div>
        <Field label="Return period">
          <input type="month" className={inputClass} value={month} max={thisMonth()} onChange={(e) => e.target.value && setMonth(e.target.value)} />
        </Field>
      </div>

      {error && <Alert>{error}</Alert>}
      {!report && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}

      {report && form === 'gstr1' && <Gstr1 report={report} section={section} setSection={setSection} />}
      {report && form === 'gstr3b' && <Gstr3b report={report} />}
      <p className="text-xs text-slate-500">These summaries are prepared from the bills and GST ledgers. Check them against the GST portal before filing.</p>
    </div>
  )
}

function Gstr1({ report, section, setSection }) {
  const t = report.totals
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label="B2B invoices" value={t.b2b.count} sub={`Taxable ${money(t.b2b.taxable)}`} icon={FileText} />
        <StatTile label="B2C taxable" value={money(t.b2c.taxable)} sub={`Tax ${money(t.b2c.tax)}`} icon={Percent} tone="green" />
        <StatTile label="Total tax (HSN)" value={money(t.hsn.tax)} sub={`On ${money(t.hsn.taxable)}`} />
        <StatTile label="Credit notes" value={t.credit_notes.count} sub={`Taxable ${money(t.credit_notes.taxable)}`} tone="amber" />
      </div>

      <Card padded={false}>
        <div className="flex flex-wrap gap-1 border-b border-slate-100 px-3 pt-3">
          {SECTIONS.map(([value, label]) => (
            <button
              key={value}
              onClick={() => setSection(value)}
              className={`rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium ${section === value ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
            >
              {label}
              {Array.isArray(report[value]) && <span className="ml-1 text-xs text-slate-400">({report[value].length})</span>}
            </button>
          ))}
        </div>
        {section === 'b2b' && !report.b2b_available && (
          <div className="p-5"><Alert tone="info">B2B needs customer GSTINs on bills (tax invoices). Until then every bill is reported as B2C.</Alert></div>
        )}
        <SectionTable section={section} rows={report[section]} />
      </Card>
    </>
  )
}

const COLUMNS = {
  b2b: [
    ['Invoice', (r) => <span className="font-mono text-xs">{r.invoice_number}</span>],
    ['Date', (r) => formatDay(r.date)],
    ['Customer / GSTIN', (r) => <><div>{r.customer ?? '—'}</div><div className="font-mono text-xs text-slate-500">{r.gstin}</div></>],
    ['Place of supply', (r) => r.place_of_supply ?? '—'],
  ],
  b2c: [
    ['Rate', (r) => `${Number(r.rate)}%`],
    ['Supply', (r) => r.supply_type],
    ['Bills', (r) => r.bills, true],
  ],
  hsn: [
    ['HSN', (r) => <span className="font-mono text-xs">{r.hsn ?? 'Not set'}</span>],
    ['Description', (r) => r.description],
    ['UQC', (r) => r.uqc],
    ['Rate', (r) => `${Number(r.rate)}%`],
    ['Qty', (r) => r.quantity, true],
  ],
  credit_notes: [
    ['Note', (r) => <span className="font-mono text-xs">{r.number}</span>],
    ['Date', (r) => formatDay(r.date)],
    ['Narration', (r) => r.narration ?? '—'],
  ],
}

function SectionTable({ section, rows }) {
  if (section === 'documents') {
    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[620px] text-sm">
          <thead>
            <tr className={headRowClass}>
              <th className={thClass}>Document</th>
              <th className={thClass}>From</th>
              <th className={thClass}>To</th>
              <th className={`${thClass} text-right`}>Total issued</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((d) => (
              <tr key={d.document}>
                <td className={tdClass}>{d.document}</td>
                <td className={`${tdClass} font-mono text-xs`}>{d.first ?? '—'}</td>
                <td className={`${tdClass} font-mono text-xs`}>{d.last ?? '—'}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{d.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )
  }

  if (rows.length === 0) return <EmptyState icon={Percent} title="Nothing in this section for the month" />

  const columns = COLUMNS[section]
  const sum = (key) => rows.reduce((s, r) => s + Number(r[key]), 0).toFixed(2)
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[860px] text-sm">
        <thead>
          <tr className={headRowClass}>
            {columns.map(([label, , right]) => <th key={label} className={`${thClass} ${right ? 'text-right' : ''}`}>{label}</th>)}
            <th className={`${thClass} text-right`}>Taxable</th>
            {TAX.map(([key, label]) => <th key={key} className={`${thClass} text-right`}>{label}</th>)}
            <th className={`${thClass} text-right`}>Total</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((r, i) => (
            <tr key={r.id ?? i} className="hover:bg-slate-50/60">
              {columns.map(([label, cell, right]) => <td key={label} className={`${tdClass} ${right ? 'text-right tabular-nums' : ''}`}>{cell(r)}</td>)}
              <td className={`${tdClass} text-right tabular-nums`}>{money(r.taxable)}</td>
              {TAX.map(([key]) => <td key={key} className={`${tdClass} text-right tabular-nums`}>{money(r[key], { blankZero: true })}</td>)}
              <td className={`${tdClass} text-right font-medium tabular-nums`}>{money(r.total)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
          <tr>
            <td className={tdClass} colSpan={columns.length}>Total</td>
            <td className={`${tdClass} text-right tabular-nums`}>{money(sum('taxable'))}</td>
            {TAX.map(([key]) => <td key={key} className={`${tdClass} text-right tabular-nums`}>{money(sum(key))}</td>)}
            <td className={`${tdClass} text-right tabular-nums`}>{money(sum('total'))}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  )
}

function Gstr3b({ report }) {
  const o = report.outward
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label="Outward taxable" value={money(o.taxable)} icon={FileText} />
        <StatTile label="Output tax" value={money(report.totals.output)} tone="amber" />
        <StatTile label="Input tax credit" value={money(report.itc.total)} tone="green" />
        <StatTile label="Net tax payable" value={money(report.totals.payable)} sub={Number(report.totals.carry_forward) > 0 ? `Credit c/f ${money(report.totals.carry_forward)}` : undefined} tone="red" />
      </div>

      <Card title="3.1 Outward supplies" padded={false}>
        <table className="w-full text-sm">
          <thead>
            <tr className={headRowClass}>
              <th className={thClass}>Nature of supplies</th>
              <th className={`${thClass} text-right`}>Taxable value</th>
              {TAX.map(([key, label]) => <th key={key} className={`${thClass} text-right`}>{label}</th>)}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            <tr>
              <td className={tdClass}>(a) Outward taxable supplies (net of returns)</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(o.taxable)}</td>
              {TAX.map(([key]) => <td key={key} className={`${tdClass} text-right tabular-nums`}>{money(o[key])}</td>)}
            </tr>
            <tr>
              <td className={tdClass}>(c) Nil rated / exempted (from bills)</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(o.nil_rated)}</td>
              <td colSpan={3} />
            </tr>
          </tbody>
        </table>
      </Card>

      <Card title="4 & 6. ITC and tax payable" padded={false}>
        <table className="w-full text-sm">
          <thead>
            <tr className={headRowClass}>
              <th className={thClass}>Head</th>
              <th className={`${thClass} text-right`}>Output tax</th>
              <th className={`${thClass} text-right`}>Eligible ITC</th>
              <th className={`${thClass} text-right`}>Payable</th>
              <th className={`${thClass} text-right`}>Credit c/f</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {TAX.map(([key, label]) => (
              <tr key={key}>
                <td className={`${tdClass} font-medium`}>{label}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(report.heads[key].output)}</td>
                <td className={`${tdClass} text-right tabular-nums`}>{money(report.heads[key].itc)}</td>
                <td className={`${tdClass} text-right font-medium tabular-nums`}>{money(report.heads[key].payable)}</td>
                <td className={`${tdClass} text-right tabular-nums text-emerald-700`}>{money(report.heads[key].carry_forward, { blankZero: true })}</td>
              </tr>
            ))}
          </tbody>
          <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
            <tr>
              <td className={tdClass}>Total</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(report.totals.output)}</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(report.totals.itc)}</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(report.totals.payable)}</td>
              <td className={`${tdClass} text-right tabular-nums`}>{money(report.totals.carry_forward, { blankZero: true })}</td>
            </tr>
          </tfoot>
        </table>
      </Card>
    </>
  )
}
