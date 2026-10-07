// Shared colours and number formats for the dashboard charts.

/**
 * Categorical order (validated for colour-blind separation and contrast on
 * white): blue, amber, violet, teal. "Others" is always the neutral grey.
 */
export const SERIES = ['#2563eb', '#d97706', '#7c3aed', '#0f9f8f']
export const NEUTRAL = '#cbd5e1'

/** Payment modes keep the same colour everywhere (colour follows the entity). */
export const MODE_COLORS = { cash: SERIES[0], card: SERIES[1], upi: SERIES[2], credit: SERIES[3] }

const rupee = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 })

/** ₹28,540 — whole rupees for headline numbers. */
export const rupees = (value) => rupee.format(Math.round(Number(value || 0)))

/** ₹1.2L, ₹45K — axis labels and tight spots. */
export const compactRupees = (value) => {
  const n = Number(value || 0)
  if (n >= 1e7) return `₹${+(n / 1e7).toFixed(1)}Cr`
  if (n >= 1e5) return `₹${+(n / 1e5).toFixed(1)}L`
  if (n >= 1e3) return `₹${+(n / 1e3).toFixed(1)}K`
  return `₹${Math.round(n)}`
}

export const count = (value) => Number(value || 0).toLocaleString('en-IN')

export const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** A "nice" axis maximum and 5 evenly spaced ticks for it. */
export function niceTicks(max) {
  if (max <= 0) return [0, 1, 2, 3, 4, 5]
  const raw = max / 5
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw)
  return Array.from({ length: 6 }, (_, i) => i * step)
}
