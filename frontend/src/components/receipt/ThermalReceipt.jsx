import { QRCodeSVG } from 'qrcode.react'
import { toCents } from '../../lib/money'
import './receipt.css'

const STORE = {
  name: import.meta.env.VITE_STORE_NAME ?? 'Arsha Mart',
  address: import.meta.env.VITE_STORE_ADDRESS ?? '',
  phone: import.meta.env.VITE_STORE_PHONE ?? '',
  gstin: import.meta.env.VITE_STORE_GSTIN ?? '',
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

  return (
    <div ref={ref} className={`rcpt ${paper === '58' ? 'rcpt--58' : ''}`}>
      <div className="rcpt-center">
        <div className="rcpt-store">{STORE.name}</div>
        {STORE.address && <div className="rcpt-muted">{STORE.address}</div>}
        {STORE.phone && <div className="rcpt-muted">Ph: {STORE.phone}</div>}
        {STORE.gstin && <div className="rcpt-muted">GSTIN: {STORE.gstin}</div>}
      </div>

      <hr className="rcpt-rule" />
      <div className="rcpt-title">TAX INVOICE</div>

      <div className="rcpt-row"><span>Bill No</span><span className="rcpt-bold">{order.order_number}</span></div>
      <div className="rcpt-row">
        <span>{created.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' })}</span>
        <span>{created.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}</span>
      </div>
      {order.cashier && <div className="rcpt-row"><span>Cashier</span><span>{order.cashier}</span></div>}
      <div className="rcpt-row"><span>Customer</span><span>{order.customer.name}</span></div>
      {order.customer.phone && <div className="rcpt-row"><span>Mobile</span><span>{order.customer.phone}</span></div>}

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

      {order.amount_paid !== null && order.amount_paid !== undefined && (
        <>
          <div className="rcpt-row"><span>Cash</span><span>{money(order.amount_paid)}</span></div>
          <div className="rcpt-row rcpt-bold"><span>Change</span><span>{money(order.change_due)}</span></div>
          <hr className="rcpt-rule" />
        </>
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
        <QRCodeSVG value={order.order_number} size={paper === '58' ? 64 : 80} level="M" />
      </div>
      <div className="rcpt-center rcpt-bold">Thank you! Visit again</div>
      <div className="rcpt-center rcpt-small">Goods once sold can't be exchange or Return.</div>
    </div>
  )
}
