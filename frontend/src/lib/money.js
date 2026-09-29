// Mirrors backend App\Support\Money so the on-screen preview matches the
// bill the server computes. The server's figures are always authoritative.

export const toCents = (amount) => Math.round(Number(amount || 0) * 100)

export const taxOn = (cents, percent) => {
  const basisPoints = Math.round(Number(percent || 0) * 100)
  return Math.floor((cents * basisPoints + 5000) / 10000)
}

export const formatINR = (value, { cents = false } = {}) => {
  const amount = cents ? value / 100 : Number(value || 0)
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(amount)
}

const DENOMINATIONS = [500, 200, 100, 50, 20, 10, 5, 2, 1]

/**
 * Greedy note/coin breakdown for the change to hand back,
 * e.g. 2280 cents -> [{ value: 20, count: 1 }, { value: 2, count: 1 }] + 80 paise.
 */
export function changeBreakdown(changeCents) {
  let rupees = Math.floor(changeCents / 100)
  const paise = changeCents % 100
  const parts = []
  for (const value of DENOMINATIONS) {
    const count = Math.floor(rupees / value)
    if (count > 0) {
      parts.push({ value, count })
      rupees -= count * value
    }
  }
  return { parts, paise }
}
