import { Plus, Trash2 } from 'lucide-react'
import { formatINR, taxOn, toCents } from '../../lib/money'
import ProductPicker from '../ProductPicker'
import { Button, inputClass } from '../ui'

let nextKey = 1
/** A blank line; `unitPrice` '' means "the product's price". */
export const newLine = (patch = {}) => ({ key: nextKey++, productId: '', quantity: 1, unitPrice: '', ...patch })

/**
 * Live totals with the server's cent maths (see backend GstCalculator):
 * CGST + SGST at half the rate each, or IGST at the full rate.
 */
export function lineTotals(lines, productById, { interstate = false, withPrice = false } = {}) {
  const rows = lines.map((line) => {
    const product = productById.get(String(line.productId))
    const qty = Number(line.quantity) || 0
    if (!product || qty < 1) return { subtotal: 0, tax: 0, cgst: 0, sgst: 0, igst: 0 }
    const price = withPrice && line.unitPrice !== '' ? line.unitPrice : product.price
    const subtotal = toCents(price) * qty
    const rate = Number(product.tax_percent)
    const cgst = interstate ? 0 : taxOn(subtotal, rate / 2)
    const sgst = interstate ? 0 : taxOn(subtotal, rate / 2)
    const igst = interstate ? taxOn(subtotal, rate) : 0
    return { subtotal, cgst, sgst, igst, tax: cgst + sgst + igst }
  })
  const sum = (key) => rows.reduce((t, r) => t + r[key], 0)
  const subtotal = sum('subtotal')
  const tax = sum('tax')
  return { rows, subtotal, tax, cgst: sum('cgst'), sgst: sum('sgst'), igst: sum('igst'), total: subtotal + tax }
}

/** Request payload lines. */
export const linesPayload = (lines, { withPrice = false } = {}) =>
  lines
    .filter((l) => l.productId)
    .map((l) => ({
      product_id: Number(l.productId),
      quantity: Number(l.quantity),
      ...(withPrice && l.unitPrice !== '' ? { unit_price: Number(l.unitPrice) } : {}),
    }))

/**
 * Product lines for quotations, challans and transfers: product picker,
 * quantity, optionally an editable price, and the line amount.
 * With `checkStock`, the picker shows the current store's stock and warns
 * when a quantity is more than what is there.
 */
export default function LineItemsEditor({ products, productById, lines, setLines, withPrice = false, checkStock = false, totals, fieldError = () => null }) {
  const update = (key, patch) => setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const remove = (key) => setLines((prev) => prev.filter((l) => l.key !== key))
  const selected = new Set(lines.map((l) => String(l.productId)).filter(Boolean))
  const cols = withPrice
    ? 'sm:grid-cols-[minmax(0,1fr)_5.5rem_7rem_7rem_2.25rem]'
    : totals
      ? 'sm:grid-cols-[minmax(0,1fr)_5.5rem_7rem_2.25rem]'
      : 'sm:grid-cols-[minmax(0,1fr)_6rem_2.25rem]'

  return (
    <div>
      <div className={`hidden gap-3 border-b border-slate-100 pb-2 text-xs font-medium uppercase tracking-wide text-slate-500 sm:grid ${cols}`}>
        <span>Product</span>
        <span>Qty</span>
        {withPrice && <span className="text-right">Price / unit</span>}
        {totals && <span className="text-right">Amount</span>}
        <span />
      </div>
      <ul className="divide-y divide-slate-100">
        {lines.map((line, i) => {
          const product = productById.get(String(line.productId))
          const over = checkStock && product && Number(line.quantity) > product.stock
          const error =
            fieldError(`items.${i}.product_id`) ||
            fieldError(`items.${i}.quantity`) ||
            fieldError(`items.${i}.unit_price`) ||
            (over ? `Only ${product.stock} in stock.` : null)
          return (
            <li key={line.key} className={`grid grid-cols-2 items-start gap-3 py-3 ${cols}`}>
              <div className="col-span-2 min-w-0 sm:col-span-1">
                <ProductPicker
                  products={products}
                  value={line.productId}
                  onChange={(productId) => update(line.key, { productId, unitPrice: '' })}
                  describe={(p) => (checkStock ? (p.stock === 0 ? 'out of stock' : `${p.stock} in stock`) : formatINR(p.price))}
                  isDisabled={(p) => (checkStock && p.stock === 0) || (selected.has(String(p.id)) && String(p.id) !== String(line.productId))}
                  ariaLabel={`Product for row ${i + 1}`}
                  invalid={Boolean(error)}
                />
                {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
              </div>
              <input
                type="number"
                min="1"
                className={`${inputClass} ${over ? 'border-red-400' : ''}`}
                value={line.quantity}
                onChange={(e) => update(line.key, { quantity: e.target.value })}
                aria-label={`Quantity for row ${i + 1}`}
              />
              {withPrice && (
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  className={`${inputClass} text-right`}
                  placeholder={product ? Number(product.price).toFixed(2) : '0.00'}
                  value={line.unitPrice}
                  onChange={(e) => update(line.key, { unitPrice: e.target.value })}
                  aria-label={`Price for row ${i + 1}`}
                />
              )}
              {totals && (
                <div className="pt-2 text-right text-sm font-medium tabular-nums text-slate-800">
                  {formatINR(totals.rows[i]?.subtotal ?? 0, { cents: true })}
                  {product && Number(product.tax_percent) > 0 && (
                    <span className="block text-xs font-normal text-slate-400">+{Number(product.tax_percent)}% GST</span>
                  )}
                </div>
              )}
              <button
                type="button"
                onClick={() => remove(line.key)}
                className="justify-self-end rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                aria-label={`Delete row ${i + 1}`}
              >
                <Trash2 size={18} />
              </button>
            </li>
          )
        })}
      </ul>
      {fieldError('items') && <p className="text-xs text-red-600">{fieldError('items')}</p>}
      <Button
        size="sm"
        variant="secondary"
        icon={Plus}
        className="mt-2"
        onClick={() => setLines((prev) => [...prev, newLine()])}
        disabled={lines.length >= products.length}
      >
        Add product
      </Button>
    </div>
  )
}
