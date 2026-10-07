import { useId, useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { useAuth } from '../../auth/AuthContext'
import { Button, inputClass } from '../ui'
import { formatINR, toCents } from '../../lib/money'
import { emptyLine, lineGst } from './helpers'
import QuickProductModal from './QuickProductModal'

const label = (p) => `${p.name} · ${p.code}`

/**
 * Editable product lines (quantity, cost before GST, GST rate) with each
 * line's total. Picking a product fills its last cost and GST rate.
 * errors: Laravel errors map, read as items.<index>.<field>.
 * onProductCreated: when given (and the user may manage products), an
 * unmatched name offers to create the product right here.
 */
export default function ProductLines({ products, lines, onChange, interstate, errors = {}, costLabel = 'Cost / unit (ex-GST)', onProductCreated }) {
  const listId = useId()
  const { can } = useAuth()
  const canCreate = Boolean(onProductCreated) && can('products.manage')
  const [creatingFor, setCreatingFor] = useState(null) // line being given a new product
  const byLabel = new Map(products.map((p) => [label(p), p]))
  const byId = new Map(products.map((p) => [p.id, p]))

  const update = (key, patch) => onChange(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const remove = (key) => onChange(lines.length > 1 ? lines.filter((l) => l.key !== key) : [emptyLine()])

  // Accept the datalist label, or an exact product name or code typed by hand.
  const find = (text) => {
    const needle = text.trim().toLowerCase()
    return byLabel.get(text)
      ?? products.find((p) => p.name.toLowerCase() === needle || String(p.code ?? '').toLowerCase() === needle)
  }

  function pick(line, text) {
    const product = find(text)
    if (product) {
      update(line.key, {
        product_id: product.id,
        search: undefined,
        unit: product.cost_price ?? '',
        rate: Number(product.tax_percent),
      })
    } else {
      update(line.key, { product_id: '', search: text })
    }
  }

  function created(product) {
    const line = creatingFor
    setCreatingFor(null)
    onProductCreated(product)
    update(line.key, {
      product_id: product.id,
      search: undefined,
      unit: line.unit !== '' ? line.unit : product.cost_price ?? '',
      rate: Number(product.tax_percent),
    })
  }

  return (
    <div className="space-y-2">
      {creatingFor && (
        <QuickProductModal
          initial={{ name: creatingFor.search, cost_price: creatingFor.unit, tax_percent: creatingFor.rate }}
          products={products}
          onClose={() => setCreatingFor(null)}
          onCreated={created}
        />
      )}
      <datalist id={listId}>
        {products.map((p) => <option key={p.id} value={label(p)} />)}
      </datalist>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="py-2 pr-2">Product</th>
              <th className="w-24 py-2 pr-2 text-right">Qty</th>
              <th className="w-32 py-2 pr-2 text-right">{costLabel}</th>
              <th className="w-24 py-2 pr-2 text-right">GST %</th>
              <th className="w-32 py-2 pr-2 text-right">Amount</th>
              <th className="w-10" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line, index) => {
              const product = byId.get(Number(line.product_id))
              const subtotal = toCents(line.unit) * (Number(line.quantity) || 0)
              const gst = lineGst(subtotal, Number(line.rate) || 0, interstate)
              const err = (field) => errors[`items.${index}.${field}`]?.[0]
              return (
                <tr key={line.key} className="align-top">
                  <td className="py-1.5 pr-2">
                    <input
                      list={listId}
                      className={inputClass}
                      placeholder="Type to search products"
                      value={line.search ?? (product ? label(product) : '')}
                      onChange={(e) => pick(line, e.target.value)}
                      aria-label="Product"
                    />
                    {product && (
                      <p className="mt-0.5 text-xs text-slate-400">
                        In stock here: {product.stock}{product.unit ? ` ${product.unit}` : ''}
                        {product.hsn_code && ` · HSN ${product.hsn_code}`}
                      </p>
                    )}
                    {!product && line.search?.trim() && (
                      canCreate ? (
                        <p className="mt-0.5 text-xs text-amber-600">
                          No matching product.{' '}
                          <button type="button" className="font-medium text-brand-600 hover:underline" onClick={() => setCreatingFor(line)}>
                            + Create “{line.search.trim()}”
                          </button>
                        </p>
                      ) : (
                        <p className="mt-0.5 text-xs text-amber-600">No matching product. Pick one from the list, or ask an admin to add it.</p>
                      )
                    )}
                    {err('product_id') &&<p className="mt-0.5 text-xs text-red-600">{err('product_id')}</p>}
                  </td>
                  <td className="py-1.5 pr-2">
                    <input type="number" min="1" className={`${inputClass} text-right`} value={line.quantity}
                      onChange={(e) => update(line.key, { quantity: e.target.value })} aria-label="Quantity" />
                    {err('quantity') && <p className="mt-0.5 text-xs text-red-600">{err('quantity')}</p>}
                  </td>
                  <td className="py-1.5 pr-2">
                    <input type="number" min="0" step="0.01" className={`${inputClass} text-right`} value={line.unit}
                      onChange={(e) => update(line.key, { unit: e.target.value })} aria-label="Unit cost" />
                    {err('unit_cost') && <p className="mt-0.5 text-xs text-red-600">{err('unit_cost')}</p>}
                  </td>
                  <td className="py-1.5 pr-2">
                    <select className={inputClass} value={line.rate} onChange={(e) => update(line.key, { rate: e.target.value })} aria-label="GST rate">
                      {[0, 5, 12, 18, 28].map((t) => <option key={t} value={t}>{t}%</option>)}
                      {![0, 5, 12, 18, 28].includes(Number(line.rate)) && <option value={line.rate}>{Number(line.rate)}%</option>}
                    </select>
                  </td>
                  <td className="py-1.5 pr-2 text-right tabular-nums">
                    <p className="pt-2 font-medium text-slate-800">{formatINR(subtotal + gst.cgst + gst.sgst + gst.igst, { cents: true })}</p>
                    <p className="text-xs text-slate-400">GST {formatINR(gst.cgst + gst.sgst + gst.igst, { cents: true })}</p>
                  </td>
                  <td className="py-1.5">
                    <Button variant="ghost" size="sm" icon={Trash2} onClick={() => remove(line.key)} aria-label="Remove line" />
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <Button variant="secondary" size="sm" icon={Plus} onClick={() => onChange([...lines, emptyLine()])}>Add line</Button>
    </div>
  )
}
