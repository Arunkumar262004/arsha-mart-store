import { useState } from 'react'
import { FileSpreadsheet, FileText, QrCode } from 'lucide-react'
import { downloadReport } from '../../api'
import { parseApiError } from '../../api/client'
import { useToast } from '../Toast'
import { Button } from '../ui'
import ReportQrModal from './ReportQrModal'

/** "PDF" and "Excel" buttons that download the whole report with the current filters. */
export default function ReportDownloads({ report, query, disabled }) {
  const toast = useToast()
  const [busy, setBusy] = useState(null)
  const [showQr, setShowQr] = useState(false)

  async function download(format) {
    setBusy(format)
    try {
      const { blob, filename } = await downloadReport(report, query, format)
      const url = URL.createObjectURL(blob)
      const a = Object.assign(document.createElement('a'), { href: url, download: filename })
      document.body.appendChild(a)
      a.click()
      a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (e) {
      // Errors arrive as a Blob because of responseType: 'blob'.
      const body = e?.response?.data
      if (body instanceof Blob) {
        try {
          e.response.data = JSON.parse(await body.text())
        } catch {
          /* not JSON */
        }
      }
      toast(parseApiError(e).message, 'error')
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <Button variant="secondary" icon={FileText} loading={busy === 'pdf'} disabled={disabled || busy !== null} onClick={() => download('pdf')}>
        PDF
      </Button>
      <Button variant="secondary" icon={FileSpreadsheet} loading={busy === 'xlsx'} disabled={disabled || busy !== null} onClick={() => download('xlsx')}>
        Excel
      </Button>
      <Button variant="secondary" icon={QrCode} disabled={disabled} onClick={() => setShowQr(true)} title="Scan to download on your phone">
        QR
      </Button>
      {showQr && <ReportQrModal report={report} query={query} onClose={() => setShowQr(false)} />}
    </>
  )
}
