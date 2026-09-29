import { useEffect, useState } from 'react'
import { CalendarRange, Search } from 'lucide-react'
import { getEmployeeOptions } from '../../api'
import { Field, Spinner, inputClass } from '../ui'

const PERIODS = [
  ['this_month', 'This month'],
  ['last_month', 'Last month'],
  ['last_3_months', 'Last 3 months'],
  ['last_6_months', 'Last 6 months'],
  ['this_year', 'This year'],
  ['current_fin_year', 'Current financial year'],
  ['last_fin_year', 'Last financial year'],
  ['custom', 'Custom range'],
]

const formatDay = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', { dateStyle: 'medium' })

/**
 * The filter bar every report shares: a date period (preset or custom
 * range), and optionally an employee picker, a search box and extra fields.
 */
export default function ReportFilters({ filters, setFilters, period, loading, employeeLabel, searchPlaceholder, children }) {
  const custom = filters.period === 'custom'

  return (
    <div className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Period">
          <select
            className={inputClass}
            value={filters.period}
            onChange={(e) => setFilters({ period: e.target.value, ...(e.target.value === 'custom' ? {} : { from: '', to: '' }) })}
          >
            {PERIODS.map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </Field>

        {custom && (
          <>
            <Field label="From">
              <input type="date" className={inputClass} value={filters.from ?? ''} max={filters.to || undefined} onChange={(e) => setFilters({ from: e.target.value })} />
            </Field>
            <Field label="To">
              <input type="date" className={inputClass} value={filters.to ?? ''} min={filters.from || undefined} onChange={(e) => setFilters({ to: e.target.value })} />
            </Field>
          </>
        )}

        {employeeLabel && (
          <EmployeeSelect label={employeeLabel} value={filters.employee_id ?? ''} onChange={(employee_id) => setFilters({ employee_id })} />
        )}

        {children}

        {searchPlaceholder && <SearchBox placeholder={searchPlaceholder} value={filters.search ?? ''} onChange={(search) => setFilters({ search })} />}
      </div>

      <p className="mt-3 flex items-center gap-2 text-xs text-slate-500">
        <CalendarRange size={14} aria-hidden />
        {custom && !(filters.from && filters.to)
          ? 'Pick both dates to run the report.'
          : period
            ? `${formatDay(period.from)} – ${formatDay(period.to)}`
            : '…'}
        {loading && <Spinner size={12} />}
      </p>
    </div>
  )
}

function EmployeeSelect({ label, value, onChange }) {
  const [employees, setEmployees] = useState([])

  useEffect(() => {
    getEmployeeOptions().then(setEmployees).catch(() => setEmployees([]))
  }, [])

  return (
    <Field label={label}>
      <select className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">All employees</option>
        {employees.map((e) => (
          <option key={e.id} value={e.id}>
            {e.name}
            {e.is_active ? '' : ' (inactive)'}
          </option>
        ))}
      </select>
    </Field>
  )
}

/** Searches as you type, after a short pause. */
function SearchBox({ placeholder, value, onChange }) {
  const [text, setText] = useState(value)

  useEffect(() => {
    if (text.trim() === value) return
    const timer = setTimeout(() => onChange(text.trim()), 350)
    return () => clearTimeout(timer)
  }, [text, value, onChange])

  return (
    <Field label="Search">
      <div className="relative">
        <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
        <input className={`${inputClass} pl-9`} placeholder={placeholder} value={text} onChange={(e) => setText(e.target.value)} />
      </div>
    </Field>
  )
}

/** Previous / next paging under a report table. */
export function Pager({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm">
      <span className="text-slate-500">
        Page {meta.current_page} of {meta.last_page} · {meta.total} rows
      </span>
      <div className="flex gap-2">
        <button
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
        >
          ← Prev
        </button>
        <button
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
        >
          Next →
        </button>
      </div>
    </div>
  )
}
