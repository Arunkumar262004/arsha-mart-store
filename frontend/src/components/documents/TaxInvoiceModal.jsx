import { useEffect, useState } from 'react'
import { Download, Printer } from 'lucide-react'
import { downloadInvoicePdf, getInvoice } from '../../api/documents'
import { parseApiError } from '../../api/client'
import { Alert, Button, Modal, Spinner } from '../ui'
import { saveBlob, usePrintA4 } from './printing'
import TaxInvoiceSheet from './TaxInvoiceSheet'

/** Loads a bill's GST invoice, shows it as an A4 sheet, prints or downloads the PDF. */
export default function TaxInvoiceModal({ orderId, onClose }) {
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)
  const [downloading, setDownloading] = useState(false)
  const { contentRef, print } = usePrintA4(invoice?.invoice_number)

  useEffect(() => {
    let cancelled = false
    getInvoice(orderId)
      .then((data) => !cancelled && setInvoice(data))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [orderId])

  async function download() {
    setDownloading(true)
    try {
      saveBlob(await downloadInvoicePdf(orderId))
    } catch (e) {
      setError(parseApiError(e).message)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <Modal
      open
      size="xl"
      title={invoice ? `${invoice.title === 'TAX INVOICE' ? 'Tax invoice' : 'Bill of supply'} · ${invoice.invoice_number}` : 'Invoice'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Close</Button>
          <Button variant="secondary" icon={Download} loading={downloading} disabled={!invoice} onClick={download}>
            Download PDF
          </Button>
          <Button icon={Printer} disabled={!invoice} onClick={print}>Print</Button>
        </>
      }
    >
      {error && <Alert>{error}</Alert>}
      {!invoice && !error && (
        <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>
      )}
      {invoice && (
        <div className="overflow-x-auto rounded-xl bg-slate-200 p-3">
          <div className="min-w-[760px] shadow-lg">
            <TaxInvoiceSheet ref={contentRef} invoice={invoice} />
          </div>
        </div>
      )}
    </Modal>
  )
}
