// How a bill was paid (orders.payment_mode). Credit = pay later, on the customer's account.
export const PAYMENT_MODES = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'upi', label: 'UPI' },
  { value: 'credit', label: 'Credit (pay later)' },
]

/** 'upi' -> 'UPI'; unknown or missing modes fall back to Cash (older bills). */
export const paymentLabel = (mode) => PAYMENT_MODES.find((m) => m.value === (mode ?? 'cash'))?.label ?? mode

/** The invoice number when the bill has one (older bills only have the order number). */
export const billNumber = (order) => order.invoice_number || order.order_number
