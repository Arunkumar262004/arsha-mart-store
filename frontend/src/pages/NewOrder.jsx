import { useCallback, useEffect, useMemo, useState } from 'react'
import { createOrder, getProducts } from '../api'
import { parseApiError } from '../api/client'
import Bill from '../components/Bill'
import LowStockAlert from '../components/LowStockAlert'
import { Plus, ReceiptText, Trash2, UserRoundPen } from 'lucide-react'
import CustomerUpdateDialog from '../components/CustomerUpdateDialog'
import { Alert, Badge, Button, Card, Field, Spinner, inputClass } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import useCustomerLookup from '../hooks/useCustomerLookup'
import ProductPicker from '../components/ProductPicker'
import { PAYMENT_MODES } from '../components/receipt/paymentModes'
import BusinessCustomerSection, { EMPTY_B2B, TaxInvoiceOffer, b2bPayload } from '../components/documents/BusinessCustomerSection'
import { changeBreakdown, formatINR, taxOn, toCents } from '../lib/money'

let nextKey = 1
const newLine = () => ({ key: nextKey++, productId: '', quantity: 1 })

// Sales inside this state get CGST + SGST; customers elsewhere get IGST.
const STORE_STATE = import.meta.env.VITE_STORE_STATE ?? 'Tamil Nadu'

export default function NewOrder() {
  const { can, currentStore, isAllStores } = useAuth()
  const [products, setProducts] = useState([])
  const [loadError, setLoadError] = useState(null)

  const customer = useCustomerLookup()

  const [lines, setLines] = useState([newLine()])
  const [amountGiven, setAmountGiven] = useState('')
  const [paymentMode, setPaymentMode] = useState('cash') // cash | card | upi | credit (pay later)
  const isCredit = paymentMode === 'credit'
  const [interstate, setInterstate] = useState(false) // customer outside the store's state: IGST
  const [b2b, setB2b] = useState(EMPTY_B2B) // optional GSTIN / billing address / place of supply

  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null) // { message, errors }
  const [order, setOrder] = useState(null)
  const [confirmingCustomer, setConfirmingCustomer] = useState(false)
  const [lowStockKey, setLowStockKey] = useState(0)

  const loadProducts = useCallback(() => {
    getProducts()
      .then((data) => {
        setProducts(data)
        setLoadError(null)
      })
      .catch((e) => setLoadError(parseApiError(e).message))
  }, [])

  useEffect(loadProducts, [loadProducts])

  const productById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products])

  // Live preview using the same cent maths as the server: CGST + SGST at half
  // the rate each within the state, or IGST at the full rate for another state.
  const preview = useMemo(() => {
    const rows = lines.map((line) => {
      const product = productById.get(String(line.productId))
      const qty = Number(line.quantity) || 0
      if (!product || qty < 1) return { subtotal: 0, cgst: 0, sgst: 0, igst: 0, total: 0 }
      const lineSubtotal = toCents(product.price) * qty
      const rate = Number(product.tax_percent)
      const cgst = interstate ? 0 : taxOn(lineSubtotal, rate / 2)
      const sgst = interstate ? 0 : taxOn(lineSubtotal, rate / 2)
      const igst = interstate ? taxOn(lineSubtotal, rate) : 0
      return { subtotal: lineSubtotal, cgst, sgst, igst, total: lineSubtotal + cgst + sgst + igst }
    })
    const sum = (key) => rows.reduce((total, r) => total + r[key], 0)
    const subtotal = sum('subtotal')
    const cgst = sum('cgst')
    const sgst = sum('sgst')
    const igst = sum('igst')
    return { rows, subtotal, cgst, sgst, igst, total: subtotal + cgst + sgst + igst }
  }, [lines, productById, interstate])

  const givenCents = amountGiven === '' ? null : toCents(amountGiven)
  const balanceCents = givenCents === null ? null : givenCents - preview.total

  const fieldError = (key) => error?.errors?.[key]?.[0]

  function updateLine(key, patch) {
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  }

  function removeLine(key) {
    setLines((prev) => prev.filter((l) => l.key !== key))
  }

  function resetForm() {
    customer.reset()
    setLines([newLine()])
    setAmountGiven('')
    setPaymentMode('cash')
    setInterstate(false)
    setB2b(EMPTY_B2B)
    setError(null)
    setOrder(null)
  }

  function handleSubmit(event) {
    event.preventDefault()
    setError(null)

    const clientErrors = {}
    lines.forEach((line, i) => {
      if (!line.productId) clientErrors[`items.${i}.product_id`] = ['Select a product or remove this row.']
    })
    if (Object.keys(clientErrors).length) {
      setError({ message: 'Please fix the highlighted rows.', errors: clientErrors })
      return
    }

    // The form differs from the saved customer: ask how to proceed, then bill.
    if (customer.changes.length) {
      setConfirmingCustomer(true)
      return
    }

    submitOrder()
  }

  /** `override` lets the confirm dialog choose which customer to bill and whether to update them. */
  async function submitOrder(override = {}) {
    setConfirmingCustomer(false)
    setSubmitting(true)
    try {
      const created = await createOrder({
        customer_email: customer.email.trim(),
        customer_name: customer.name.trim() || null,
        customer_phone: customer.phone.trim() || null,
        items: lines.map((l) => ({ product_id: Number(l.productId), quantity: Number(l.quantity) })),
        // Credit bills are paid later, so no amount is taken now.
        amount_paid: isCredit || amountGiven === '' ? null : Number(amountGiven),
        payment_mode: paymentMode,
        interstate,
        ...b2bPayload(b2b),
        ...override,
      })
      setOrder(created)
    } catch (e) {
      const err = parseApiError(e)
      if (err.conflict?.type === 'phone_owner') {
        // The mobile belongs to someone else: offer the same choices instead of an error.
        customer.adoptPhoneOwner(err.conflict.customer)
        setConfirmingCustomer(true)
      } else {
        setError(err)
      }
    } finally {
      setSubmitting(false)
      // Stock may have changed either way (our order, or someone else's).
      loadProducts()
      setLowStockKey((k) => k + 1)
      window.dispatchEvent(new Event('store:refresh-notifications'))
    }
  }

  if (order) {
    return (
      <>
        <TaxInvoiceOffer order={order} />
        <Bill order={order} onNewOrder={resetForm} />
      </>
    )
  }

  const selectedIds = new Set(lines.map((l) => String(l.productId)).filter(Boolean))
  const filledLines = selectedIds.size

  return (
    <form onSubmit={handleSubmit} className="space-y-6" noValidate>
      <div className="min-w-0 space-y-6">
        {isAllStores ? (
          <Alert tone="warning">You are viewing all stores. Select a store in the header to create bills.</Alert>
        ) : (
          currentStore && (
            <p className="text-sm text-slate-500">
              Billing at <b className="font-medium text-slate-800">{currentStore.name}</b>
              {currentStore.gstin && <span className="text-slate-400"> · GSTIN {currentStore.gstin}</span>}
            </p>
          )
        )}
        {loadError && <Alert>{loadError}</Alert>}
        {error && <Alert>{error.message}</Alert>}

        <Card title="Customer" actions={<CustomerStatus status={customer.status} />}>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field
              label="Mobile no."
              error={fieldError('customer_phone') || customer.lookupError}
              hint="For the WhatsApp bill"
            >
              <input
                type="tel"
                inputMode="tel"
                className={inputClass}
                placeholder="e.g. 98765 43210"
                value={customer.phone}
                onChange={(e) => customer.change('phone', e.target.value)}
                onBlur={() => customer.lookup('phone')}
              />
            </Field>
            <Field label="Email" error={fieldError('customer_email')}>
              <input
                type="email"
                className={inputClass}
                placeholder="e.g. arun@example.com"
                value={customer.email}
                onChange={(e) => customer.change('email', e.target.value)}
                onBlur={() => customer.lookup('email')}
                required
              />
            </Field>
            <Field label="Name" error={fieldError('customer_name')}>
              <input
                className={inputClass}
                placeholder="auto-filled if customer exists"
                value={customer.name}
                onChange={(e) => customer.change('name', e.target.value)}
              />
            </Field>
          </div>
          {customer.status === 'modified' && customer.match && (
            <div className="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <UserRoundPen size={18} className="mt-0.5 shrink-0 text-amber-600" aria-hidden />
              <p>
                This {customer.match.by === 'phone' ? 'mobile number' : 'email'} belongs to{' '}
                <b>{customer.match.customer.name}</b> ({customer.match.by === 'phone' ? customer.match.customer.email : customer.match.customer.phone ?? 'no mobile'}).
                You changed {customer.changes.map((c) => c.label.toLowerCase()).join(' and ')}. You'll be asked whether to
                update their record when you generate the bill; billing won't be blocked.
              </p>
            </div>
          )}
        </Card>

        <BusinessCustomerSection
          b2b={b2b}
          setB2b={setB2b}
          match={customer.match}
          storeStateCode={currentStore?.state_code}
          setInterstate={setInterstate}
          fieldError={fieldError}
        />

        <Card
          padded={false}
          className="@container"
          title={
            <span className="flex items-center gap-2">
              Products
              {filledLines > 0 && <Badge tone="brand">{filledLines} item{filledLines === 1 ? '' : 's'}</Badge>}
            </span>
          }
          actions={
            <Button
              size="sm"
              icon={Plus}
              onClick={() => setLines((prev) => [...prev, newLine()])}
              disabled={lines.length >= products.length}
            >
              Add product
            </Button>
          }
        >
          {/* Wide card: one line per product. Narrow card (phone, or zoomed-in laptop): product + delete on top, qty / per unit / price below. */}
          <div className="hidden grid-cols-[1.5rem_minmax(0,1fr)_5.5rem_6rem_6.5rem_2.25rem] gap-3 border-y border-slate-100 bg-slate-50 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-500 @2xl:grid">
            <span>#</span>
            <span>Product</span>
            <span>Qty</span>
            <span className="text-right">Per unit</span>
            <span className="text-right leading-tight">
              Price
              <span className="block text-[10px] font-normal normal-case tracking-normal text-slate-400">qty × per unit</span>
            </span>
            <span />
          </div>
          <ul className="divide-y divide-slate-100 border-t border-slate-100 @2xl:border-t-0">
            {lines.length === 0 && (
              <li className="px-5 py-8 text-center text-sm text-slate-500">No products added. Click “Add product” to start the bill.</li>
            )}
            {lines.map((line, i) => {
              const product = productById.get(String(line.productId))
              const overStock = product && Number(line.quantity) > product.stock
              const rowError =
                fieldError(`items.${i}.product_id`) ||
                fieldError(`items.${i}.quantity`) ||
                (overStock ? `Only ${product.stock} in stock.` : null)
              return (
                <li
                  key={line.key}
                  className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 px-5 py-4 @2xl:grid-cols-[1.5rem_minmax(0,1fr)_5.5rem_6rem_6.5rem_2.25rem]"
                >
                  <span className="hidden pt-2.5 text-xs tabular-nums text-slate-400 @2xl:block">{i + 1}</span>

                  <div className="min-w-0">
                    <ProductPicker
                      products={products}
                      value={line.productId}
                      onChange={(productId) => updateLine(line.key, { productId })}
                      describe={(p) => (p.stock === 0 ? 'out of stock' : `${p.stock} left`)}
                      isDisabled={(p) => p.stock === 0 || (selectedIds.has(String(p.id)) && String(p.id) !== String(line.productId))}
                      ariaLabel={`Product for row ${i + 1}`}
                      invalid={Boolean(rowError)}
                    />
                    {rowError && <p className="mt-1 text-xs text-red-600">{rowError}</p>}
                  </div>

                  <button
                    type="button"
                    onClick={() => removeLine(line.key)}
                    className="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600 @2xl:order-last"
                    aria-label={`Delete row ${i + 1}`}
                    title="Delete"
                  >
                    <Trash2 size={18} />
                  </button>

                  <div className="col-span-2 grid grid-cols-3 items-start gap-3 @2xl:contents">
                    <label className="block">
                      <span className="mb-1 block text-[11px] font-medium uppercase text-slate-500 @2xl:hidden">Qty</span>
                      <input
                        type="number"
                        min="1"
                        max={product?.stock}
                        className={`${inputClass} ${overStock ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ''}`}
                        value={line.quantity}
                        onChange={(e) => updateLine(line.key, { quantity: e.target.value })}
                        aria-label={`Quantity for row ${i + 1}`}
                      />
                    </label>
                    <div className="text-right text-sm tabular-nums text-slate-600 @2xl:pt-2">
                      <span className="mb-1 block text-[11px] font-medium uppercase text-slate-500 @2xl:hidden">Per unit</span>
                      {formatINR(product?.price ?? 0)}
                      {product && Number(product.tax_percent) > 0 && (
                        <span className="block text-xs text-slate-400">+{Number(product.tax_percent)}% GST</span>
                      )}
                    </div>
                    <div className="text-right text-sm font-semibold tabular-nums text-slate-900 @2xl:pt-2">
                      <span className="mb-1 block text-[11px] font-medium uppercase text-slate-500 @2xl:hidden">Price</span>
                      {formatINR(product ? preview.rows[i].subtotal : 0, { cents: true })}
                    </div>
                  </div>
                </li>
              )
            })}
          </ul>
          {fieldError('items') && <p className="px-5 pb-4 text-xs text-red-600">{fieldError('items')}</p>}
        </Card>
      </div>

      {/* Bottom row: low stock on the left, summary + Generate on the right (summary first on phones). */}
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px]">
        <div className="lg:col-start-2 lg:row-start-1">
          <Card title="Bill summary">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between text-slate-600">
                <dt>Subtotal</dt>
                <dd className="tabular-nums">{formatINR(preview.subtotal, { cents: true })}</dd>
              </div>
              {interstate ? (
                <div className="flex justify-between text-slate-600">
                  <dt>IGST</dt>
                  <dd className="tabular-nums">{formatINR(preview.igst, { cents: true })}</dd>
                </div>
              ) : (
                <>
                  <div className="flex justify-between text-slate-600">
                    <dt>CGST</dt>
                    <dd className="tabular-nums">{formatINR(preview.cgst, { cents: true })}</dd>
                  </div>
                  <div className="flex justify-between text-slate-600">
                    <dt>SGST</dt>
                    <dd className="tabular-nums">{formatINR(preview.sgst, { cents: true })}</dd>
                  </div>
                </>
              )}
            </dl>
            <label className="mt-3 flex items-center gap-2 text-xs text-slate-600">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300 accent-brand-600"
                checked={interstate}
                onChange={(e) => setInterstate(e.target.checked)}
              />
              Customer outside {STORE_STATE} (charge IGST)
            </label>
            <div className="mt-4 flex items-baseline justify-between rounded-xl bg-slate-900 px-4 py-3 text-white">
              <span className="text-sm text-slate-300">Grand total</span>
              <span className="text-2xl font-semibold tabular-nums">{formatINR(preview.total, { cents: true })}</span>
            </div>

            <div className="mt-5">
              <span className="mb-1.5 block text-xs font-medium text-slate-600">Payment</span>
              <div className="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:grid-cols-4" role="radiogroup" aria-label="Payment mode">
                {PAYMENT_MODES.map((m) => (
                  <button
                    key={m.value}
                    type="button"
                    role="radio"
                    aria-checked={paymentMode === m.value}
                    onClick={() => {
                      setPaymentMode(m.value)
                      if (m.value === 'credit') setAmountGiven('')
                    }}
                    className={`rounded-md px-2 py-1.5 font-medium transition ${paymentMode === m.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                  >
                    {m.value === 'credit' ? 'Credit' : m.label}
                  </button>
                ))}
              </div>
              {fieldError('payment_mode') && <p className="mt-1 text-xs text-red-600">{fieldError('payment_mode')}</p>}
              {isCredit && (
                <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                  Pay later: the full amount is added to the customer's account balance.
                </p>
              )}
            </div>

            <div className={isCredit ? 'hidden' : 'mt-5'}>
              <Field label="Amount given by customer" hint="Optional: shows the change to return" error={fieldError('amount_paid')}>
                <div className="relative">
                  <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">₹</span>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    className={`${inputClass} pl-7`}
                    placeholder="0.00"
                    value={amountGiven}
                    onChange={(e) => setAmountGiven(e.target.value)}
                  />
                </div>
              </Field>
              {balanceCents !== null && <BalanceLine balanceCents={balanceCents} />}
            </div>

            <Button
              type="submit"
              variant="success"
              size="lg"
              icon={ReceiptText}
              loading={submitting}
              disabled={products.length === 0 || filledLines === 0 || isAllStores}
              className="mt-5 w-full"
            >
              Generate bill
            </Button>
            <p className="mt-2 text-center text-xs text-slate-500">
              Confirmation goes by email{customer.phone.trim() ? ' and WhatsApp' : ''}.
            </p>
          </Card>
        </div>

        {can('products.view') && (
          <div className="lg:col-start-1 lg:row-start-1">
            <LowStockAlert refreshKey={lowStockKey} />
          </div>
        )}
      </div>

      {confirmingCustomer && customer.match && (
        <CustomerUpdateDialog
          match={customer.match}
          changes={customer.changes}
          form={{ email: customer.email.trim(), name: customer.name.trim() }}
          onChoose={submitOrder}
          onCancel={() => setConfirmingCustomer(false)}
        />
      )}
    </form>
  )
}

function CustomerStatus({ status }) {
  const states = {
    checking: { text: 'Looking up…', className: 'text-slate-500', spinner: true },
    found: { text: '✓ Returning customer: details filled in', className: 'text-green-700' },
    modified: { text: 'Returning customer: details changed', className: 'text-amber-700' },
    new: { text: 'New customer: enter name and email', className: 'text-brand-700' },
  }
  const state = states[status]
  if (!state) return <span className="text-xs text-slate-400">Enter mobile or email to find a customer</span>
  return (
    <span className={`flex items-center gap-1.5 text-xs font-medium ${state.className}`}>
      {state.spinner && <Spinner />} {state.text}
    </span>
  )
}

function BalanceLine({ balanceCents }) {
  if (balanceCents < 0) {
    return (
      <div className="mt-3 flex justify-between rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
        <span>Short by</span>
        <span className="tabular-nums">{formatINR(-balanceCents, { cents: true })}</span>
      </div>
    )
  }
  const { parts, paise } = changeBreakdown(balanceCents)
  const breakdown = [...parts.map((p) => `${p.count}×₹${p.value}`), ...(paise ? [`${paise} paise`] : [])].join(' + ')
  return (
    <div className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
      <div className="flex justify-between font-semibold">
        <span>Balance to return</span>
        <span className="tabular-nums">{formatINR(balanceCents, { cents: true })}</span>
      </div>
      {breakdown && <p className="mt-0.5 text-xs text-emerald-700">{breakdown}</p>}
    </div>
  )
}
