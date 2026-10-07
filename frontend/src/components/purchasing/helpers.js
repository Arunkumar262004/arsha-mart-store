// Shared non-component helpers for the purchasing pages.
import { taxOn, toCents } from '../../lib/money'

const iso = (d) => {
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
  return local.toISOString().slice(0, 10)
}

export const today = () => iso(new Date())

/** Date range for a period preset; custom keeps the given from / to. */
export function periodRange(preset, custom = {}) {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth()
  const finStart = m >= 3 ? y : y - 1 // April to March
  switch (preset) {
    case 'today':
      return { from: iso(now), to: iso(now) }
    case 'this_month':
      return { from: iso(new Date(y, m, 1)), to: iso(now) }
    case 'last_month':
      return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) }
    case 'fin_year':
      return { from: iso(new Date(finStart, 3, 1)), to: iso(now) }
    case 'custom':
      return { from: custom.from ?? '', to: custom.to ?? '' }
    default:
      return { from: '', to: '' }
  }
}

export const PERIODS = [
  ['today', 'Today'],
  ['this_month', 'This month'],
  ['last_month', 'Last month'],
  ['fin_year', 'This financial year'],
  ['all', 'All time'],
  ['custom', 'Custom'],
]

/**
 * GST on one line in paise, mirroring the backend: CGST + SGST at half the
 * rate each inside the state, IGST at the full rate across states.
 */
export function lineGst(subtotalCents, rate, interstate) {
  return interstate
    ? { cgst: 0, sgst: 0, igst: taxOn(subtotalCents, rate) }
    : { cgst: taxOn(subtotalCents, rate / 2), sgst: taxOn(subtotalCents, rate / 2), igst: 0 }
}

/** Totals in paise for lines of { quantity, unit (rupees), rate }. */
export function sumLines(lines, interstate) {
  const totals = { subtotal: 0, cgst: 0, sgst: 0, igst: 0 }
  for (const line of lines) {
    const subtotal = toCents(line.unit) * (Number(line.quantity) || 0)
    const gst = lineGst(subtotal, Number(line.rate) || 0, interstate)
    totals.subtotal += subtotal
    totals.cgst += gst.cgst
    totals.sgst += gst.sgst
    totals.igst += gst.igst
  }
  totals.tax = totals.cgst + totals.sgst + totals.igst
  totals.total = totals.subtotal + totals.tax
  return totals
}

export const formatDate = (value) =>
  value ? new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '-'

export const MODE_LABELS = { cash: 'Cash', bank: 'Bank', credit: 'Credit' }

let nextKey = 1
/** A blank product line for ProductLines. */
export const emptyLine = () => ({ key: nextKey++, product_id: '', quantity: 1, unit: '', rate: 0 })
