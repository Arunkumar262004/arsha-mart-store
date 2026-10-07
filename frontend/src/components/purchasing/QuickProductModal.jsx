import { useState } from 'react'
import { createPortal } from 'react-dom'
import { createProduct } from '../../api'
import { parseApiError } from '../../api/client'
import { Alert, Button, Field, Modal, inputClass } from '../ui'
import { UNITS } from '../../lib/units'

/** Code suggestion from the name, e.g. "Milk Bikis 100g" -> "MILK-BIKIS-100G", kept unique. */
function suggestCode(name, takenCodes) {
  const base = name.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'ITEM'
  let code = base
  for (let n = 2; takenCodes.has(code); n++) code = `${base}-${n}`
  return code
}

/**
 * Add a product without leaving a purchase. No opening stock: the purchase
 * being entered brings the stock in.
 * initial: { name, cost_price, tax_percent } taken from the purchase line.
 */
export default function QuickProductModal({ initial, products, onClose, onCreated }) {
  const [form, setForm] = useState(() => ({
    name: initial.name.trim(),
    code: suggestCode(initial.name.trim(), new Set(products.map((p) => p.code))),
    hsn_code: '',
    unit: 'pcs',
    price: '',
    cost_price: initial.cost_price ?? '',
    tax_percent: initial.tax_percent ?? 18,
  }))
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function submit(e) {
    e.preventDefault()
    // The modal is portalled out of the purchase form in the DOM, but React
    // still bubbles the submit event to it.
    e.stopPropagation()
    setSaving(true)
    setError(null)
    try {
      const product = await createProduct({
        ...form,
        name: form.name.trim(),
        hsn_code: form.hsn_code || null,
        cost_price: form.cost_price === '' ? null : form.cost_price,
        stock: 0,
      })
      onCreated({ ...product, stock: 0 })
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return createPortal(
    <Modal
      open
      title="New product"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="quick-product-form" loading={saving}>Create and use</Button>
        </>
      }
    >
      <form id="quick-product-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        {error && !Object.keys(error.errors ?? {}).length && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
        <Field label="Product name" error={fieldError('name')} className="sm:col-span-2">
          <input className={inputClass} value={form.name} onChange={set('name')} autoFocus />
        </Field>
        <Field label="Code (SKU)" error={fieldError('code')} hint="Suggested from the name; change if you like.">
          <input className={`${inputClass} font-mono uppercase`} value={form.code} onChange={set('code')} />
        </Field>
        <Field label="HSN code" error={fieldError('hsn_code')} hint="Optional, from the supplier's bill.">
          <input
            className={`${inputClass} font-mono`}
            value={form.hsn_code}
            inputMode="numeric"
            maxLength={8}
            onChange={(e) => setForm((f) => ({ ...f, hsn_code: e.target.value.replace(/\D/g, '') }))}
          />
        </Field>
        <Field label="Unit" error={fieldError('unit')}>
          <select className={inputClass} value={form.unit} onChange={set('unit')}>
            {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
          </select>
        </Field>
        <Field label="Tax (GST %)" error={fieldError('tax_percent')}>
          <select className={inputClass} value={form.tax_percent} onChange={set('tax_percent')}>
            {[0, 5, 12, 18, 28].map((t) => <option key={t} value={t}>{t}%</option>)}
          </select>
        </Field>
        <Field label="Cost price (₹)" error={fieldError('cost_price')} hint="Before GST, as on the bill.">
          <input type="number" min="0" step="0.01" className={inputClass} value={form.cost_price} onChange={set('cost_price')} />
        </Field>
        <Field label="Selling price per unit (₹)" error={fieldError('price')}>
          <input type="number" min="0" step="0.01" className={inputClass} value={form.price} onChange={set('price')} />
        </Field>
        <p className="text-xs text-slate-500 sm:col-span-2">Stock comes in when you save this purchase, so there's no opening stock here.</p>
      </form>
    </Modal>,
    document.body,
  )
}
