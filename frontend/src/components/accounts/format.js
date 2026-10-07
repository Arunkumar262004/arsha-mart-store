// Display helpers shared by the accounts pages.
import { formatINR } from '../../lib/money'

export const thClass = 'px-4 py-2.5'
export const tdClass = 'px-4 py-2.5'
export const headRowClass = 'bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500'

/** "₹1,234.50" for a decimal string; blank for null / zero when `blankZero`. */
export const money = (value, { blankZero = false } = {}) => {
  if (value == null || value === '') return ''
  if (blankZero && Number(value) === 0) return ''
  return formatINR(value)
}

/** "₹1,234.50 Dr" — a balance with its side; "₹0.00" when nil. */
export const drCr = (amount, side) => (side ? `${formatINR(amount)} ${side}` : formatINR(0))

/** Side of a signed amount, for client-side sums. */
export const sideOf = (signed) => (signed > 0 ? 'Dr' : signed < 0 ? 'Cr' : null)

export const formatDay = (date) =>
  date ? new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString('en-IN', { dateStyle: 'medium' }) : '—'

const pad = (n) => String(n).padStart(2, '0')
/** Today in the browser's local time as Y-m-d. */
export const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
export const thisMonth = () => today().slice(0, 7)
export const monthStart = () => `${thisMonth()}-01`
/** 1 April of the current financial year (India). */
export const finYearStart = () => {
  const d = new Date()
  const year = d.getMonth() < 3 ? d.getFullYear() - 1 : d.getFullYear()
  return `${year}-04-01`
}

export const VOUCHER_TYPES = {
  sales: 'Sales',
  purchase: 'Purchase',
  receipt: 'Receipt',
  payment: 'Payment',
  contra: 'Contra',
  journal: 'Journal',
  credit_note: 'Credit note',
  debit_note: 'Debit note',
  expense: 'Expense',
}

export const VOUCHER_TONES = {
  sales: 'green',
  purchase: 'amber',
  receipt: 'green',
  payment: 'red',
  expense: 'red',
  credit_note: 'amber',
  debit_note: 'amber',
  journal: 'brand',
  contra: 'brand',
}

export const PAYMENT_MODES = { cash: 'Cash', card: 'Card', upi: 'UPI', credit: 'Credit' }
