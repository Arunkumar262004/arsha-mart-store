import { QRCodeSVG } from 'qrcode.react'
import { toCents } from '../../lib/money'
import { billNumber, paymentLabel } from './paymentModes'
import './receipt.css'

// Fallback header for bills without store details (older API responses).
const DEFAULT_STORE = {
  name: import.meta.env.VITE_STORE_NAME ?? 'Inofex Retail',
  address: import.meta.env.VITE_STORE_ADDRESS ?? '',
  phone: import.meta.env.VITE_STORE_PHONE ?? '',
  gstin: import.meta.env.VITE_STORE_GSTIN ?? '',
}

/**
 * Receipt header: the seller the API works out for the bill's store (company
 * legal details, or the store's own address / GSTIN), falling back to the
 * VITE_STORE_* values for old responses.
 */
function storeHeader(store) {
  const seller = store?.seller ?? store
  if (!seller?.name) return DEFAULT_STORE
  const place = [seller.city, seller.state && seller.pincode ? `${seller.state} - ${seller.pincode}` : seller.state || seller.pincode]
  return {
    name: seller.name,
    branch: seller.branch ?? '',
    address: [seller.address, ...place].filter(Boolean).join(', '),
    phone: seller.phone ?? '',
    gstin: seller.gstin ?? '',
  }
}

const amt = (cents) => (cents / 100).toFixed(2)
const money = (value) => Number(value ?? 0).toFixed(2)

/**
 * GST summary per rate, from the CGST / SGST / IGST amounts the server
 * stored on each line (CGST + SGST inside the state, IGST outside it).
 */
function gstSummary(items) {
  const byRate = new Map()
  for (const item of items) {
    const rate = Number(item.tax_percent)
    const row = byRate.get(rate) ?? { rate, taxable: 0, cgst: 0, sgst: 0, igst: 0 }
    row.taxable += toCents(item.line_subtotal)
    row.cgst += toCents(item.cgst_amount)
    row.sgst += toCents(item.sgst_amount)
    row.igst += toCents(item.igst_amount)
    byRate.set(rate, row)
  }
  return [...byRate.values()].sort((a, b) => a.rate - b.rate)
}

/**
 * A department-store style till receipt for an 80mm or 58mm thermal roll.
 * Pass `ref` so react-to-print can print exactly this element.
 */
export default function ThermalReceipt({ order, paper = '80', ref }) {
  const created = new Date(order.created_at)
  const gst = gstSummary(order.items)
  const interstate = Boolean(order.is_interstate)
  const units = order.items.reduce((s, i) => s + i.quantity, 0)
  const store = storeHeader(order.store)
  const isCredit = order.payment_mode === 'credit'

  return (
    <div ref={ref} className={`rcpt ${paper === '58' ? 'rcpt--58' : ''}`}>
      <div className="rcpt-center">
        <div className="rcpt-store">{store.name}</div>
        {store.branch && <div className="rcpt-muted">{store.branch}</div>}
        {store.address && <div className="rcpt-muted">{store.address}</div>}
        {store.phone && <div className="rcpt-muted">Ph: {store.phone}</div>}
        {store.gstin && <div className="rcpt-muted">GSTIN: {store.gstin}</div>}
      </div>

      <hr className="rcpt-rule" />
      <div className="rcpt-title">TAX INVOICE</div>

      <div className="rcpt-row"><span>Invoice No</span><span className="rcpt-bold">{billNumber(order)}</span></div>
      {order.invoice_number && (
        <div className="rcpt-row rcpt-small"><span>Order No</span><span>{order.order_number}</span></div>
      )}
      <div className="rcpt-row">
        <span>{created.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' })}</span>
        <span>{created.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}</span>
      </div>
      {order.cashier && <div className="rcpt-row"><span>Cashier</span><span>{order.cashier}</span></div>}
      <div className="rcpt-row"><span>Customer</span><span>{order.customer.name}</span></div>
      {order.customer.phone && <div className="rcpt-row"><span>Mobile</span><span>{order.customer.phone}</span></div>}
      {order.customer_gstin && <div className="rcpt-row"><span>GSTIN</span><span>{order.customer_gstin}</span></div>}
      {order.place_of_supply && <div className="rcpt-row"><span>Place of supply</span><span>{order.place_of_supply}</span></div>}
      <div className="rcpt-row"><span>Payment</span><span className="rcpt-bold">{paymentLabel(order.payment_mode)}</span></div>

      <hr className="rcpt-rule" />
      <div className="rcpt-row rcpt-bold rcpt-small"><span>ITEM / QTY x RATE</span><span>AMOUNT</span></div>
      <hr className="rcpt-rule" />

      {order.items.map((item) => (
        <div key={item.product_id} className="rcpt-item">
          <div className="rcpt-item-name">{item.product_name}</div>
          <div className="rcpt-row">
            <span>
              {item.quantity} x {money(item.unit_price)}
              <span className="rcpt-small">  GST {Number(item.tax_percent)}%</span>
            </span>
            <span>{money(item.line_subtotal)}</span>
          </div>
        </div>
      ))}

      <hr className="rcpt-rule" />
      <div className="rcpt-row rcpt-small"><span>Items: {order.items.length}</span><span>Qty: {units}</span></div>
      <div className="rcpt-row"><span>Subtotal</span><span>{money(order.subtotal)}</span></div>
      {interstate ? (
        <div className="rcpt-row"><span>IGST</span><span>{money(order.igst_amount)}</span></div>
      ) : (
        <>
          <div className="rcpt-row"><span>CGST</span><span>{money(order.cgst_amount)}</span></div>
          <div className="rcpt-row"><span>SGST</span><span>{money(order.sgst_amount)}</span></div>
        </>
      )}

      <hr className="rcpt-rule rcpt-rule--double" />
      <div className="rcpt-row rcpt-total"><span>TOTAL</span><span>₹{money(order.grand_total)}</span></div>
      <hr className="rcpt-rule rcpt-rule--double" />

      {isCredit ? (
        <>
          <div className="rcpt-row rcpt-bold"><span>Balance due</span><span>{money(order.grand_total)}</span></div>
          <hr className="rcpt-rule" />
        </>
      ) : (
        order.amount_paid !== null && order.amount_paid !== undefined && (
          <>
            <div className="rcpt-row"><span>{paymentLabel(order.payment_mode)} received</span><span>{money(order.amount_paid)}</span></div>
            <div className="rcpt-row rcpt-bold"><span>Change</span><span>{money(order.change_due)}</span></div>
            <hr className="rcpt-rule" />
          </>
        )
      )}

      <div className="rcpt-bold rcpt-small">GST SUMMARY</div>
      <table className="rcpt-table">
        <thead>
          <tr>
            <th>Rate</th>
            <th>Taxable</th>
            {interstate ? (
              <th>IGST</th>
            ) : (
              <>
                <th>CGST</th>
                <th>SGST</th>
              </>
            )}
          </tr>
        </thead>
        <tbody>
          {gst.map((r) => (
            <tr key={r.rate}>
              <td>{r.rate}%</td>
              <td>{amt(r.taxable)}</td>
              {interstate ? (
                <td>{amt(r.igst)}</td>
              ) : (
                <>
                  <td>{amt(r.cgst)}</td>
                  <td>{amt(r.sgst)}</td>
                </>
              )}
            </tr>
          ))}
        </tbody>
      </table>

      <hr className="rcpt-rule" />
      <div className="rcpt-qr">
        <QRCodeSVG value={billNumber(order)}size={paper === '58' ? 64 : 80} level="M" />
      </div>
      <div className="rcpt-center rcpt-bold">Thank you! Visit again</div>
      <div className="rcpt-center rcpt-small">Goods once sold can't be exchange or Return.</div>
    </div>
  )
}
